<?php
declare(strict_types=1);

require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/mailer.php';

header('Content-Type: text/html; charset=UTF-8');
$auth = requireRole(ROLE_FINANZAS);
$pdo = db();

$financeRun = normalizeRun((string) ($auth['run'] ?? extractRunFromUserInfo((array) ($auth['user_info'] ?? [])) ?? ''));
$financeName = trim((string) ($auth['name'] ?? 'Finanzas')) ?: 'Finanzas';
$financeStmt = $pdo->prepare("SELECT id,full_name FROM system_users WHERE run=:run AND role='FINANZAS' AND is_active=1 LIMIT 1");
$financeStmt->execute(['run' => $financeRun]);
$financeUser = $financeStmt->fetch();
if ($financeUser === false) {
    $pdo->prepare("INSERT INTO system_users (run,first_names,full_name,role,profession_experience,is_active) VALUES (:run,:name,:name,'FINANZAS','Finanzas',1)")
        ->execute(['run' => $financeRun, 'name' => $financeName]);
    $financeUser = ['id' => (int) $pdo->lastInsertId(), 'full_name' => $financeName];
}
$financeUserId = (int) $financeUser['id'];

function financeReport(PDO $pdo, int $reportId): array
{
    $stmt = $pdo->prepare("SELECT r.*,u.full_name AS honorario_name,u.email AS honorario_email,d.name AS direction_name,
        a.pdf_path AS agreement_pdf_path,ad.pdf_path AS agreement_decree_path,
        (SELECT md.pdf_path FROM decrees md WHERE md.honorario_user_id=r.honorario_user_id AND md.decree_number=r.decree_number_text ORDER BY md.id DESC LIMIT 1) AS manual_decree_path,
        (SELECT f.id FROM monthly_report_files f WHERE f.report_id=r.id AND f.file_type='RESPALDO' ORDER BY f.id DESC LIMIT 1) AS report_file_id,
        (SELECT f.stored_path FROM monthly_report_files f WHERE f.report_id=r.id AND f.file_type='RESPALDO' ORDER BY f.id DESC LIMIT 1) AS report_path,
        (SELECT f.stored_path FROM monthly_report_files f WHERE f.report_id=r.id AND f.file_type='CERTIFICADO' ORDER BY f.id DESC LIMIT 1) AS certificate_path,
        (SELECT f.stored_path FROM monthly_report_files f WHERE f.report_id=r.id AND f.file_type='BOLETA' ORDER BY f.id DESC LIMIT 1) AS boleta_path,
        (SELECT f.stored_path FROM monthly_report_files f WHERE f.report_id=r.id AND f.file_type='DECRETO' ORDER BY f.id DESC LIMIT 1) AS uploaded_decree_path,
        (SELECT f.stored_path FROM monthly_report_files f WHERE f.report_id=r.id AND f.file_type='CONVENIO_FIRMADO' ORDER BY f.id DESC LIMIT 1) AS uploaded_agreement_path
        FROM monthly_reports r
        INNER JOIN system_users u ON u.id=r.honorario_user_id
        LEFT JOIN directions d ON d.id=r.direction_id
        LEFT JOIN agreements a ON a.id=r.agreement_id
        LEFT JOIN decrees ad ON ad.id=a.decree_id
        WHERE r.id=:id LIMIT 1");
    $stmt->execute(['id' => $reportId]);
    $report = $stmt->fetch();
    if ($report === false) throw new RuntimeException('Informe no encontrado.');
    return $report;
}

function financeDocumentPaths(array $report): array
{
    return [
        'REPORT' => (string) ($report['report_path'] ?? ''),
        'BOLETA' => (string) ($report['boleta_path'] ?? ''),
        'CERTIFICATE' => (string) ($report['certificate_path'] ?? ''),
        'DECREE' => (string) (($report['agreement_decree_path'] ?? '') ?: ($report['manual_decree_path'] ?? '') ?: ($report['uploaded_decree_path'] ?? '')),
        'AGREEMENT' => (string) (($report['agreement_pdf_path'] ?? '') ?: ($report['uploaded_agreement_path'] ?? '')),
    ];
}

function financeAbsolutePath(string $storedPath): string
{
    if ($storedPath === '') throw new RuntimeException('El documento solicitado no está disponible.');
    $candidate = realpath(__DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $storedPath));
    $uploads = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads');
    if ($candidate === false || $uploads === false || !str_starts_with($candidate, $uploads . DIRECTORY_SEPARATOR) || !is_file($candidate)) {
        throw new RuntimeException('El archivo solicitado no está disponible en el servidor.');
    }
    return $candidate;
}

