<?php
declare(strict_types=1);

require_once __DIR__ . '/src/db.php';

$pdo = db();
$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$tokenHash = preg_match('/^[a-f0-9]{64}$/', $token) === 1 ? hash('sha256', $token) : '';

function loadSignatureRequest(PDO $pdo, string $tokenHash): array|false
{
    if ($tokenHash === '') return false;
    $stmt = $pdo->prepare('SELECT sr.*, f.original_name, f.stored_path, f.mime_type, f.size_bytes, r.report_month, r.report_year, r.provider_name, u.first_names, u.last_names, u.full_name
                           FROM signature_requests sr
                           INNER JOIN monthly_report_files f ON f.id = sr.report_file_id
                           INNER JOIN monthly_reports r ON r.id = sr.report_id
                           INNER JOIN system_users u ON u.id = sr.honorario_user_id
                           WHERE sr.token_hash = :token LIMIT 1');
    $stmt->execute(['token' => $tokenHash]);
    return $stmt->fetch();
}

$request = loadSignatureRequest($pdo, $tokenHash);
if ($request !== false && (string) $request['status'] === 'PENDIENTE' && strtotime((string) $request['expires_at']) < time()) {
    $pdo->prepare("UPDATE signature_requests SET status = 'EXPIRADO' WHERE id = :id")->execute(['id' => $request['id']]);
    $request['status'] = 'EXPIRADO';
}

if (isset($_GET['document'])) {
    if ($request === false || !in_array((string) $request['status'], ['PENDIENTE', 'FIRMADO'], true)) {
        http_response_code(404);
        exit('Documento no disponible.');
    }
    $storedPath = (string) $request['stored_path'];
    $resolved = realpath(__DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $storedPath));
    $uploadsRoot = realpath(__DIR__ . '/uploads');
    if ($resolved === false || $uploadsRoot === false || !str_starts_with($resolved, $uploadsRoot . DIRECTORY_SEPARATOR) || !is_file($resolved)) {
        http_response_code(404);
        exit('Documento no encontrado.');
    }
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $request['original_name']) ?: 'informe.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('Content-Length: ' . filesize($resolved));
    header('X-Content-Type-Options: nosniff');
    readfile($resolved);
    exit;
}

