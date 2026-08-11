<?php
declare(strict_types=1);

require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/firmagob.php';
require_once __DIR__ . '/src/mailer.php';

header('Content-Type: text/html; charset=UTF-8');

$auth = requireRole(ROLE_DIRECTOR);
$pdo = db();
$run = normalizeRun((string) ($auth['run'] ?? extractRunFromUserInfo((array) ($auth['user_info'] ?? [])) ?? ''));
$profileStmt = $pdo->prepare("SELECT dp.*,u.id AS user_id,u.full_name,u.email,u.run FROM director_profiles dp INNER JOIN system_users u ON u.id=dp.system_user_id WHERE u.run=:run AND u.role='DIRECTOR' AND u.is_active=1 AND dp.is_active=1 LIMIT 1");
$profileStmt->execute(['run'=>$run]);
$director = $profileStmt->fetch();
if ($director === false) {
    http_response_code(403);
    exit('El perfil de director no está habilitado.');
}
$success = '';
$error = '';

function reportFilePath(array $file): string
{
    $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $file['stored_path']);
    $candidate = realpath(__DIR__ . DIRECTORY_SEPARATOR . $relative);
    $root = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads');
    if ($candidate === false || $root === false || !str_starts_with($candidate, $root . DIRECTORY_SEPARATOR) || !is_file($candidate)) {
        throw new RuntimeException('El archivo del informe no está disponible.');
    }
    return $candidate;
}