function archiveFinanceFile(PDO $pdo, array $file, string $stage, int $reviewId): void
{
    $pdo->prepare("INSERT IGNORE INTO monthly_report_file_history
        (report_id,source_file_id,finance_review_id,stage,original_name,stored_path,mime_type,size_bytes)
        VALUES (:report,:file,:review,:stage,:name,:path,:mime,:size)")
        ->execute([
            'report' => $file['report_id'], 'file' => $file['id'], 'review' => $reviewId,
            'stage' => $stage, 'name' => $file['original_name'], 'path' => $file['stored_path'],
            'mime' => $file['mime_type'], 'size' => $file['size_bytes'],
        ]);
}

if ((string) ($_GET['action'] ?? '') === 'view_document_pdf') {
    try {
        $report = financeReport($pdo, (int) ($_GET['report_id'] ?? 0));
        if (!in_array((string) $report['status'], ['APROBADO', 'APROBADO_PAGO'], true)) {
            throw new RuntimeException('El expediente aún no está disponible para Finanzas.');
        }
        $type = strtoupper((string) ($_GET['type'] ?? ''));
        $paths = financeDocumentPaths($report);
        if (!array_key_exists($type, $paths)) throw new RuntimeException('Tipo de documento no válido.');
        $absolute = financeAbsolutePath($paths[$type]);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . strtolower($type) . '_' . (int) $report['id'] . '.pdf"');
        header('Content-Length: ' . filesize($absolute));
        header('X-Content-Type-Options: nosniff');
        readfile($absolute);
    } catch (Throwable $e) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $e->getMessage();
    }
    exit;
}

