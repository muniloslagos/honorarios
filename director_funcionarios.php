<?php
declare(strict_types=1);

require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/db.php';

header('Content-Type: text/html; charset=UTF-8');

$auth = requireRole(ROLE_DIRECTOR);
$pdo = db();
$run = normalizeRun((string) ($auth['run'] ?? extractRunFromUserInfo((array) ($auth['user_info'] ?? [])) ?? ''));
$profileStmt = $pdo->prepare("SELECT dp.id,u.full_name FROM director_profiles dp INNER JOIN system_users u ON u.id=dp.system_user_id WHERE u.run=:run AND u.role='DIRECTOR' AND u.is_active=1 AND dp.is_active=1 LIMIT 1");
$profileStmt->execute(['run' => $run]);
$director = $profileStmt->fetch();
if ($director === false) {
    http_response_code(403);
    exit('El perfil de director no está habilitado.');
}

function directorStaffFilePath(string $storedPath): string
{
    $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $storedPath);
    $candidate = realpath(__DIR__ . DIRECTORY_SEPARATOR . $relative);
    $root = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads');
    if ($candidate === false || $root === false || !str_starts_with($candidate, $root . DIRECTORY_SEPARATOR) || !is_file($candidate)) {
        throw new RuntimeException('El archivo no está disponible.');
    }
    return $candidate;
}

function outputDirectorStaffPdf(array $document, string $fallbackName): never
{
    $path = directorStaffFilePath((string) $document['stored_path']);
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) ($document['original_name'] ?: $fallbackName)) ?: $fallbackName;
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