$error = '';
$signed = false;
$signatureFullPath = '';
$signedPdfFullPath = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($request === false || (string) $request['status'] !== 'PENDIENTE') {
            throw new RuntimeException('Este enlace ya no está disponible para firmar.');
        }
        if (!isset($_POST['accept_signature'])) {
            throw new RuntimeException('Debes aceptar la declaración de firma.');
        }
        $dataUrl = (string) ($_POST['signature_data'] ?? '');
        if (!str_starts_with($dataUrl, 'data:image/png;base64,')) {
            throw new RuntimeException('No se recibió una firma válida.');
        }
        $binary = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')), true);
        if ($binary === false || strlen($binary) < 200 || strlen($binary) > 3 * 1024 * 1024) {
            throw new RuntimeException('La imagen de firma no es válida o es demasiado grande.');
        }
        $imageInfo = getimagesizefromstring($binary);
        if ($imageInfo === false || ($imageInfo['mime'] ?? '') !== 'image/png') {
            throw new RuntimeException('El formato de la firma no es válido.');
        }

        $dir = __DIR__ . '/uploads/signed_signatures';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('No fue posible guardar la firma.');
        }
        $fileName = 'firma_informe_' . (int) $request['report_id'] . '_' . date('YmdHis') . '.png';
        if (file_put_contents($dir . '/' . $fileName, $binary, LOCK_EX) === false) {
            throw new RuntimeException('No fue posible guardar la firma.');
        }
        $relativePath = 'uploads/signed_signatures/' . $fileName;
        $signatureFullPath = $dir . '/' . $fileName;

        $signedUpload = $_FILES['signed_pdf'] ?? [];
        if (!isset($signedUpload['error']) || (int) $signedUpload['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('No se recibió el PDF con la firma estampada.');
        }
        $signedPdfSize = (int) ($signedUpload['size'] ?? 0);
        if ($signedPdfSize < 100 || $signedPdfSize > 30 * 1024 * 1024) {
            throw new RuntimeException('El PDF firmado no es válido o supera el máximo de 30 MB.');
        }
        $signedTmp = (string) ($signedUpload['tmp_name'] ?? '');
        $header = file_get_contents($signedTmp, false, null, 0, 5);
        if ($header === false || $header !== '%PDF-') {
            throw new RuntimeException('El archivo firmado no tiene un formato PDF válido.');
        }
        $signedDir = __DIR__ . '/uploads/reports/signed';
        if (!is_dir($signedDir) && !mkdir($signedDir, 0775, true) && !is_dir($signedDir)) {
            throw new RuntimeException('No fue posible crear la carpeta de documentos firmados.');
        }
        $signedPdfName = 'informe_firmado_' . (int) $request['report_id'] . '_' . date('YmdHis') . '.pdf';
        $signedPdfFullPath = $signedDir . '/' . $signedPdfName;
        if (!move_uploaded_file($signedTmp, $signedPdfFullPath)) {
            throw new RuntimeException('No fue posible guardar el PDF firmado.');
        }
        $signedPdfRelativePath = 'uploads/reports/signed/' . $signedPdfName;

        $pdo->beginTransaction();
        $update = $pdo->prepare("UPDATE signature_requests SET status = 'FIRMADO', signed_at = NOW(), signed_signature_path = :path, signer_ip = :ip, signer_user_agent = :agent WHERE id = :id AND status = 'PENDIENTE'");
        $update->execute([
            'path' => $relativePath,
            'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            'id' => $request['id'],
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Este enlace ya fue utilizado.');
        }
        $signedOriginalName = 'firmado_' . preg_replace('/^firmado_/', '', (string) $request['original_name']);
        $pdo->prepare("INSERT IGNORE INTO monthly_report_file_history (report_id,source_file_id,stage,original_name,stored_path,mime_type,size_bytes)
                       VALUES (:report,:file,'ORIGINAL',:name,:path,:mime,:size)")
            ->execute(['report'=>$request['report_id'],'file'=>$request['report_file_id'],'name'=>$request['original_name'],'path'=>$request['stored_path'],
                       'mime'=>$request['mime_type'],'size'=>$request['size_bytes']]);
        $pdo->prepare('UPDATE monthly_report_files SET original_name = :name, stored_path = :path, mime_type = :mime, size_bytes = :size WHERE id = :id')
            ->execute(['name' => $signedOriginalName, 'path' => $signedPdfRelativePath, 'mime' => 'application/pdf', 'size' => filesize($signedPdfFullPath), 'id' => $request['report_file_id']]);
        $pdo->prepare("UPDATE monthly_reports SET status = 'ENVIADO', submitted_at = NOW() WHERE id = :id")->execute(['id' => $request['report_id']]);
        $pdo->commit();
        $signed = true;
        $request['status'] = 'FIRMADO';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($signedPdfFullPath !== '' && is_file($signedPdfFullPath)) unlink($signedPdfFullPath);
        if ($signatureFullPath !== '' && is_file($signatureFullPath)) unlink($signatureFullPath);
        $error = $e->getMessage();
    }
} elseif ($request !== false && (string) $request['status'] === 'PENDIENTE') {
    $pdo->prepare('UPDATE signature_requests SET opened_at = COALESCE(opened_at, NOW()) WHERE id = :id')->execute(['id' => $request['id']]);
}

$valid = $request !== false && (string) $request['status'] === 'PENDIENTE';
$personName = $request !== false ? trim((string) ($request['first_names'] ?? '') . ' ' . (string) ($request['last_names'] ?? '')) : '';
if ($personName === '' && $request !== false) $personName = (string) $request['full_name'];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Firma de informe | Sistema Honorarios</title>
<style>
:root{--brand:#0b7285;--dark:#15364d;--muted:#60778b;--line:#d8e5ef;--bg:#eef4f8;--danger:#b42318;--ok:#18794e}*{box-sizing:border-box}body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:var(--bg);color:var(--dark)}
.header{background:#fff;border-bottom:1px solid var(--line);padding:14px 20px;display:flex;justify-content:space-between;align-items:center;gap:12px}.header strong{font-size:1.05rem}.secure{font-size:.8rem;color:var(--ok)}
.page{max-width:1180px;margin:20px auto;padding:0 16px}.intro{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px;margin-bottom:14px}.intro h1{font-size:1.25rem;margin:0 0 7px}.intro p{margin:4px 0;color:var(--muted)}
.grid{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(320px,.8fr);gap:14px}.card{background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden}.card h2{font-size:.98rem;margin:0;padding:13px 15px;border-bottom:1px solid var(--line)}iframe{width:100%;height:68vh;border:0;background:#e5ebf0}
.sign-body{padding:15px}.canvas-wrap{border:2px dashed #aabfce;border-radius:10px;background:#fff;touch-action:none;overflow:hidden}canvas{display:block;width:100%;height:230px;touch-action:none}.actions{display:flex;gap:8px;margin-top:10px}.btn{border:0;border-radius:9px;padding:11px 15px;background:var(--brand);color:#fff;font-weight:750;cursor:pointer}.btn.secondary{background:#edf3f7;color:var(--dark)}.consent{display:flex;align-items:flex-start;gap:9px;margin:15px 0;font-size:.9rem;line-height:1.35}.consent input{margin-top:3px}.notice{padding:15px;border-radius:10px;margin-bottom:14px}.error{background:#fff0ef;color:var(--danger);border:1px solid #f1c1bc}.success{background:#edf9f2;color:var(--ok);border:1px solid #bde4cb}.invalid{max-width:650px;margin:60px auto;background:#fff;padding:28px;border-radius:14px;border:1px solid var(--line);text-align:center}
@media(max-width:800px){.grid{grid-template-columns:1fr}iframe{height:48vh}.page{margin:12px auto}canvas{height:210px}.header{padding:12px 14px}}
</style>
</head>
<body>
<header class="header"><strong>Sistema de Honorarios</strong><span class="secure">Conexión segura · Enlace personal</span></header>
<main class="page">
<?php if ($signed || ($request !== false && (string) $request['status'] === 'FIRMADO')): ?>
<div class="invalid"><div class="notice success"><strong>Informe firmado y enviado correctamente.</strong></div><p>El informe ha sido firmado y se ha enviado a revisión y firma del director. Ya puede cerrar esta ventana.</p><button class="btn" type="button" id="closeSignedWindow">Cerrar Ventana</button><p id="closeWindowHelp" class="muted" style="display:none">Si el navegador no cierra la pestaña automáticamente, puede cerrarla manualmente.</p></div>
<?php elseif (!$valid): ?>
<div class="invalid"><h1>Enlace no disponible</h1><p>El enlace es inválido, venció o fue anulado. Solicita uno nuevo desde el sistema.</p></div>
<?php else: ?>
<section class="intro"><h1>Revisa y firma tu informe mensual</h1><p><strong><?php echo htmlspecialchars($personName, ENT_QUOTES, 'UTF-8'); ?></strong> · Informe <?php echo (int) $request['report_month']; ?>/<?php echo (int) $request['report_year']; ?></p><p>El enlace vence el <?php echo htmlspecialchars(date('d-m-Y H:i', strtotime((string) $request['expires_at'])), ENT_QUOTES, 'UTF-8'); ?>.</p></section>
<?php if ($error !== ''): ?><div class="notice error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<div class="grid">
<section class="card"><h2>Documento que vas a firmar</h2><iframe src="firmar.php?token=<?php echo rawurlencode($token); ?>&amp;document=1" title="Informe PDF"></iframe></section>
<section class="card"><h2>Firma con tu dedo o puntero</h2><div class="sign-body">
<p style="margin-top:0;color:var(--muted);font-size:.9rem">Dibuja tu firma dentro del recuadro.</p>
<form method="post" id="signatureForm"><input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="signature_data" id="signatureData">
<div class="canvas-wrap"><canvas id="signatureCanvas"></canvas></div><div class="actions"><button type="button" class="btn secondary" id="clearSignature">Borrar firma</button></div>
<label class="consent"><input type="checkbox" name="accept_signature" required><span>Declaro que revisé el documento y acepto utilizar esta firma electrónica para firmar y enviar el informe.</span></label>
<button class="btn" type="submit">Confirmar firma y enviar</button></form>
</div></section></div>
<?php endif; ?>
</main>
<?php if ($valid): ?><script src="assets/vendor/pdf-lib.min.js"></script><script>
const canvas=document.getElementById('signatureCanvas'),ctx=canvas.getContext('2d'),form=document.getElementById('signatureForm'),data=document.getElementById('signatureData'),submitButton=form.querySelector('button[type="submit"]'),signerName=<?php echo json_encode($personName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;let drawing=false,hasInk=false,submitting=false;
function setup(){const r=canvas.getBoundingClientRect(),d=Math.max(window.devicePixelRatio||1,1);canvas.width=Math.round(r.width*d);canvas.height=Math.round(r.height*d);ctx.setTransform(d,0,0,d,0,0);ctx.clearRect(0,0,r.width,r.height);ctx.strokeStyle='#17324a';ctx.lineWidth=2.5;ctx.lineCap='round';ctx.lineJoin='round'}
function point(e){const r=canvas.getBoundingClientRect();return{x:e.clientX-r.left,y:e.clientY-r.top}}
canvas.addEventListener('pointerdown',e=>{drawing=true;hasInk=true;canvas.setPointerCapture(e.pointerId);const p=point(e);ctx.beginPath();ctx.moveTo(p.x,p.y)});canvas.addEventListener('pointermove',e=>{if(!drawing)return;const p=point(e);ctx.lineTo(p.x,p.y);ctx.stroke()});['pointerup','pointercancel','pointerleave'].forEach(n=>canvas.addEventListener(n,()=>drawing=false));
document.getElementById('clearSignature').addEventListener('click',()=>{setup();hasInk=false});
form.addEventListener('submit',async e=>{
    e.preventDefault();
    if(submitting)return;
    if(!hasInk){alert('Dibuja tu firma antes de continuar.');return}
    if(typeof PDFLib==='undefined'){alert('No fue posible cargar el componente de firma PDF. Recarga la página.');return}
    submitting=true;submitButton.disabled=true;submitButton.textContent='Estampando firma...';
    try{
        const signatureUrl=canvas.toDataURL('image/png');data.value=signatureUrl;
        const documentUrl=document.querySelector('iframe').src;
        const sourceResponse=await fetch(documentUrl,{credentials:'same-origin'});
        if(!sourceResponse.ok)throw new Error('No fue posible cargar el informe.');
        const pdfDoc=await PDFLib.PDFDocument.load(await sourceResponse.arrayBuffer());
        const signatureImage=await pdfDoc.embedPng(signatureUrl);
        const pages=pdfDoc.getPages(),page=pages[pages.length-1],size=page.getSize();
        const signatureOffsetLeft=85,signatureShiftRight=56.7,baseBoxX=18,safeLeftMargin=12;
        const boxWidth=Math.min(306,size.width*.58),signatureY=90;
        const scaled=signatureImage.scaleToFit(boxWidth,79.2);
        const boxX=Math.min(
            size.width-boxWidth-12,
            Math.max(baseBoxX-signatureOffsetLeft,safeLeftMargin-(boxWidth-scaled.width)/2)+signatureShiftRight
        );
        page.drawImage(signatureImage,{x:boxX+(boxWidth-scaled.width)/2,y:signatureY,width:scaled.width,height:scaled.height});
        const font=await pdfDoc.embedFont(PDFLib.StandardFonts.Helvetica),textColor=PDFLib.rgb(.22,.28,.35);
        const roleText='Prestador(a) de Servicio',roleSize=8.7;
        const roleX=boxX+(boxWidth-font.widthOfTextAtSize(roleText,roleSize))/2;
        page.drawText(roleText,{x:roleX,y:72,size:roleSize,font,color:textColor});
        let nameSize=8.7;
        while(nameSize>6&&font.widthOfTextAtSize(signerName,nameSize)>boxWidth)nameSize-=.5;
        const nameX=boxX+(boxWidth-font.widthOfTextAtSize(signerName,nameSize))/2;
        page.drawText(signerName,{x:nameX,y:58,size:nameSize,font,color:textColor});
        const signedBytes=await pdfDoc.save();
        const formData=new FormData(form);formData.set('signature_data',signatureUrl);formData.append('signed_pdf',new Blob([signedBytes],{type:'application/pdf'}),'informe_firmado.pdf');
        submitButton.textContent='Enviando informe...';
        const response=await fetch(window.location.href,{method:'POST',body:formData,credentials:'same-origin'});
        const responseHtml=await response.text();document.open();document.write(responseHtml);document.close();
    }catch(error){submitting=false;submitButton.disabled=false;submitButton.textContent='Confirmar firma y enviar';alert(error.message||'No fue posible firmar el informe.');}
});setup();
</script><?php endif; ?>
<script>
const closeSignedWindow = document.getElementById('closeSignedWindow');
if (closeSignedWindow) {
    closeSignedWindow.addEventListener('click', function () {
        window.open('', '_self');
        window.close();
        setTimeout(function () {
            const help = document.getElementById('closeWindowHelp');
            if (help) help.style.display = 'block';
        }, 400);
    });
}
</script>
</body></html>