$success = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? '');
        $reportId = (int) ($_POST['report_id'] ?? 0);
        if (!in_array($action, ['approve_payment', 'reject'], true) || $reportId < 1) throw new RuntimeException('Acción no válida.');
        $observation = trim((string) ($_POST['observation'] ?? ''));
        if ($action === 'reject' && $observation === '') throw new RuntimeException('La observación del rechazo es obligatoria.');

        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT * FROM monthly_reports WHERE id=:id FOR UPDATE');
        $lock->execute(['id' => $reportId]);
        $locked = $lock->fetch();
        if ($locked === false || (string) $locked['status'] !== 'APROBADO') throw new RuntimeException('El informe ya no está pendiente de revisión en Finanzas.');
        $report = financeReport($pdo, $reportId);

        $review = $pdo->prepare("INSERT INTO finance_report_reviews
            (report_id,finance_user_id,previous_director_user_id,previous_director_capacity,previous_director_signed_at,action,observation)
            VALUES (:report,:finance,:director,:capacity,:signed,:action,:observation)");
        $review->execute([
            'report' => $reportId, 'finance' => $financeUserId,
            'director' => $locked['reviewed_by_director_user_id'] ?: null,
            'capacity' => $locked['director_capacity'] ?: null,
            'signed' => $locked['director_signed_at'] ?: null,
            'action' => $action === 'approve_payment' ? 'APROBADO_PAGO' : 'RECHAZADO',
            'observation' => $observation !== '' ? $observation : null,
        ]);
        $reviewId = (int) $pdo->lastInsertId();

        if ($action === 'approve_payment') {
            $missing = [];
            $labels = ['REPORT'=>'informe','BOLETA'=>'boleta','CERTIFICATE'=>'certificado','DECREE'=>'decreto','AGREEMENT'=>'convenio'];
            foreach (financeDocumentPaths($report) as $type => $path) {
                try { financeAbsolutePath($path); } catch (Throwable $e) { $missing[] = $labels[$type]; }
            }
            if ($missing !== []) throw new RuntimeException('No se puede aprobar: faltan ' . implode(', ', $missing) . '.');
            $update = $pdo->prepare("UPDATE monthly_reports SET status='APROBADO_PAGO',finance_reviewed_by_user_id=:finance,finance_approved_at=NOW(),finance_rejected_at=NULL,finance_observation=NULL WHERE id=:id AND status='APROBADO'");
            $update->execute(['finance' => $financeUserId, 'id' => $reportId]);
            if ($update->rowCount() !== 1) throw new RuntimeException('El informe cambió de estado durante la revisión.');
            $pdo->commit();
            $success = 'Informe aprobado para pago.';
        } else {
            $files = $pdo->prepare("SELECT * FROM monthly_report_files WHERE report_id=:report AND file_type IN ('RESPALDO','CERTIFICADO') FOR UPDATE");
            $files->execute(['report' => $reportId]);
            foreach ($files->fetchAll() as $file) {
                archiveFinanceFile($pdo, $file, (string) $file['file_type'] === 'CERTIFICADO' ? 'CERTIFICADO' : 'FIRMADO_DIRECTOR', $reviewId);
            }
            $pdo->prepare("UPDATE monthly_report_files SET file_type='HISTORICO' WHERE report_id=:report AND file_type IN ('RESPALDO','CERTIFICADO')")
                ->execute(['report' => $reportId]);

            if ((string) $locked['source_type'] === 'MANUAL') {
                $original = $pdo->prepare("SELECT original_name,stored_path,mime_type,size_bytes FROM monthly_report_file_history WHERE report_id=:report AND stage='ORIGINAL' ORDER BY id DESC LIMIT 1");
                $original->execute(['report' => $reportId]);
                $base = $original->fetch();
                if ($base !== false) {
                    try {
                        financeAbsolutePath((string) $base['stored_path']);
                        $pdo->prepare("INSERT INTO monthly_report_files (report_id,file_type,original_name,stored_path,mime_type,size_bytes) VALUES (:report,'RESPALDO',:name,:path,:mime,:size)")
                            ->execute(['report'=>$reportId,'name'=>$base['original_name'],'path'=>$base['stored_path'],'mime'=>$base['mime_type'],'size'=>$base['size_bytes']]);
                    } catch (Throwable $ignored) {
                        // En informes antiguos puede no existir ya una copia original recuperable.
                    }
                }
            }

            $pdo->prepare("UPDATE signature_requests SET status='ANULADO' WHERE report_id=:report AND status='PENDIENTE'")
                ->execute(['report' => $reportId]);
            $pdo->prepare("UPDATE monthly_reports SET status='RECHAZADO',observations=:observation,submitted_at=NULL,
                reviewed_by_director_user_id=NULL,director_capacity=NULL,director_signed_at=NULL,director_rejection_observation=NULL,director_rejected_at=NULL,
                finance_reviewed_by_user_id=:finance,finance_approved_at=NULL,finance_rejected_at=NOW(),finance_observation=:observation
                WHERE id=:id")
                ->execute(['observation'=>$observation,'finance'=>$financeUserId,'id'=>$reportId]);
            $pdo->commit();
            $success = 'Informe rechazado y devuelto al prestador para corrección.';

            $email = trim((string) ($report['honorario_email'] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                try {
                    $safeName = htmlspecialchars((string) $report['honorario_name'], ENT_QUOTES, 'UTF-8');
                    $safeObservation = nl2br(htmlspecialchars($observation, ENT_QUOTES, 'UTF-8'));
                    sendSmtpMail($email, (string) $report['honorario_name'], 'Informe rechazado por Finanzas',
                        '<p>Hola ' . $safeName . ',</p><p>Finanzas rechazó tu informe. Debes ingresar al sistema para corregir las observaciones y comenzar nuevamente el proceso de firmas.</p><p><strong>Observación:</strong><br>' . $safeObservation . '</p>',
                        "Finanzas rechazó tu informe. Ingresa al sistema para corregirlo.\n\nObservación: " . $observation);
                } catch (Throwable $mailError) {
                    $success .= ' No fue posible enviar la notificación por correo.';
                }
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

$view = (string) ($_GET['view'] ?? 'pending');
if (!in_array($view, ['pending', 'approved'], true)) $view = 'pending';
$status = $view === 'approved' ? 'APROBADO_PAGO' : 'APROBADO';
$list = $pdo->prepare("SELECT r.id,r.report_month,r.report_year,r.source_type,r.director_signed_at,r.finance_approved_at,
    u.full_name AS honorario_name,d.name AS direction_name,du.full_name AS director_name,
    (SELECT COUNT(*) FROM monthly_report_files f WHERE f.report_id=r.id AND f.file_type IN ('RESPALDO','BOLETA','CERTIFICADO')) AS core_files
    FROM monthly_reports r INNER JOIN system_users u ON u.id=r.honorario_user_id
    LEFT JOIN directions d ON d.id=r.direction_id LEFT JOIN system_users du ON du.id=r.reviewed_by_director_user_id
    WHERE r.status=:status ORDER BY COALESCE(r.finance_approved_at,r.director_signed_at) DESC,r.id DESC");
$list->execute(['status' => $status]);
$reports = $list->fetchAll();
$counts = ['pending'=>(int)$pdo->query("SELECT COUNT(*) FROM monthly_reports WHERE status='APROBADO'")->fetchColumn(),'approved'=>(int)$pdo->query("SELECT COUNT(*) FROM monthly_reports WHERE status='APROBADO_PAGO'")->fetchColumn()];
$months = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Revisión de Finanzas</title>
<script src="https://cdn.jsdelivr.net/npm/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script><script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root{--navy:#102f48;--brand:#08778a;--line:#dce8f1;--muted:#62778a;--bg:#f3f7fa;--green:#eaf8ee;--green-line:#b8dec2;--danger:#b42318}*{box-sizing:border-box}body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:var(--bg);color:var(--navy)}.layout{min-height:100vh;display:grid;grid-template-columns:260px 1fr}.side{background:#fff;border-right:1px solid var(--line);padding:28px 24px}.side h1{font-size:1.25rem;margin:0 0 6px}.side p{color:var(--muted);margin:0 0 28px}.side a{display:block;color:var(--navy);text-decoration:none;padding:11px 12px;border-radius:10px;margin:5px 0}.side a.active{background:#e8f4f6;color:#056476;font-weight:700}.main{padding:34px;max-width:1260px;width:100%;margin:auto}.head{display:flex;justify-content:space-between;align-items:end;margin-bottom:20px}.head h2{font-size:1.65rem;margin:0 0 5px}.head p{margin:0;color:var(--muted)}.tabs{display:flex;gap:8px;margin-bottom:16px}.tab{padding:10px 14px;border:1px solid var(--line);background:#fff;border-radius:10px;text-decoration:none;color:var(--navy);font-weight:600}.tab.active{background:var(--brand);color:#fff;border-color:var(--brand)}.card{background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden}.row{display:grid;grid-template-columns:minmax(190px,1.5fr) minmax(170px,1.2fr) 150px auto;gap:18px;align-items:center;padding:15px 18px;border-bottom:1px solid var(--line)}.row:last-child{border-bottom:0}.row.approved{background:var(--green);border-color:var(--green-line)}.person{font-weight:700}.meta{font-size:.86rem;color:var(--muted);margin-top:4px}.actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}.btn{border:0;border-radius:9px;padding:9px 12px;font-weight:700;cursor:pointer;background:var(--brand);color:#fff}.btn.ghost{background:#fff;color:var(--brand);border:1px solid #a9ced5}.btn.danger{background:#fff;color:var(--danger);border:1px solid #efb4ae}.empty{padding:44px;text-align:center;color:var(--muted)}.alert{padding:13px 15px;border-radius:10px;margin-bottom:15px;background:#e8f7ed;color:#146c3b}.alert.error{background:#fff0ef;color:#9d2118}.modal{position:fixed;inset:0;background:rgba(10,30,45,.72);display:none;z-index:1000;padding:22px}.modal.open{display:flex}.modal-box{background:#fff;border-radius:14px;width:min(1150px,100%);height:calc(100vh - 44px);margin:auto;display:flex;flex-direction:column;overflow:hidden}.modal-head{padding:12px 16px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center}.modal iframe{border:0;flex:1;width:100%}.close{border:0;background:#edf3f6;border-radius:8px;padding:8px 12px;cursor:pointer}@media(max-width:850px){.layout{display:block}.side{border-right:0;border-bottom:1px solid var(--line)}.main{padding:20px}.row{grid-template-columns:1fr}.actions{justify-content:flex-start}}
</style></head><body><div class="layout"><aside class="side"><h1>Sistema Honorarios</h1><p>Perfil Finanzas</p><a class="active" href="finanzas.php">Revisión de informes</a><a href="logout.php">Cerrar sesión</a></aside><main class="main"><div class="head"><div><h2>Revisión para pago</h2><p>Expedientes firmados y enviados por las direcciones.</p></div></div>
<?php if($success!==''):?><div class="alert"><?=htmlspecialchars($success,ENT_QUOTES,'UTF-8')?></div><?php endif;?><?php if($error!==''):?><div class="alert error"><?=htmlspecialchars($error,ENT_QUOTES,'UTF-8')?></div><?php endif;?>
<nav class="tabs"><a class="tab <?=$view==='pending'?'active':''?>" href="?view=pending">Pendientes de revisión (<?=$counts['pending']?>)</a><a class="tab <?=$view==='approved'?'active':''?>" href="?view=approved">Aprobados para pago (<?=$counts['approved']?>)</a></nav>
<section class="card"><?php if($reports===[]):?><div class="empty">No hay informes en esta sección.</div><?php else:?><?php foreach($reports as $r):?><article class="row <?=$view==='approved'?'approved':''?>"><div><div class="person"><?=htmlspecialchars((string)$r['honorario_name'],ENT_QUOTES,'UTF-8')?></div><div class="meta"><?=htmlspecialchars($months[(int)$r['report_month']].' de '.$r['report_year'],ENT_QUOTES,'UTF-8')?> · <?=htmlspecialchars((string)$r['source_type'],ENT_QUOTES,'UTF-8')?></div></div><div><strong><?=htmlspecialchars((string)($r['direction_name']??'Sin dirección'),ENT_QUOTES,'UTF-8')?></strong><div class="meta">Firmado por <?=htmlspecialchars((string)($r['director_name']??'Director(a)'),ENT_QUOTES,'UTF-8')?></div></div><div><div class="meta"><?=$view==='approved'?'Aprobado para pago':'Pendiente de revisión'?></div></div><div class="actions"><button class="btn ghost" type="button" data-preview="<?=(int)$r['id']?>">Ver expediente</button><?php if($view==='pending'):?><form method="post" class="reject-form"><input type="hidden" name="action" value="reject"><input type="hidden" name="report_id" value="<?=(int)$r['id']?>"><input type="hidden" name="observation" value=""><button class="btn danger" type="submit">Rechazar</button></form><form method="post" class="approve-form"><input type="hidden" name="action" value="approve_payment"><input type="hidden" name="report_id" value="<?=(int)$r['id']?>"><button class="btn" type="submit">Aprobar pago</button></form><?php endif;?></div></article><?php endforeach;?><?php endif;?></section></main></div>
<div class="modal" id="previewModal"><div class="modal-box"><div class="modal-head"><strong>Expediente completo</strong><button class="close" type="button" id="closePreview">Cerrar</button></div><iframe id="previewFrame" title="Vista previa del expediente"></iframe></div></div>
<script>
const modal=document.getElementById('previewModal'),frame=document.getElementById('previewFrame');let currentUrl='';
async function mergedExpediente(reportId){
 if(typeof PDFLib==='undefined')throw new Error('No fue posible cargar el visor PDF.');
 const types=['REPORT','BOLETA','CERTIFICATE','DECREE','AGREEMENT'];const merged=await PDFLib.PDFDocument.create();
 for(const type of types){const response=await fetch(`finanzas.php?action=view_document_pdf&report_id=${reportId}&type=${type}`);if(!response.ok)throw new Error(await response.text()||'El expediente está incompleto.');const source=await PDFLib.PDFDocument.load(await response.arrayBuffer());const pages=await merged.copyPages(source,source.getPageIndices());pages.forEach(page=>merged.addPage(page));}
 return new Blob([await merged.save()],{type:'application/pdf'});
}
document.querySelectorAll('[data-preview]').forEach(button=>button.addEventListener('click',async()=>{try{Swal.fire({title:'Preparando expediente',text:'Uniendo informe, boleta, certificado, decreto y convenio.',allowOutsideClick:false,allowEscapeKey:false,showConfirmButton:false,didOpen:()=>Swal.showLoading()});const blob=await mergedExpediente(button.dataset.preview);Swal.close();if(currentUrl)URL.revokeObjectURL(currentUrl);currentUrl=URL.createObjectURL(blob);frame.src=currentUrl;modal.classList.add('open');}catch(e){Swal.fire({title:'Expediente incompleto',text:e.message||'No fue posible preparar la vista previa.',icon:'warning',confirmButtonColor:'#08778a'});}}));
function closePreview(){modal.classList.remove('open');frame.src='about:blank';if(currentUrl){URL.revokeObjectURL(currentUrl);currentUrl='';}}document.getElementById('closePreview').addEventListener('click',closePreview);modal.addEventListener('click',e=>{if(e.target===modal)closePreview();});
document.querySelectorAll('.approve-form').forEach(form=>form.addEventListener('submit',async e=>{e.preventDefault();const result=await Swal.fire({title:'¿Aprobar para pago?',text:'Se verificará que el expediente contenga los cinco documentos obligatorios.',icon:'question',showCancelButton:true,confirmButtonText:'Sí, aprobar pago',cancelButtonText:'Cancelar',confirmButtonColor:'#08778a'});if(result.isConfirmed){Swal.fire({title:'Aprobando informe',text:'Espere un momento.',allowOutsideClick:false,allowEscapeKey:false,showConfirmButton:false,didOpen:()=>Swal.showLoading()});form.submit();}}));
document.querySelectorAll('.reject-form').forEach(form=>form.addEventListener('submit',async e=>{e.preventDefault();const result=await Swal.fire({title:'Rechazar informe',text:'La observación será visible para el prestador.',input:'textarea',inputLabel:'Observación obligatoria',inputPlaceholder:'Indique claramente qué debe corregirse…',showCancelButton:true,confirmButtonText:'Rechazar y devolver',cancelButtonText:'Cancelar',confirmButtonColor:'#b42318',inputValidator:value=>!value.trim()?'Debe ingresar una observación.':undefined});if(result.isConfirmed){form.querySelector('[name=observation]').value=result.value.trim();Swal.fire({title:'Devolviendo informe',text:'Guardando la trazabilidad y notificando al prestador.',allowOutsideClick:false,allowEscapeKey:false,showConfirmButton:false,didOpen:()=>Swal.showLoading()});form.submit();}}));
</script></body></html>