$action = (string) ($_GET['action'] ?? '');
$documentId = (int) ($_GET['id'] ?? 0);
if ($documentId > 0 && in_array($action, ['view_decree', 'view_agreement', 'view_report'], true)) {
    try {
        if ($action === 'view_decree') {
            $stmt = $pdo->prepare("SELECT d.pdf_original_name AS original_name,d.pdf_path AS stored_path
                                   FROM decrees d INNER JOIN system_users u ON u.id=d.honorario_user_id
                                   INNER JOIN director_directions dd ON dd.direction_id=u.direction_id
                                   WHERE d.id=:id AND dd.director_profile_id=:profile AND u.role='HONORARIO' AND u.is_active=1 AND d.pdf_path IS NOT NULL LIMIT 1");
            $fallback = 'decreto.pdf';
        } elseif ($action === 'view_agreement') {
            $stmt = $pdo->prepare("SELECT a.pdf_original_name AS original_name,a.pdf_path AS stored_path
                                   FROM agreements a INNER JOIN system_users u ON u.id=a.honorario_user_id
                                   INNER JOIN director_directions dd ON dd.direction_id=u.direction_id
                                   WHERE a.id=:id AND dd.director_profile_id=:profile AND u.role='HONORARIO' AND u.is_active=1 AND a.pdf_path IS NOT NULL LIMIT 1");
            $fallback = 'convenio.pdf';
        } else {
            $stmt = $pdo->prepare("SELECT f.original_name,f.stored_path
                                   FROM monthly_reports r INNER JOIN system_users u ON u.id=r.honorario_user_id
                                   INNER JOIN director_directions dd ON dd.direction_id=u.direction_id
                                   INNER JOIN monthly_report_files f ON f.id=(SELECT MAX(f2.id) FROM monthly_report_files f2 WHERE f2.report_id=r.id AND f2.file_type='RESPALDO')
                                   WHERE r.id=:id AND dd.director_profile_id=:profile AND u.role='HONORARIO' AND u.is_active=1 LIMIT 1");
            $fallback = 'informe.pdf';
        }
        $stmt->execute(['id' => $documentId, 'profile' => $director['id']]);
        $document = $stmt->fetch();
        if ($document === false) {
            throw new RuntimeException('El documento no pertenece a un funcionario de tus direcciones.');
        }
        outputDirectorStaffPdf($document, $fallback);
    } catch (Throwable $e) {
        http_response_code(404);
        echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        exit;
    }
}

$directionsStmt = $pdo->prepare("SELECT d.id,d.name,dd.assignment_type
                                 FROM director_directions dd INNER JOIN directions d ON d.id=dd.direction_id
                                 WHERE dd.director_profile_id=:profile AND d.is_active=1
                                 ORDER BY (dd.assignment_type='PRINCIPAL') DESC,dd.administrative_order,d.name");
$directionsStmt->execute(['profile' => $director['id']]);
$directions = $directionsStmt->fetchAll();
$allowedDirectionIds = array_map(static fn(array $row): int => (int) $row['id'], $directions);
$selectedDirection = (int) ($_GET['direction_id'] ?? ($allowedDirectionIds[0] ?? 0));
if (!in_array($selectedDirection, $allowedDirectionIds, true)) {
    $selectedDirection = (int) ($allowedDirectionIds[0] ?? 0);
}

$staff = [];
if ($selectedDirection > 0) {
    $staffStmt = $pdo->prepare("SELECT u.id,u.run,u.full_name,u.email,u.profession_experience,d.name AS direction_name
                                FROM system_users u INNER JOIN directions d ON d.id=u.direction_id
                                INNER JOIN director_directions dd ON dd.direction_id=u.direction_id AND dd.director_profile_id=:profile
                                WHERE u.role='HONORARIO' AND u.is_active=1 AND u.direction_id=:direction
                                ORDER BY u.full_name");
    $staffStmt->execute(['profile' => $director['id'], 'direction' => $selectedDirection]);
    $staff = $staffStmt->fetchAll();
}

$selectedStaff = null;
$decrees = [];
$agreements = [];
$reports = [];
$staffId = (int) ($_GET['user_id'] ?? 0);
if ($staffId > 0) {
    $detailStmt = $pdo->prepare("SELECT u.id,u.run,u.full_name,u.email,u.profession_experience,d.name AS direction_name
                                 FROM system_users u INNER JOIN directions d ON d.id=u.direction_id
                                 INNER JOIN director_directions dd ON dd.direction_id=u.direction_id AND dd.director_profile_id=:profile
                                 WHERE u.id=:user AND u.role='HONORARIO' AND u.is_active=1 LIMIT 1");
    $detailStmt->execute(['profile' => $director['id'], 'user' => $staffId]);
    $selectedStaff = $detailStmt->fetch() ?: null;
    if ($selectedStaff !== null) {
        $stmt = $pdo->prepare('SELECT id,decree_number,decree_date,pdf_path FROM decrees WHERE honorario_user_id=:user ORDER BY decree_date DESC,id DESC');
        $stmt->execute(['user' => $staffId]);
        $decrees = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT id,agreement_number,agreement_date,start_date,end_date,status,pdf_path FROM agreements WHERE honorario_user_id=:user ORDER BY agreement_date DESC,id DESC');
        $stmt->execute(['user' => $staffId]);
        $agreements = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT r.id,r.report_month,r.report_year,r.source_type,r.status,r.submitted_at,
                               EXISTS(SELECT 1 FROM monthly_report_files f WHERE f.report_id=r.id AND f.file_type='RESPALDO') AS has_pdf
                               FROM monthly_reports r WHERE r.honorario_user_id=:user ORDER BY r.report_year DESC,r.report_month DESC,r.id DESC");
        $stmt->execute(['user' => $staffId]);
        $reports = $stmt->fetchAll();
    }
}

function reportStatusLabel(string $status): string
{
    return [
        'BORRADOR' => 'Borrador',
        'ENVIADO' => 'Pendiente de firma',
        'OBSERVADO' => 'Observado',
        'RECHAZADO' => 'Rechazado',
        'APROBADO' => 'Finalizado',
    ][$status] ?? $status;
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Funcionarios de la dirección</title>
<style>
:root{--p:#0b7285;--bg:#f3f7fb;--line:#dbe7f1;--text:#17324a;--muted:#60778b}
*{box-sizing:border-box}body{margin:0;font-family:Segoe UI,sans-serif;background:var(--bg);color:var(--text)}
.shell{display:grid;grid-template-columns:280px 1fr;min-height:100vh}.side{background:#fff;border-right:1px solid var(--line);padding:24px}
.nav{display:block;padding:12px;margin:8px 0;border:1px solid var(--line);border-radius:10px;text-decoration:none;color:var(--text)}
.nav.active{background:#eaf6f8;border-color:var(--p)}.main{padding:28px}.filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}
.filter,.btn{display:inline-block;border:1px solid var(--line);border-radius:9px;padding:9px 13px;background:#fff;color:var(--text);text-decoration:none;font-weight:700}
.filter.active,.btn{background:var(--p);border-color:var(--p);color:#fff}.btn.light{background:#eef4f8;border-color:#eef4f8;color:var(--text)}
.card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px;margin-bottom:12px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:12px}
.muted{color:var(--muted)}.documents{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px}.document-list{margin:0;padding:0;list-style:none}
.document-list li{padding:11px 0;border-bottom:1px solid var(--line)}.document-list li:last-child{border-bottom:0}.status{display:inline-block;background:#eaf6f8;border-radius:20px;padding:3px 9px;font-size:.85rem}
.actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.back{margin-bottom:18px}@media(max-width:800px){.shell{display:block}.main{padding:16px}}
</style>
</head>
<body>
<div class="shell">
<aside class="side">
    <h2>Buzones de documentos</h2>
    <p><?=htmlspecialchars((string) $director['full_name'], ENT_QUOTES, 'UTF-8')?></p>
    <a class="nav" href="director.php">Informes</a>
    <a class="nav active" href="director_funcionarios.php">Funcionarios</a>
    <a class="nav" href="logout.php">Cerrar sesión</a>
</aside>
<main class="main">
    <?php if($selectedStaff !== null): ?>
        <a class="btn light back" href="director_funcionarios.php?direction_id=<?=$selectedDirection?>">← Volver a funcionarios</a>
        <section class="card">
            <h1><?=htmlspecialchars((string) $selectedStaff['full_name'], ENT_QUOTES, 'UTF-8')?></h1>
            <p><strong>RUN:</strong> <?=htmlspecialchars((string) $selectedStaff['run'], ENT_QUOTES, 'UTF-8')?></p>
            <p><strong>Correo:</strong> <?=htmlspecialchars((string) ($selectedStaff['email'] ?: 'No registrado'), ENT_QUOTES, 'UTF-8')?></p>
            <p><strong>Dirección:</strong> <?=htmlspecialchars((string) $selectedStaff['direction_name'], ENT_QUOTES, 'UTF-8')?></p>
            <?php if(!empty($selectedStaff['profession_experience'])): ?><p><strong>Profesión o experiencia:</strong> <?=htmlspecialchars((string) $selectedStaff['profession_experience'], ENT_QUOTES, 'UTF-8')?></p><?php endif; ?>
        </section>
        <div class="documents">
            <section class="card">
                <h2>Decretos</h2>
                <?php if(!$decrees): ?><p class="muted">No hay decretos registrados.</p><?php endif; ?>
                <ul class="document-list">
                <?php foreach($decrees as $item): ?><li>
                    <strong><?=htmlspecialchars((string) $item['decree_number'], ENT_QUOTES, 'UTF-8')?></strong><br>
                    <span class="muted"><?=htmlspecialchars((string) $item['decree_date'], ENT_QUOTES, 'UTF-8')?></span>
                    <?php if(!empty($item['pdf_path'])): ?><br><a href="?action=view_decree&amp;id=<?=(int) $item['id']?>" target="_blank">Ver PDF</a><?php endif; ?>
                </li><?php endforeach; ?>
                </ul>
            </section>
            <section class="card">
                <h2>Convenios</h2>
                <?php if(!$agreements): ?><p class="muted">No hay convenios registrados.</p><?php endif; ?>
                <ul class="document-list">
                <?php foreach($agreements as $item): ?><li>
                    <strong><?=htmlspecialchars((string) $item['agreement_number'], ENT_QUOTES, 'UTF-8')?></strong>
                    <span class="status"><?=htmlspecialchars((string) $item['status'], ENT_QUOTES, 'UTF-8')?></span><br>
                    <span class="muted"><?=htmlspecialchars((string) $item['start_date'], ENT_QUOTES, 'UTF-8')?> al <?=htmlspecialchars((string) $item['end_date'], ENT_QUOTES, 'UTF-8')?></span>
                    <?php if(!empty($item['pdf_path'])): ?><br><a href="?action=view_agreement&amp;id=<?=(int) $item['id']?>" target="_blank">Ver PDF</a><?php endif; ?>
                </li><?php endforeach; ?>
                </ul>
            </section>
            <section class="card">
                <h2>Informes</h2>
                <?php if(!$reports): ?><p class="muted">No hay informes registrados.</p><?php endif; ?>
                <ul class="document-list">
                <?php foreach($reports as $item): ?><li>
                    <strong><?=(int) $item['report_month']?>/<?=(int) $item['report_year']?></strong>
                    <span class="status"><?=htmlspecialchars(reportStatusLabel((string) $item['status']), ENT_QUOTES, 'UTF-8')?></span><br>
                    <span class="muted"><?=$item['source_type'] === 'MANUAL' ? 'Manual' : 'Por convenio'?></span>
                    <?php if((int) $item['has_pdf'] === 1): ?><br><a href="?action=view_report&amp;id=<?=(int) $item['id']?>" target="_blank">Ver PDF</a><?php endif; ?>
                </li><?php endforeach; ?>
                </ul>
            </section>
            <section class="card">
                <h2>Certificados</h2>
                <p class="muted">No hay certificados registrados. Esta sección queda preparada para el módulo de certificados que se incorporará más adelante.</p>
            </section>
        </div>
    <?php else: ?>
        <h1>Funcionarios activos</h1>
        <p class="muted">Personal a honorarios asignado a las direcciones que tienes habilitadas.</p>
        <nav class="filters" aria-label="Filtrar por dirección">
            <?php foreach($directions as $direction): ?>
                <a class="filter <?=$selectedDirection === (int) $direction['id'] ? 'active' : ''?>" href="?direction_id=<?=(int) $direction['id']?>">
                    <?=htmlspecialchars((string) $direction['name'], ENT_QUOTES, 'UTF-8')?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php if(!$staff): ?><div class="card">No hay funcionarios activos asignados a esta dirección.</div><?php endif; ?>
        <div class="grid">
            <?php foreach($staff as $person): ?>
                <article class="card">
                    <h2><?=htmlspecialchars((string) $person['full_name'], ENT_QUOTES, 'UTF-8')?></h2>
                    <p><strong>RUN:</strong> <?=htmlspecialchars((string) $person['run'], ENT_QUOTES, 'UTF-8')?></p>
                    <p><strong>Correo:</strong> <?=htmlspecialchars((string) ($person['email'] ?: 'No registrado'), ENT_QUOTES, 'UTF-8')?></p>
                    <a class="btn" href="?direction_id=<?=$selectedDirection?>&amp;user_id=<?=(int) $person['id']?>">Ver documentos</a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
</div>
</body>
</html>