function directorReport(PDO $pdo, int $reportId, int $profileId): array
{
    $stmt = $pdo->prepare("SELECT r.*,f.id AS file_id,f.original_name,f.stored_path,f.size_bytes,
                                  f.mime_type,u.full_name AS honorario_name,u.first_names AS honorario_first_names,u.last_names AS honorario_last_names,
                                  u.email AS honorario_email,u.run AS honorario_run,
                                  d.name AS direction_name,d.mailbox_type,dd.assignment_type,dp.official_position,
                                  COALESCE(r.decree_date,ad.decree_date,md.decree_date) AS decree_date,
                                  a.pdf_path AS agreement_pdf_path,COALESCE(ad.pdf_path,md.pdf_path) AS decree_pdf_path
                           FROM monthly_reports r
                           INNER JOIN monthly_report_files f ON f.report_id=r.id AND f.file_type='RESPALDO'
                           INNER JOIN system_users u ON u.id=r.honorario_user_id
                           INNER JOIN directions d ON d.id=r.direction_id
                           INNER JOIN director_directions dd ON dd.direction_id=r.direction_id AND dd.director_profile_id=:profile
                           INNER JOIN director_profiles dp ON dp.id=dd.director_profile_id
                           LEFT JOIN agreements a ON a.id=r.agreement_id
                           LEFT JOIN decrees ad ON ad.id=a.decree_id
                           LEFT JOIN decrees md ON md.honorario_user_id=r.honorario_user_id AND md.decree_number=r.decree_number_text
                           WHERE r.id=:report
                           ORDER BY f.id DESC,md.id DESC LIMIT 1");
    $stmt->execute(['profile'=>$profileId,'report'=>$reportId]);
    $row = $stmt->fetch();
    if ($row === false) throw new RuntimeException('El documento no pertenece a uno de tus buzones.');
    return $row;
}

if ((string) ($_GET['action'] ?? '') === 'certificate_data' && (int) ($_GET['report_id'] ?? 0) > 0) {
    try {
        $report = directorReport($pdo, (int) $_GET['report_id'], (int) $director['id']);
        $months = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'report_id' => (int) $report['id'],
            'source_type' => (string) $report['source_type'],
            'month_name' => $months[(int) $report['report_month']] ?? (string) $report['report_month'],
            'year' => (int) $report['report_year'],
            'honorario_name' => (string) $report['honorario_name'],
            'honorario_run' => (string) $report['honorario_run'],
            'profession' => (string) $report['profession_experience'],
            'decree_number' => (string) ($report['decree_number_text'] ?? ''),
            'decree_date' => (string) ($report['decree_date'] ?? ''),
            'boleta_number' => (string) ($report['boleta_number'] ?? ''),
            'boleta_amount' => (float) ($report['boleta_amount'] ?? 0),
            'direction_name' => (string) $report['direction_name'],
            'mailbox_type' => (string) $report['mailbox_type'],
            'assignment_type' => (string) $report['assignment_type'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(404);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
if ((string) ($_GET['action'] ?? '') === 'view_document_pdf' && (int) ($_GET['report_id'] ?? 0) > 0) {
    try {
        $report = directorReport($pdo, (int) $_GET['report_id'], (int) $director['id']);
        if (!in_array((string) $report['status'], ['APROBADO','APROBADO_PAGO'], true)) throw new RuntimeException('El expediente solo está disponible para informes finalizados.');
        $type = strtoupper((string) ($_GET['type'] ?? ''));
        if (!in_array($type, ['REPORT','CERTIFICATE','BOLETA','DECREE','AGREEMENT'], true)) throw new RuntimeException('Tipo de documento no válido.');
        $storedPath = '';
        if ($type === 'REPORT') {
            $storedPath = (string) $report['stored_path'];
        } elseif ($type === 'AGREEMENT' && !empty($report['agreement_pdf_path'])) {
            $storedPath = (string) $report['agreement_pdf_path'];
        } elseif ($type === 'DECREE' && !empty($report['decree_pdf_path'])) {
            $storedPath = (string) $report['decree_pdf_path'];
        } else {
            $fileType = ['CERTIFICATE'=>'CERTIFICADO','BOLETA'=>'BOLETA','DECREE'=>'DECRETO','AGREEMENT'=>'CONVENIO_FIRMADO'][$type] ?? '';
            $fileStmt = $pdo->prepare('SELECT stored_path FROM monthly_report_files WHERE report_id=:report AND file_type=:type ORDER BY id DESC LIMIT 1');
            $fileStmt->execute(['report'=>$report['id'],'type'=>$fileType]);
            $storedPath = (string) ($fileStmt->fetchColumn() ?: '');
        }
        if ($storedPath === '') throw new RuntimeException('El documento solicitado no está disponible.');
        $path = reportFilePath(['stored_path'=>$storedPath]);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . strtolower($type) . '_' . (int) $report['id'] . '.pdf"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
    } catch (Throwable $e) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $e->getMessage();
    }
    exit;
}

if ((string) ($_GET['action'] ?? '') === 'view' && (int) ($_GET['report_id'] ?? 0) > 0) {
    try {
        $report = directorReport($pdo, (int) $_GET['report_id'], (int) $director['id']);
        $path = reportFilePath($report);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="informe_' . (int) $report['id'] . '.pdf"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
    } catch (Throwable $e) {
        http_response_code(404);
        echo $e->getMessage();
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? '');
        $reportId = (int) ($_POST['report_id'] ?? 0);
        $report = directorReport($pdo, $reportId, (int) $director['id']);
        if ($action === 'reject') {
            $observation = trim((string) ($_POST['observation'] ?? ''));
            if ($observation === '') throw new RuntimeException('La observación del rechazo es obligatoria.');
            $stmt = $pdo->prepare("UPDATE monthly_reports SET status='RECHAZADO', director_rejection_observation=:observation, observations=:observation, reviewed_by_director_user_id=:director, director_capacity=:capacity, director_rejected_at=NOW(), director_signed_at=NULL WHERE id=:id AND status='ENVIADO'");
            $stmt->execute(['observation'=>$observation,'director'=>$director['user_id'],'capacity'=>$report['assignment_type']==='PRINCIPAL'?'TITULAR':'SUBROGANTE','id'=>$reportId]);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('El informe ya no está pendiente de revisión.');
            $success = 'Informe rechazado y devuelto al honorario para su corrección.';
        } elseif ($action === 'sign') {
            $upload = $_FILES['prepared_pdf'] ?? [];
            $certificateUpload = $_FILES['prepared_certificate'] ?? [];
            if ((int) ($upload['error'] ?? -1) !== UPLOAD_ERR_OK) throw new RuntimeException('No se recibió el PDF preparado para firmar.');
            if ((int) ($certificateUpload['error'] ?? -1) !== UPLOAD_ERR_OK) throw new RuntimeException('No se recibió el certificado preparado para firmar.');
            $prepared = file_get_contents((string) $upload['tmp_name']);
            $preparedCertificate = file_get_contents((string) $certificateUpload['tmp_name']);
            if ($prepared === false || !str_starts_with($prepared, '%PDF-')) throw new RuntimeException('El informe preparado no es un PDF válido.');
            if ($preparedCertificate === false || !str_starts_with($preparedCertificate, '%PDF-')) throw new RuntimeException('El certificado preparado no es un PDF válido.');
            if (trim((string) ($report['boleta_number'] ?? '')) === '' || (float) ($report['boleta_amount'] ?? 0) <= 0) {
                throw new RuntimeException('El informe debe tener número y valor líquido de boleta antes de finalizarlo.');
            }

            $usedFirmaGob = firmaGobIsConfigured();
            $signedPdf = $usedFirmaGob
                ? signPdfWithFirmaGob($prepared, (string) $director['run'], 'Informe mensual ' . $report['report_month'] . '/' . $report['report_year'] . ' - ' . $report['honorario_name'])
                : $prepared;
            $signedCertificate = $usedFirmaGob
                ? signPdfWithFirmaGob($preparedCertificate, (string) $director['run'], 'Certificado de cumplimiento ' . $report['report_month'] . '/' . $report['report_year'] . ' - ' . $report['honorario_name'])
                : $preparedCertificate;

            $dir = __DIR__ . '/uploads/reports/director_signed';
            $certificateDir = __DIR__ . '/uploads/reports/certificates';
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('No fue posible crear la carpeta de documentos firmados.');
            if (!is_dir($certificateDir) && !mkdir($certificateDir, 0775, true) && !is_dir($certificateDir)) throw new RuntimeException('No fue posible crear la carpeta de certificados.');
            $name = 'informe_final_' . $reportId . '_' . date('YmdHis') . '.pdf';
            $certificateName = 'certificado_' . $reportId . '_' . date('YmdHis') . '.pdf';
            $reportAbsolutePath = $dir . '/' . $name;
            $certificateAbsolutePath = $certificateDir . '/' . $certificateName;
            if (file_put_contents($reportAbsolutePath, $signedPdf, LOCK_EX) === false) throw new RuntimeException('No fue posible guardar el documento firmado.');
            if (file_put_contents($certificateAbsolutePath, $signedCertificate, LOCK_EX) === false) {
                @unlink($reportAbsolutePath);
                throw new RuntimeException('No fue posible guardar el certificado firmado.');
            }
            $relative = 'uploads/reports/director_signed/' . $name;
            $certificateRelative = 'uploads/reports/certificates/' . $certificateName;

            try {
                $pdo->beginTransaction();
                $lock = $pdo->prepare("SELECT status FROM monthly_reports WHERE id=:id FOR UPDATE");
                $lock->execute(['id'=>$reportId]);
                if ($lock->fetchColumn() !== 'ENVIADO') throw new RuntimeException('Este informe ya fue firmado o dejó de estar pendiente.');
                $pdo->prepare("INSERT IGNORE INTO monthly_report_file_history (report_id,source_file_id,stage,original_name,stored_path,mime_type,size_bytes)
                               VALUES (:report,:file,'FIRMADO_FUNCIONARIO',:name,:path,:mime,:size)")
                    ->execute(['report'=>$reportId,'file'=>$report['file_id'],'name'=>$report['original_name'],'path'=>$report['stored_path'],
                               'mime'=>$report['mime_type'],'size'=>$report['size_bytes']]);
                $pdo->prepare('UPDATE monthly_report_files SET original_name=:name,stored_path=:path,mime_type=\'application/pdf\',size_bytes=:size WHERE id=:id')
                    ->execute(['name'=>$name,'path'=>$relative,'size'=>strlen($signedPdf),'id'=>$report['file_id']]);
                $pdo->prepare("DELETE FROM monthly_report_files WHERE report_id=:report AND file_type='CERTIFICADO'")->execute(['report'=>$reportId]);
                $pdo->prepare("INSERT INTO monthly_report_files (report_id,file_type,original_name,stored_path,mime_type,size_bytes) VALUES (:report,'CERTIFICADO',:name,:path,'application/pdf',:size)")
                    ->execute(['report'=>$reportId,'name'=>$certificateName,'path'=>$certificateRelative,'size'=>strlen($signedCertificate)]);
                $pdo->prepare("UPDATE monthly_reports SET status='APROBADO', reviewed_by_director_user_id=:director, director_capacity=:capacity, director_signed_at=NOW(), director_rejection_observation=NULL, director_rejected_at=NULL, observations=NULL WHERE id=:id")
                    ->execute(['director'=>$director['user_id'],'capacity'=>$report['assignment_type']==='PRINCIPAL'?'TITULAR':'SUBROGANTE','id'=>$reportId]);
                $pdo->commit();
            } catch (Throwable $databaseError) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                @unlink($reportAbsolutePath);
                @unlink($certificateAbsolutePath);
                throw $databaseError;
            }
            $email = trim((string) $report['honorario_email']);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                try {
                    sendSmtpMail($email, (string) $report['honorario_name'], 'Informe mensual firmado por dirección', '<p>Hola ' . htmlspecialchars((string)$report['honorario_name'],ENT_QUOTES,'UTF-8') . ',</p><p>Tu informe mensual fue firmado por el director y quedó finalizado.</p>', 'Tu informe mensual fue firmado por el director y quedó finalizado.');
                } catch (Throwable $mailError) {
                    $success = 'Informe firmado y finalizado. No fue posible enviar la notificación por correo.';
                }
            }
            if ($success === '') {
                $success = $usedFirmaGob
                    ? 'Informe firmado electrónicamente y finalizado.'
                    : 'Informe finalizado con la imagen de firma del director. FirmaGob no está configurado.';
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

$view = (string) ($_GET['view'] ?? 'pending');
if (!in_array($view, ['pending', 'signed'], true)) $view = 'pending';
$reportStatus = $view === 'signed' ? 'APROBADO' : 'ENVIADO';
$boxesStmt = $pdo->prepare("SELECT d.id,d.name,d.mailbox_type,dd.assignment_type,dd.administrative_order,
                            (SELECT COUNT(*) FROM monthly_reports r WHERE r.direction_id=d.id AND r.status='ENVIADO') AS pending_count,
                            (SELECT COUNT(*) FROM monthly_reports r WHERE r.direction_id=d.id AND r.status IN ('APROBADO','APROBADO_PAGO')) AS signed_count
                            FROM director_directions dd INNER JOIN directions d ON d.id=dd.direction_id
                            WHERE dd.director_profile_id=:profile AND d.is_active=1
                              AND (dd.assignment_type='PRINCIPAL' OR EXISTS(SELECT 1 FROM monthly_reports r2 WHERE r2.direction_id=d.id AND (r2.status=:status OR (:status='APROBADO' AND r2.status='APROBADO_PAGO'))))
                            ORDER BY (dd.assignment_type='PRINCIPAL') DESC,dd.administrative_order,d.name");
$boxesStmt->execute(['profile'=>$director['id'],'status'=>$reportStatus]);
$boxes = $boxesStmt->fetchAll();
$selectedDirection = (int) ($_GET['direction_id'] ?? ($boxes[0]['id'] ?? 0));
$reports = [];
if ($selectedDirection > 0) {
    $reportsStmt = $pdo->prepare("SELECT r.id,r.report_month,r.report_year,r.provider_name,r.submitted_at,r.director_signed_at,r.director_capacity,f.id AS file_id,du.full_name AS director_name
                                  FROM monthly_reports r INNER JOIN monthly_report_files f ON f.id=(SELECT MAX(f2.id) FROM monthly_report_files f2 WHERE f2.report_id=r.id AND f2.file_type='RESPALDO')
                                  INNER JOIN director_directions dd ON dd.direction_id=r.direction_id AND dd.director_profile_id=:profile
                                  LEFT JOIN system_users du ON du.id=r.reviewed_by_director_user_id
                                  WHERE r.direction_id=:direction AND (r.status=:status OR (:status='APROBADO' AND r.status='APROBADO_PAGO'))
                                  ORDER BY COALESCE(r.director_signed_at,r.submitted_at) DESC,r.id DESC");
    $reportsStmt->execute(['profile'=>$director['id'],'direction'=>$selectedDirection,'status'=>$reportStatus]);
    $reports=$reportsStmt->fetchAll();
}
$reportMonthNames = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$signatureUrl = (string) ($director['signature_path'] ?? '');
$firmaGobConfigured = firmaGobIsConfigured();
$selectedAssignmentType = 'PRINCIPAL';
$selectedMailboxType = 'DIRECCION';
$selectedDirectionName = '';
if ($selectedDirection > 0) {
    $selectedBoxStmt = $pdo->prepare("SELECT dd.assignment_type,d.mailbox_type,d.name
                                      FROM director_directions dd INNER JOIN directions d ON d.id=dd.direction_id
                                      WHERE dd.director_profile_id=:profile AND d.id=:direction LIMIT 1");
    $selectedBoxStmt->execute(['profile'=>$director['id'],'direction'=>$selectedDirection]);
    $selectedBox = $selectedBoxStmt->fetch();
    if ($selectedBox !== false) {
        $selectedAssignmentType = (string) $selectedBox['assignment_type'];
        $selectedMailboxType = (string) $selectedBox['mailbox_type'];
        $selectedDirectionName = (string) $selectedBox['name'];
    }
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Buzones de documentos</title><script src="https://cdn.jsdelivr.net/npm/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>
<style>
:root{--p:#0b7285;--bg:#f3f7fb;--line:#dbe7f1;--text:#17324a;--muted:#60778b;--danger:#b42318}
*{box-sizing:border-box}
body{margin:0;font-family:Segoe UI,sans-serif;background:var(--bg);color:var(--text)}
.shell{display:grid;grid-template-columns:280px 1fr;min-height:100vh}
.side{background:#fff;border-right:1px solid var(--line);padding:24px}
.box{display:block;padding:12px;margin:8px 0;border:1px solid var(--line);border-radius:10px;text-decoration:none;color:var(--text)}
.box.active{background:#eaf6f8;border-color:var(--p)}
.badge{float:right;background:var(--p);color:#fff;border-radius:20px;padding:2px 8px}
.main{padding:28px}
.tabs{display:flex;gap:8px;margin:0 0 18px}
.tab{padding:10px 16px;border:1px solid var(--line);border-radius:10px;background:#fff;color:var(--text);font-weight:700;text-decoration:none}
.tab.active{background:var(--p);border-color:var(--p);color:#fff}
.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:12px 16px;margin-bottom:8px}
.report-card{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:14px}
.report-info{min-width:0}.report-info h3{margin:0 0 3px;font-size:1rem}.report-info p{margin:2px 0;font-size:.9rem}.report-card .actions{justify-content:flex-end}
.actions{display:flex;gap:8px;flex-wrap:wrap}
.btn{border:0;border-radius:8px;padding:9px 13px;background:var(--p);color:#fff;font-weight:700;cursor:pointer;text-decoration:none}
.btn.light{background:#eef4f8;color:var(--text)}
.btn.danger{background:#fff0ef;color:var(--danger)}
.alert{padding:12px;border-radius:9px;margin-bottom:15px}
.ok{background:#edf9f2}
.err{background:#fff0ef}
.muted{color:var(--muted)}
.modal{display:none;position:fixed;inset:0;background:#0008;align-items:center;justify-content:center;padding:20px}
.modal.open{display:flex}
.dialog{background:#fff;border-radius:14px;padding:20px;max-width:560px;width:100%}
textarea{width:100%;min-height:120px;padding:10px}
.viewer{display:none;position:fixed;inset:0;background:rgba(10,26,43,.52);padding:18px;z-index:220;align-items:center;justify-content:center}
.viewer.open{display:flex}
.viewer-dialog{width:min(1100px,96vw);height:min(850px,92vh);display:flex;flex-direction:column;background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:0 24px 60px rgba(6,22,38,.26);overflow:hidden}
.viewer-head{display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid var(--line)}
.viewer-head h3{margin:0}
.viewer-head p{margin:3px 0 0;color:var(--muted);font-size:.88rem}
.viewer-close{border:0;background:#eef4f8;color:var(--text);width:36px;height:36px;border-radius:50%;font-size:24px;line-height:1;cursor:pointer}
.viewer-body{display:flex;flex:1;min-height:0;overflow:hidden}
.viewer iframe{width:100%;height:100%;border:0;background:#eef2f6}
.viewer-footer{display:none;justify-content:flex-end;padding:14px 18px;border-top:1px solid var(--line);background:#fff}
.viewer-footer.visible{display:flex}
@media(max-width:800px){.shell{display:block}.main{padding:16px}.report-card{grid-template-columns:1fr}.report-card .actions{justify-content:flex-start}}
</style></head><body>
<div class="shell">
<aside class="side">
    <h2>Buzones de documentos</h2>
    <p><?=htmlspecialchars((string) $director['full_name'], ENT_QUOTES, 'UTF-8')?></p>
    <?php foreach($boxes as $box): ?>
        <a class="box <?=$selectedDirection === (int) $box['id'] ? 'active' : ''?>" href="?view=<?=urlencode($view)?>&amp;direction_id=<?=(int) $box['id']?>">
            <?=htmlspecialchars((string) $box['name'], ENT_QUOTES, 'UTF-8')?>
            <span class="badge"><?=$view === 'signed' ? (int) $box['signed_count'] : (int) $box['pending_count']?></span>
            <small><br><?=$box['assignment_type'] === 'PRINCIPAL' ? 'Dirección principal' : 'Subrogancia'?></small>
        </a>
    <?php endforeach; ?>
    <a class="box" href="director_funcionarios.php">Funcionarios</a>
    <a class="box" href="logout.php">Cerrar sesión</a>
</aside>
<main class="main">
    <h1>Informes</h1>
    <nav class="tabs" aria-label="Estado de los informes">
        <a class="tab <?=$view === 'pending' ? 'active' : ''?>" href="?view=pending<?=$selectedDirection > 0 ? '&amp;direction_id=' . $selectedDirection : ''?>">Pendientes</a>
        <a class="tab <?=$view === 'signed' ? 'active' : ''?>" href="?view=signed<?=$selectedDirection > 0 ? '&amp;direction_id=' . $selectedDirection : ''?>">Firmados</a>
    </nav>
    <?php if($success): ?><div class="alert ok"><?=htmlspecialchars($success, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
    <?php if($error): ?><div class="alert err"><?=htmlspecialchars($error, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
    <?php if(!$reports): ?>
        <div class="card">No hay informes <?=$view === 'signed' ? 'firmados' : 'pendientes'?> en este buzón.</div>
    <?php endif; ?>
    <?php foreach($reports as $r): ?>
        <article class="card report-card">
            <div class="report-info">
            <h3><?=htmlspecialchars((string) $r['provider_name'], ENT_QUOTES, 'UTF-8')?> &mdash; <?=htmlspecialchars((string) ($reportMonthNames[(int) $r['report_month']] ?? $r['report_month']), ENT_QUOTES, 'UTF-8')?> de <?=(int) $r['report_year']?></h3>
            <?php if($view === 'signed'): ?>
                <p class="muted">
                    Firmado por <?=htmlspecialchars((string) ($r['director_name'] ?: 'Director(a)'), ENT_QUOTES, 'UTF-8')?>
                    <?=($r['director_capacity'] ?? '') === 'SUBROGANTE' ? 'como Director(a) subrogante' : 'como Director(a)'?>.
                    <?php if(!empty($r['director_signed_at'])): ?>Fecha: <?=htmlspecialchars((string) $r['director_signed_at'], ENT_QUOTES, 'UTF-8')?>.<?php endif; ?>
                </p>
            <?php endif; ?>
            </div>
            <div class="actions">
                <button class="btn light" data-view="<?=(int) $r['id']?>" data-can-sign="<?=$view === 'pending' ? '1' : '0'?>">Ver informe</button>
                <?php if($view === 'signed'): ?>
                    <button class="btn light" type="button" data-view-url="director.php?action=view_document_pdf&amp;type=CERTIFICATE&amp;report_id=<?=(int) $r['id']?>" data-view-title="Certificado">Ver certificado</button>
                    <button class="btn light" type="button" data-print-bundle="<?=(int) $r['id']?>" title="Unir e imprimir expediente" aria-label="Unir e imprimir informe, boleta, certificado, decreto y convenio">&#128424;</button>
                <?php endif; ?>
                <?php if($view === 'pending'): ?>
                    <button class="btn" type="button" data-sign="<?=(int) $r['id']?>">Firmar</button>
                    <button class="btn danger" data-reject="<?=(int) $r['id']?>">Rechazar</button>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</main>
</div>
<div class="viewer" id="viewer" aria-hidden="true">
    <div class="viewer-dialog" role="dialog" aria-modal="true" aria-labelledby="viewerTitle">
        <div class="viewer-head">
            <div><h3 id="viewerTitle">Informe</h3><p>Documento PDF enviado para revisión.</p></div>
            <button class="viewer-close" type="button" id="closeViewer" aria-label="Cerrar vista previa">×</button>
        </div>
        <div class="viewer-body"><iframe id="pdfFrame" title="Vista previa del informe PDF"></iframe></div>
        <div class="viewer-footer" id="viewerFooter">
            <button class="btn" type="button" id="viewerSignButton">Firmar y finalizar</button>
        </div>
    </div>
</div>
<form method="post" id="rejectForm" hidden><input type="hidden" name="action" value="reject"><input type="hidden" name="report_id" id="rejectId"><input type="hidden" name="observation" id="rejectObservation"></form>
<form method="post" enctype="multipart/form-data" id="signForm" hidden><input name="action" value="sign"><input name="report_id" id="signId"><input type="file" name="prepared_pdf" id="preparedPdf"><input type="file" name="prepared_certificate" id="preparedCertificate"></form>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const viewer=document.getElementById('viewer'),frame=document.getElementById('pdfFrame'),viewerFooter=document.getElementById('viewerFooter'),viewerSignButton=document.getElementById('viewerSignButton'),rejectForm=document.getElementById('rejectForm');
const closeViewer=()=>{viewer.classList.remove('open');viewer.setAttribute('aria-hidden','true');frame.src='';viewerFooter.classList.remove('visible');viewerSignButton.dataset.reportId='';document.body.style.overflow=''};
const openPdfViewer=(url,reportId='',canSign=false,title='Informe')=>{
 frame.src=url;
 document.getElementById('viewerTitle').textContent=title;
 viewerSignButton.dataset.reportId=reportId;
 viewerFooter.classList.toggle('visible',canSign);
 viewer.classList.add('open');viewer.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';
};
document.querySelectorAll('[data-view]').forEach(b=>b.onclick=()=>openPdfViewer('director.php?action=view&report_id='+b.dataset.view,b.dataset.view,b.dataset.canSign==='1'));
document.querySelectorAll('[data-view-url]').forEach(b=>b.onclick=()=>openPdfViewer(b.dataset.viewUrl||'','',false,b.dataset.viewTitle||'Documento'));
document.getElementById('closeViewer').onclick=closeViewer;
viewer.addEventListener('click',event=>{if(event.target===viewer)closeViewer()});
document.addEventListener('keydown',event=>{if(event.key==='Escape'&&viewer.classList.contains('open'))closeViewer()});
document.querySelectorAll('[data-reject]').forEach(button=>button.onclick=async()=>{
 let observation='';
 if(typeof window.Swal==='undefined'){
  observation=(window.prompt('Indique la observación obligatoria para devolver el informe:')||'').trim();
  if(!observation)return;
 }else{
  const result=await window.Swal.fire({
   title:'Rechazar informe',
   text:'El informe será devuelto al funcionario para su corrección.',
   input:'textarea',
   inputLabel:'Observación obligatoria',
   inputPlaceholder:'Explique claramente qué debe corregirse…',
   inputAttributes:{'aria-label':'Observación obligatoria del rechazo'},
   icon:'warning',
   showCancelButton:true,
   confirmButtonText:'Rechazar y devolver',
   cancelButtonText:'Cancelar',
   confirmButtonColor:'#b42318',
   cancelButtonColor:'#64748b',
   reverseButtons:true,
   focusCancel:true,
   inputValidator:value=>!value||!value.trim()?'Debes ingresar una observación para rechazar el informe.':undefined
  });
  if(!result.isConfirmed)return;
  observation=result.value.trim();
  window.Swal.fire({title:'Devolviendo informe',text:'Espere un momento.',allowOutsideClick:false,allowEscapeKey:false,showConfirmButton:false,didOpen:()=>window.Swal.showLoading()});
 }
 document.getElementById('rejectId').value=button.dataset.reject;
 document.getElementById('rejectObservation').value=observation;
 HTMLFormElement.prototype.submit.call(rejectForm);
});
viewerSignButton.onclick=async()=>{
 const button=viewerSignButton,reportId=button.dataset.reportId;
 if(!reportId)return;
 if(typeof window.Swal==='undefined'){
  if(!window.confirm('¿Firmar electrónicamente y finalizar este informe?'))return;
 }else{
  const result=await window.Swal.fire({
   title:'¿Firmar y finalizar el informe?',
   text:'La firma del director será estampada en el PDF y el proceso quedará finalizado.',
   icon:'question',
   showCancelButton:true,
   confirmButtonText:'Firmar y finalizar',
   cancelButtonText:'Cancelar',
   confirmButtonColor:'#086374',
   cancelButtonColor:'#64748b',
   reverseButtons:true,
   focusCancel:true
  });
  if(!result.isConfirmed)return;
  window.Swal.fire({title:'Firmando informe',text:'Espere un momento mientras se prepara la firma.',allowOutsideClick:false,allowEscapeKey:false,showConfirmButton:false,didOpen:()=>window.Swal.showLoading()});
 }
 button.disabled=true;button.textContent='Firmando…';
 try{
  const [pdfResponse,signatureResponse,dataResponse]=await Promise.all([fetch('director.php?action=view&report_id='+reportId),fetch(<?=json_encode($signatureUrl)?>),fetch('director.php?action=certificate_data&report_id='+reportId)]);
  if(!pdfResponse.ok||!signatureResponse.ok||!dataResponse.ok)throw new Error('No fue posible cargar el informe, los datos del certificado o la imagen de firma.');
  const certificateData=await dataResponse.json();
  const pdfDoc=await PDFLib.PDFDocument.load(await pdfResponse.arrayBuffer());
  const signatureBytes=await signatureResponse.arrayBuffer();
  const signatureType=signatureResponse.headers.get('content-type')||'';
  const image=signatureType.includes('png')?await pdfDoc.embedPng(signatureBytes):await pdfDoc.embedJpg(signatureBytes);
  const page=pdfDoc.getPages()[pdfDoc.getPageCount()-1];
  const font=await pdfDoc.embedFont(PDFLib.StandardFonts.Helvetica);
  const boldFont=await pdfDoc.embedFont(PDFLib.StandardFonts.HelveticaBold);
  const sourceRatio=image.width/image.height,expectedRatio=885/293;
  if(Math.abs(sourceRatio-expectedRatio)>0.08)throw new Error('La imagen de firma debe medir 885 x 293 px o mantener esa proporción.');
  const stampWidth=Math.min(240,page.getWidth()-56),stampHeight=stampWidth/expectedRatio;
  const stampScale=stampWidth/300;
  const stampOffsetLeft=85,signatureShiftRight=56.7;
  const stampX=page.getWidth()-stampWidth-28-stampOffsetLeft+signatureShiftRight,stampY=90;
  page.drawImage(image,{x:stampX,y:stampY,width:stampWidth,height:stampHeight});

  const textX=stampX+stampWidth*(295/885)+(7*stampScale);
  const maxTextWidth=stampX+stampWidth-textX-(8*stampScale);
  const fitSize=(text,usedFont,preferred,min=5.8)=>{
   let size=preferred;
   while(size>min&&usedFont.widthOfTextAtSize(text,size)>maxTextWidth)size-=0.2;
   return size;
  };
  const dateParts=Object.fromEntries(new Intl.DateTimeFormat('es-CL',{
   timeZone:'America/Santiago',day:'2-digit',month:'2-digit',year:'numeric',
   hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'
  }).formatToParts(new Date()).filter(part=>part.type!=='literal').map(part=>[part.type,part.value]));
  const signedAt=`${dateParts.day}.${dateParts.month}.${dateParts.year} ${dateParts.hour}:${dateParts.minute}:${dateParts.second}`;
  const signerName=<?=json_encode((string)$director['full_name'])?>.toLocaleUpperCase('es-CL');
  const signatureHeading=<?=json_encode($firmaGobConfigured ? 'Firmado digitalmente por:' : 'Firmado por:')?>;
  const assignmentType=<?=json_encode($selectedAssignmentType)?>;
  const mailboxType=<?=json_encode($selectedMailboxType)?>;
  const directionName=<?=json_encode($selectedDirectionName)?>;
  const baseRole=mailboxType==='DEPARTAMENTO'?'Jefe(a)':'Director(a)';
  const unitName=directionName.replace(/^(Dirección|Departamento)\s+(de\s+)?/i,'');
  const roleLines=assignmentType==='SUBROGANTE'
   ? [`${baseRole} Subrogante`,directionName]
   : [`${baseRole}${unitName?` de ${unitName}`:''}`];

  page.drawText(signatureHeading,{x:textX,y:stampY+stampHeight-(21*stampScale),size:fitSize(signatureHeading,font,8.2*stampScale),font});
  page.drawText(signerName,{x:textX,y:stampY+stampHeight-(36*stampScale),size:fitSize(signerName,boldFont,8.5*stampScale),font:boldFont});
  const dateLine=`Fecha: ${signedAt}`;
  page.drawText(dateLine,{x:textX,y:stampY+stampHeight-(51*stampScale),size:fitSize(dateLine,font,8.2*stampScale),font});
  page.drawText(roleLines[0],{x:textX,y:stampY+(25*stampScale),size:fitSize(roleLines[0],font,8.5*stampScale),font});
  if(roleLines[1])page.drawText(roleLines[1],{x:textX,y:stampY+(12*stampScale),size:fitSize(roleLines[1],font,7.8*stampScale),font});
  const certificateDoc=await PDFLib.PDFDocument.create();
  const certificatePage=certificateDoc.addPage([595.28,841.89]);
  const certificateFont=await certificateDoc.embedFont(PDFLib.StandardFonts.Helvetica);
  const certificateBold=await certificateDoc.embedFont(PDFLib.StandardFonts.HelveticaBold);
  const pageWidth=certificatePage.getWidth(),margin=58,contentWidth=pageWidth-(margin*2);
  const wrapText=(text,usedFont,size,maxWidth)=>{const words=String(text).split(/\s+/),lines=[];let line='';words.forEach(word=>{const candidate=line?line+' '+word:word;if(usedFont.widthOfTextAtSize(candidate,size)<=maxWidth)line=candidate;else{if(line)lines.push(line);line=word}});if(line)lines.push(line);return lines};
  const drawParagraph=(text,y,size=11,lineHeight=17)=>{wrapText(text,certificateFont,size,contentWidth).forEach(line=>{certificatePage.drawText(line,{x:margin,y,size,font:certificateFont});y-=lineHeight});return y-12};
  const formatRun=value=>{const clean=String(value||'').replace(/[^0-9K]/gi,'').toUpperCase();if(clean.length<2)return value;const body=clean.slice(0,-1).replace(/\B(?=(\d{3})+(?!\d))/g,'.');return body+'-'+clean.slice(-1)};
  const formatDate=value=>{if(!value)return 'fecha no registrada';const date=new Date(value+'T12:00:00');return new Intl.DateTimeFormat('es-CL',{day:'2-digit',month:'long',year:'numeric',timeZone:'America/Santiago'}).format(date)};
  const formatMoney=value=>new Intl.NumberFormat('es-CL',{maximumFractionDigits:0}).format(Number(value||0));
  certificatePage.drawText('CERTIFICADO',{x:(pageWidth-certificateBold.widthOfTextAtSize('CERTIFICADO',16))/2,y:766,size:16,font:certificateBold});
  const placeLine=`Los Lagos, ${certificateData.month_name} ${certificateData.year}.`;
  certificatePage.drawText(placeLine,{x:pageWidth-margin-certificateFont.widthOfTextAtSize(placeLine,11),y:716,size:11,font:certificateFont});
  const certificateBaseRole=certificateData.mailbox_type==='DEPARTAMENTO'?'Jefe(a)':'Director(a)';
  const certificateCapacity=certificateData.assignment_type==='SUBROGANTE'?' Subrogante':'';
  const certificateUnitName=String(certificateData.direction_name||'').replace(/^(Dirección|Departamento)\s+(de\s+)?/i,'');
  const directorRole=`${certificateBaseRole}${certificateCapacity}${certificateUnitName?` de ${certificateUnitName}`:''}`;
  const decreePhrase=certificateData.source_type==='CONVENIO'?'que aprueba convenio de prestación de Servicios a Honorarios':'que aprueba su contratación a honorarios';
  let certificateY=655;
  certificateY=drawParagraph(`${<?=json_encode((string)$director['full_name'])?>}, ${directorRole}, de la Municipalidad de Los Lagos, certifica que la persona prestadora de servicios, ${certificateData.honorario_name}, RUN ${formatRun(certificateData.honorario_run)}, en su calidad de ${String(certificateData.profession||'').toLocaleUpperCase('es-CL')}, de la Municipalidad de Los Lagos según Decreto Afecto N.º ${certificateData.decree_number||'sin número'} de fecha ${formatDate(certificateData.decree_date)}, ${decreePhrase}, cumplió labores satisfactoriamente durante el mes de ${certificateData.month_name} de ${certificateData.year}.`,certificateY);
  certificateY=drawParagraph(`Se adjunta Boleta de Honorarios N.º ${certificateData.boleta_number} por un valor total líquido de $${formatMoney(certificateData.boleta_amount)}.-, más Informe del mes de ${certificateData.month_name} de ${certificateData.year}.`,certificateY);
  drawParagraph('Se extiende el presente certificado para ser presentado en el Departamento de Finanzas de la I. Municipalidad de Los Lagos.',certificateY);
  const certificateImage=signatureType.includes('png')?await certificateDoc.embedPng(signatureBytes):await certificateDoc.embedJpg(signatureBytes);
  const certificateStampWidth=240,certificateStampHeight=certificateStampWidth/expectedRatio;
  const certificateStampX=(pageWidth-certificateStampWidth)/2,certificateStampY=72;
  certificatePage.drawImage(certificateImage,{x:certificateStampX,y:certificateStampY,width:certificateStampWidth,height:certificateStampHeight});
  const certScale=certificateStampWidth/300,certTextX=certificateStampX+certificateStampWidth*(295/885)+(7*certScale),certMaxWidth=certificateStampX+certificateStampWidth-certTextX-(8*certScale);
  const certFit=(text,usedFont,preferred,min=5.8)=>{let size=preferred;while(size>min&&usedFont.widthOfTextAtSize(text,size)>certMaxWidth)size-=0.2;return size};
  certificatePage.drawText(signatureHeading,{x:certTextX,y:certificateStampY+certificateStampHeight-(21*certScale),size:certFit(signatureHeading,certificateFont,8.2*certScale),font:certificateFont});
  certificatePage.drawText(signerName,{x:certTextX,y:certificateStampY+certificateStampHeight-(36*certScale),size:certFit(signerName,certificateBold,8.5*certScale),font:certificateBold});
  certificatePage.drawText(dateLine,{x:certTextX,y:certificateStampY+certificateStampHeight-(51*certScale),size:certFit(dateLine,certificateFont,8.2*certScale),font:certificateFont});
  certificatePage.drawText(roleLines[0],{x:certTextX,y:certificateStampY+(25*certScale),size:certFit(roleLines[0],certificateFont,8.5*certScale),font:certificateFont});
  if(roleLines[1])certificatePage.drawText(roleLines[1],{x:certTextX,y:certificateStampY+(12*certScale),size:certFit(roleLines[1],certificateFont,7.8*certScale),font:certificateFont});
  const bytes=await pdfDoc.save(),certificateBytes=await certificateDoc.save();
  const file=new File([bytes],'informe_preparado.pdf',{type:'application/pdf'}),certificateFile=new File([certificateBytes],'certificado_preparado.pdf',{type:'application/pdf'});
  const dt=new DataTransfer(),certificateDt=new DataTransfer();dt.items.add(file);certificateDt.items.add(certificateFile);
  document.getElementById('preparedPdf').files=dt.files;document.getElementById('preparedCertificate').files=certificateDt.files;document.getElementById('signId').value=reportId;document.getElementById('signForm').submit();
 }catch(e){
  button.disabled=false;button.textContent='Firmar y finalizar';
  if(typeof window.Swal!=='undefined')window.Swal.fire({title:'No fue posible firmar',text:e.message||'Ocurrió un error al firmar el informe.',icon:'error',confirmButtonText:'Entendido',confirmButtonColor:'#086374'});
  else window.alert(e.message||'No fue posible firmar el informe.');
 }
};
let bundleObjectUrl='';
document.querySelectorAll('[data-print-bundle]').forEach(button=>button.onclick=async()=>{
 const reportId=button.dataset.printBundle;
 const documents=[['REPORT','informe'],['BOLETA','boleta'],['CERTIFICATE','certificado'],['DECREE','decreto'],['AGREEMENT','convenio']];
 if(typeof window.Swal!=='undefined')window.Swal.fire({title:'Preparando expediente',text:'Uniendo informe, boleta, certificado, decreto y convenio.',allowOutsideClick:false,allowEscapeKey:false,showConfirmButton:false,didOpen:()=>window.Swal.showLoading()});
 try{
  const responses=await Promise.all(documents.map(item=>fetch(`director.php?action=view_document_pdf&report_id=${encodeURIComponent(reportId)}&type=${item[0]}`)));
  const missing=responses.map((response,index)=>response.ok?'':documents[index][1]).filter(Boolean);
  if(missing.length)throw new Error('Faltan documentos para completar el expediente: '+missing.join(', ')+'.');
  const merged=await PDFLib.PDFDocument.create();
  for(const response of responses){const source=await PDFLib.PDFDocument.load(await response.arrayBuffer());const pages=await merged.copyPages(source,source.getPageIndices());pages.forEach(page=>merged.addPage(page));}
  if(bundleObjectUrl)URL.revokeObjectURL(bundleObjectUrl);
  bundleObjectUrl=URL.createObjectURL(new Blob([await merged.save()],{type:'application/pdf'}));
  if(typeof window.Swal!=='undefined')window.Swal.close();
  openPdfViewer(bundleObjectUrl,'',false,'Expediente');
 }catch(error){
  const message=error.message||'No fue posible preparar el expediente.';
  if(typeof window.Swal!=='undefined')window.Swal.fire({title:'Expediente incompleto',text:message,icon:'warning',confirmButtonText:'Entendido',confirmButtonColor:'#086374'});else window.alert(message);
 }
});

document.querySelectorAll('[data-sign]').forEach(button=>button.onclick=()=>{
 viewerSignButton.dataset.reportId=button.dataset.sign;
 viewerSignButton.click();
});
</script></body></html>




