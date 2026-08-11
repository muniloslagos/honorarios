<?php
declare(strict_types=1);

require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/honorario_data.php';

$authUser = requireRole(ROLE_HONORARIO);
$dbUser = ensureHonorarioDbUser($authUser);
$pdo = db();

$success = '';
$error = '';
$showCreateAgreementForm = isset($_POST['create_agreement']) || isset($_POST['create_decree_inline']);

function resolveAgreementStatus(string $startDate, string $endDate): string
{
    try {
        $today = new DateTimeImmutable('today');
        $start = new DateTimeImmutable($startDate);
        $end = new DateTimeImmutable($endDate);
    } catch (Throwable $e) {
        return 'VIGENTE';
    }

    if ($today < $start) {
        return 'PENDIENTE_FIRMA';
    }

    if ($today > $end) {
        return 'NO_VIGENTE';
    }

    return 'VIGENTE';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['create_decree_inline'])) {
            $decreeNumber = trim((string) ($_POST['decree_number_inline'] ?? ''));
            $decreeDate = trim((string) ($_POST['decree_date_inline'] ?? ''));

            if ($decreeNumber === '' || $decreeDate === '') {
                throw new RuntimeException('Completa numero y fecha del decreto.');
            }

            $decreePdf = uploadPdf($_FILES['decree_pdf_inline'] ?? [], 'decrees/' . $dbUser['run']);

            $stmt = $pdo->prepare('INSERT INTO decrees (honorario_user_id, decree_number, decree_date, pdf_original_name, pdf_path, created_by_user_id)
                                   VALUES (:uid,:num,:dt,:pdfn,:pdfp,:actor)
                                   ON DUPLICATE KEY UPDATE decree_date=VALUES(decree_date), pdf_original_name=VALUES(pdf_original_name), pdf_path=VALUES(pdf_path)');
            $stmt->execute([
                'uid' => $dbUser['id'],
                'num' => $decreeNumber,
                'dt' => $decreeDate,
                'pdfn' => $decreePdf['original_name'] ?? null,
                'pdfp' => $decreePdf['stored_path'] ?? null,
                'actor' => $dbUser['id'],
            ]);

            $success = 'Decreto agregado desde modal.';
        }

        if (isset($_POST['create_agreement'])) {
            $agreementNumber = trim((string) ($_POST['agreement_number'] ?? ''));
            $agreementDate = trim((string) ($_POST['agreement_date'] ?? ''));
            $startDate = trim((string) ($_POST['start_date'] ?? ''));
            $endDate = trim((string) ($_POST['end_date'] ?? ''));
            $installments = trim((string) ($_POST['installments_total'] ?? ''));
            $programItem = trim((string) ($_POST['program_item'] ?? ''));
            $professionExperience = trim((string) ($_POST['profession_experience'] ?? ''));
            $supervisionUnit = trim((string) ($_POST['supervision_unit'] ?? ''));
            $decreeId = trim((string) ($_POST['decree_id'] ?? ''));

            $functionItems = $_POST['functions_items'] ?? [];
            $functionsList = [];
            if (is_array($functionItems)) {
                foreach ($functionItems as $item) {
                    $text = trim((string) $item);
                    if ($text !== '') {
                        $functionsList[] = $text;
                    }
                }
            }

            if (count($functionsList) === 0) {
                $functionsRaw = trim((string) ($_POST['functions_text'] ?? ''));
                $legacyLines = preg_split('/\r\n|\r|\n/', $functionsRaw) ?: [];
                foreach ($legacyLines as $line) {
                    $text = trim((string) $line);
                    if ($text !== '') {
                        $functionsList[] = $text;
                    }
                }
            }

            if ($agreementNumber === '' || $agreementDate === '' || $startDate === '' || $endDate === '' || $programItem === '' || $professionExperience === '' || $supervisionUnit === '') {
                throw new RuntimeException('Completa los campos obligatorios del convenio.');
            }

            $status = resolveAgreementStatus($startDate, $endDate);

            $agreementPdf = uploadPdf($_FILES['agreement_pdf'] ?? [], 'agreements/' . $dbUser['run']);

            $stmt = $pdo->prepare('INSERT INTO agreements (
                    honorario_user_id, agreement_number, agreement_date, start_date, end_date, installments_total,
                        program_item, profession_experience, supervision_unit, decree_id, pdf_original_name, pdf_path, status, created_by_user_id
                ) VALUES (
                        :uid, :num, :ad, :sd, :ed, :ins, :prog, :profession, :supervision, :decree, :pdfn, :pdfp, :st, :actor
                )
                ON DUPLICATE KEY UPDATE
                    agreement_date=VALUES(agreement_date),
                    start_date=VALUES(start_date),
                    end_date=VALUES(end_date),
                    installments_total=VALUES(installments_total),
                    program_item=VALUES(program_item),
                        profession_experience=VALUES(profession_experience),
                        supervision_unit=VALUES(supervision_unit),
                    decree_id=VALUES(decree_id),
                    pdf_original_name=VALUES(pdf_original_name),
                    pdf_path=VALUES(pdf_path),
                    status=VALUES(status)');

            $stmt->execute([
                'uid' => $dbUser['id'],
                'num' => $agreementNumber,
                'ad' => $agreementDate,
                'sd' => $startDate,
                'ed' => $endDate,
                'ins' => $installments !== '' ? (int) $installments : null,
                'prog' => $programItem,
                'profession' => $professionExperience,
                'supervision' => $supervisionUnit,
                'decree' => $decreeId !== '' ? (int) $decreeId : null,
                'pdfn' => $agreementPdf['original_name'] ?? null,
                'pdfp' => $agreementPdf['stored_path'] ?? null,
                'st' => $status,
                'actor' => $dbUser['id'],
            ]);

            $agFind = $pdo->prepare('SELECT id FROM agreements WHERE honorario_user_id = :uid AND agreement_number = :num LIMIT 1');
            $agFind->execute(['uid' => $dbUser['id'], 'num' => $agreementNumber]);
            $agreement = $agFind->fetch();

            if ($agreement !== false) {
                $agreementId = (int) $agreement['id'];
                $pdo->prepare('DELETE FROM agreement_functions WHERE agreement_id = :id')->execute(['id' => $agreementId]);

                $order = 1;
                foreach ($functionsList as $text) {
                    $insFn = $pdo->prepare('INSERT INTO agreement_functions (agreement_id, function_text, sort_order) VALUES (:aid, :txt, :ord)');
                    $insFn->execute(['aid' => $agreementId, 'txt' => $text, 'ord' => $order]);
                    $order++;
                }
            }

            $success = 'Convenio guardado correctamente.';
            $showCreateAgreementForm = false;
        }

        if (isset($_POST['update_agreement_functions'])) {
            $agreementId = (int) ($_POST['agreement_id'] ?? 0);
            if ($agreementId <= 0) {
                throw new RuntimeException('Convenio invalido para actualizar funciones.');
            }

            $agreementOwner = $pdo->prepare('SELECT id FROM agreements WHERE id = :id AND honorario_user_id = :uid LIMIT 1');
            $agreementOwner->execute(['id' => $agreementId, 'uid' => $dbUser['id']]);
            if ($agreementOwner->fetch() === false) {
                throw new RuntimeException('No tienes permisos para editar funciones de este convenio.');
            }

            $idsRaw = $_POST['function_ids'] ?? [];
            $textsRaw = $_POST['function_texts'] ?? [];
            if (!is_array($idsRaw) || !is_array($textsRaw)) {
                throw new RuntimeException('Formato de funciones invalido.');
            }

            $submittedRows = [];
            $len = max(count($idsRaw), count($textsRaw));
            for ($i = 0; $i < $len; $i++) {
                $rowId = isset($idsRaw[$i]) ? (int) $idsRaw[$i] : 0;
                $rowText = isset($textsRaw[$i]) ? trim((string) $textsRaw[$i]) : '';
                if ($rowText === '') {
                    continue;
                }
                $submittedRows[] = ['id' => $rowId, 'text' => $rowText];
            }

            $currentStmt = $pdo->prepare('SELECT id, function_text FROM agreement_functions WHERE agreement_id = :aid ORDER BY sort_order ASC, id ASC');
            $currentStmt->execute(['aid' => $agreementId]);
            $currentRows = $currentStmt->fetchAll();
            $currentById = [];
            foreach ($currentRows as $row) {
                $currentById[(int) $row['id']] = (string) $row['function_text'];
            }

            $submittedExistingIds = [];
            foreach ($submittedRows as $row) {
                if ($row['id'] > 0) {
                    $submittedExistingIds[] = $row['id'];
                }
            }

            $deletedIds = array_values(array_diff(array_keys($currentById), $submittedExistingIds));
            if (count($deletedIds) > 0) {
                $deletedTexts = [];
                foreach ($deletedIds as $deletedId) {
                    $deletedTexts[] = $currentById[$deletedId];
                }

                if (count($deletedTexts) > 0) {
                    $inTexts = implode(',', array_fill(0, count($deletedTexts), '?'));
                    $checkSql = 'SELECT 1
                                 FROM monthly_report_activities a
                                 JOIN monthly_reports r ON r.id = a.report_id
                                 WHERE r.honorario_user_id = ?
                                   AND r.agreement_id = ?
                                   AND a.activity_description IN (' . $inTexts . ')
                                 LIMIT 1';
                    $checkStmt = $pdo->prepare($checkSql);
                    $checkStmt->execute(array_merge([$dbUser['id'], $agreementId], $deletedTexts));
                    if ($checkStmt->fetch() !== false) {
                        throw new RuntimeException('No puedes eliminar funciones que ya estan relacionadas a actividades de informes.');
                    }
                }
            }

            $pdo->beginTransaction();
            try {
                $updateStmt = $pdo->prepare('UPDATE agreement_functions SET function_text = :txt, sort_order = :ord WHERE id = :id AND agreement_id = :aid');
                $insertStmt = $pdo->prepare('INSERT INTO agreement_functions (agreement_id, function_text, sort_order) VALUES (:aid, :txt, :ord)');
                $order = 1;
                foreach ($submittedRows as $row) {
                    if ($row['id'] > 0 && isset($currentById[$row['id']])) {
                        $updateStmt->execute([
                            'txt' => $row['text'],
                            'ord' => $order,
                            'id' => $row['id'],
                            'aid' => $agreementId,
                        ]);
                    } else {
                        $insertStmt->execute([
                            'aid' => $agreementId,
                            'txt' => $row['text'],
                            'ord' => $order,
                        ]);
                    }
                    $order++;
                }

                if (count($deletedIds) > 0) {
                    $inDelete = implode(',', array_fill(0, count($deletedIds), '?'));
                    $deleteSql = 'DELETE FROM agreement_functions WHERE agreement_id = ? AND id IN (' . $inDelete . ')';
                    $deleteStmt = $pdo->prepare($deleteSql);
                    $deleteStmt->execute(array_merge([$agreementId], $deletedIds));
                }

                $pdo->commit();
            } catch (Throwable $txe) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $txe;
            }

            $success = 'Funciones del convenio actualizadas correctamente.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$decreeStmt = $pdo->prepare('SELECT id, decree_number, decree_date FROM decrees WHERE honorario_user_id = :uid ORDER BY decree_date DESC');
$decreeStmt->execute(['uid' => $dbUser['id']]);
$decrees = $decreeStmt->fetchAll();

$agreementsStmt = $pdo->prepare('SELECT a.*, d.decree_number FROM agreements a LEFT JOIN decrees d ON d.id = a.decree_id WHERE a.honorario_user_id = :uid ORDER BY a.start_date DESC, a.id DESC');
$agreementsStmt->execute(['uid' => $dbUser['id']]);
$agreements = $agreementsStmt->fetchAll();

$functionsByAgreement = [];
if (count($agreements) > 0) {
    $ids = array_map(static fn(array $a): int => (int) $a['id'], $agreements);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $fnStmt = $pdo->prepare("SELECT agreement_id, id, function_text FROM agreement_functions WHERE agreement_id IN ($in) ORDER BY sort_order ASC, id ASC");
    $fnStmt->execute($ids);
    foreach ($fnStmt->fetchAll() as $row) {
        $aid = (int) $row['agreement_id'];
        if (!isset($functionsByAgreement[$aid])) {
            $functionsByAgreement[$aid] = [];
        }
        $functionsByAgreement[$aid][] = [
            'id' => (int) $row['id'],
            'text' => (string) $row['function_text'],
        ];
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Convenios | Honorarios</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }

        :root {
            --c-bg: #f2f6fb;
            --c-surface: #ffffff;
            --c-text: #12324f;
            --c-muted: #5a7690;
            --c-border: #d8e4ef;
            --c-primary: #0e7893;
            --c-primary-dark: #0a5f74;
            --c-soft: #eef6fb;
            --c-success-bg: #edfbf3;
            --c-success-text: #17623f;
            --c-error-bg: #fff1f1;
            --c-error-text: #992020;
            --radius-lg: 16px;
            --radius-md: 10px;
            --shadow-card: 0 14px 38px rgba(14, 71, 113, .08);
            --sidebar-width: 280px;
        }

        body {
            margin: 0;
            color: var(--c-text);
            background:
                radial-gradient(1200px 400px at -10% -10%, #d7e9f6 0%, transparent 60%),
                radial-gradient(1000px 380px at 120% -15%, #e4f3f3 0%, transparent 58%),
                var(--c-bg);
            font-family: "Segoe UI", system-ui, sans-serif;
        }

        .shell {
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
        }

        .sidebar {
            width: var(--sidebar-width);
            min-height: 100vh;
            position: sticky;
            top: 0;
            align-self: flex-start;
            padding: 22px 18px;
            border-right: 1px solid var(--c-border);
            background: rgba(255,255,255,.8);
            backdrop-filter: blur(10px);
            overflow-y: auto;
        }

        .sidebar-brand {
            margin: 0 0 4px;
            font-size: 1.03rem;
            font-weight: 800;
            color: var(--c-primary-dark);
        }

        .sidebar-subtitle {
            margin: 0 0 16px;
            color: var(--c-muted);
            font-size: .92rem;
        }

        .sidebar-menu {
            display: grid;
            gap: 8px;
        }

        .sidebar-link {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 11px 12px;
            border-radius: 12px;
            border: 1px solid var(--c-border);
            background: #fff;
            color: var(--c-text);
            text-decoration: none;
            font-weight: 700;
            font-size: .92rem;
        }

        .sidebar-link.active {
            background: linear-gradient(120deg, #ecf8fa, #f8fbff);
            border-color: #cfe5ef;
            color: var(--c-primary-dark);
        }

        .sidebar-tag {
            flex-shrink: 0;
            font-size: .74rem;
            font-weight: 800;
            color: var(--c-muted);
            border: 1px solid var(--c-border);
            border-radius: 999px;
            padding: 2px 8px;
            white-space: nowrap;
        }

        .content-shell {
            flex: 1;
            min-width: 0;
        }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 30;
            backdrop-filter: blur(8px);
            background: rgba(242, 246, 251, .9);
            border-bottom: 1px solid var(--c-border);
        }

        .topbar-inner {
            max-width: 1200px;
            margin: 0 auto;
            padding: 14px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
        }

        .brand {
            font-size: 1.05rem;
            font-weight: 800;
            letter-spacing: .02em;
            color: var(--c-primary-dark);
            text-decoration: none;
        }

        .actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn {
            appearance: none;
            border: 0;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 10px 14px;
            border-radius: var(--radius-md);
            font-size: .9rem;
            font-weight: 700;
            cursor: pointer;
            transition: transform .08s ease, background .16s ease;
            white-space: nowrap;
        }

        .btn:active { transform: translateY(1px); }

        .btn-primary { background: var(--c-primary); color: #fff; }
        .btn-primary:hover { background: var(--c-primary-dark); }

        .btn-soft {
            background: var(--c-soft);
            color: #244d72;
            border: 1px solid #d7e7f5;
        }
        .btn-soft:hover { background: #e4f1fb; }

        .btn-link {
            background: transparent;
            color: var(--c-primary-dark);
            border: 1px dashed #b8d2e7;
            padding: 8px 12px;
            font-size: .82rem;
        }

        .page {
            max-width: 1200px;
            margin: 24px auto 36px;
            padding: 0 20px;
        }

        .page-header {
            margin-bottom: 14px;
        }

        .page-title {
            margin: 0;
            font-size: clamp(1.55rem, 2vw, 2rem);
            font-weight: 800;
        }

        .page-subtitle {
            margin: 7px 0 0;
            color: var(--c-muted);
            font-size: .93rem;
            max-width: 780px;
            line-height: 1.45;
        }

        .alert {
            border-radius: var(--radius-md);
            padding: 11px 13px;
            margin: 14px 0;
            font-size: .9rem;
            border: 1px solid transparent;
        }

        .alert-ok {
            background: var(--c-success-bg);
            border-color: #bceace;
            color: var(--c-success-text);
        }

        .alert-err {
            background: var(--c-error-bg);
            border-color: #f2c5c5;
            color: var(--c-error-text);
        }

        .agreement-create-card { display: none; }
        .agreement-create-card.is-visible { display: block; }

        .card {
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
            margin-top: 18px;
            overflow: hidden;
        }

        .card-head {
            padding: 16px 18px;
            border-bottom: 1px solid var(--c-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .card-head h2 {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 800;
        }

        .card-body { padding: 18px; }

        .form-hint {
            margin: 0 0 14px;
            color: var(--c-muted);
            font-size: .9rem;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(180px, 1fr));
            gap: 12px;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: repeat(2, minmax(240px, 1fr));
            gap: 12px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .field label {
            font-size: .76rem;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--c-muted);
            font-weight: 700;
        }

        input, select, textarea {
            width: 100%;
            border: 1.5px solid #c8d8e6;
            border-radius: var(--radius-md);
            padding: 10px 11px;
            color: var(--c-text);
            background: #fff;
            font-size: .92rem;
            outline: none;
            transition: border-color .16s ease, box-shadow .16s ease;
            font-family: inherit;
        }

        input:focus, select:focus, textarea:focus {
            border-color: var(--c-primary);
            box-shadow: 0 0 0 3px rgba(14, 120, 147, .16);
        }

        textarea { min-height: 110px; resize: vertical; }

        .decree-helper {
            margin-top: 6px;
        }

        .form-footer {
            margin-top: 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        thead th {
            background: #f7fbff;
            color: #3c5f7f;
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .06em;
            border-bottom: 1px solid var(--c-border);
            padding: 12px 11px;
            text-align: left;
            white-space: nowrap;
        }

        tbody td {
            border-bottom: 1px solid #edf3f8;
            padding: 12px 11px;
            font-size: .9rem;
            vertical-align: top;
        }

        tbody tr:hover td { background: #fbfdff; }

        .agreement-title {
            margin: 0 0 4px;
            font-size: .95rem;
            font-weight: 800;
            color: #103556;
        }

        .agreement-meta {
            margin: 0;
            color: #416280;
            line-height: 1.35;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            border-radius: 99px;
            padding: 2px 9px;
            font-size: .75rem;
            font-weight: 800;
            letter-spacing: .02em;
        }

        .status-vigente { background: #e9fbf3; color: #147348; }
        .status-pendiente { background: #fff8e6; color: #9b6100; }
        .status-no-vigente { background: #fdeeee; color: #9f2828; }

        .list-chip {
            display: inline-block;
            background: #eff6ff;
            border: 1px solid #d7e5f5;
            border-radius: 999px;
            padding: 3px 10px;
            margin: 2px;
            font-size: .81rem;
            color: #2a4e73;
            line-height: 1.22;
        }

        .empty {
            color: var(--c-muted);
            text-align: center;
            padding: 22px 10px;
        }

        .pdf-link {
            font-weight: 700;
            color: var(--c-primary-dark);
            text-decoration: none;
        }

        .pdf-link:hover { text-decoration: underline; }

        .fn-preview {
            display: grid;
            gap: 8px;
            padding: 10px;
            border: 1px dashed #b9cfe0;
            border-radius: var(--radius-md);
            background: #fafdff;
            min-height: 76px;
        }

        .fn-empty {
            margin: 0;
            color: var(--c-muted);
            font-size: .85rem;
        }

        .fn-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }

        .fn-row input[type="text"] {
            flex: 1;
        }

        .btn-danger {
            background: #ffeaea;
            color: #972b2b;
            border: 1px solid #efbcbc;
            padding: 8px 10px;
            border-radius: var(--radius-md);
            font-size: .8rem;
            font-weight: 700;
            cursor: pointer;
        }

        .btn-danger:hover {
            background: #ffd9d9;
        }

        .modal-bg {
            position: fixed;
            inset: 0;
            background: rgba(7, 24, 40, .5);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            z-index: 90;
        }

        .modal {
            background: #fff;
            border-radius: var(--radius-lg);
            max-width: 560px;
            width: 100%;
            border: 1px solid var(--c-border);
            box-shadow: 0 18px 44px rgba(6, 30, 58, .24);
            overflow: hidden;
        }

        .modal-head {
            padding: 14px 16px;
            border-bottom: 1px solid var(--c-border);
            background: #f8fbfe;
        }

        .modal-head h3 {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 800;
        }

        .modal-body { padding: 16px; }

        .modal-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 14px;
        }

        @media (max-width: 980px) {
            .grid { grid-template-columns: repeat(2, minmax(200px, 1fr)); }
            .grid-2 { grid-template-columns: 1fr; }
        }

        @media (max-width: 640px) {
            .page { padding: 0 14px; }
            .shell { display: block; }
            .sidebar {
                width: 100%;
                min-height: auto;
                position: relative;
                border-right: 0;
                border-bottom: 1px solid var(--c-border);
            }
            .topbar-inner { padding: 12px 14px; }
            .grid { grid-template-columns: 1fr; }
            .actions .btn { flex: 1 1 auto; }
            .card-head h2 { font-size: 1.08rem; }
        }
    </style>
</head>
<body>
    <div class="shell">
        <aside class="sidebar">
            <h2 class="sidebar-brand">Personal a Honorarios</h2>
            <p class="sidebar-subtitle">Panel del perfil Honorario</p>
            <nav class="sidebar-menu" aria-label="Menu principal">
                <a class="sidebar-link" href="dashboard.php">Inicio <span class="sidebar-tag">Home</span></a>
                <a class="sidebar-link active" href="convenios.php">Mis convenios <span class="sidebar-tag">Activo</span></a>
                <a class="sidebar-link" href="decretos.php">Mis decretos <span class="sidebar-tag">Activo</span></a>
                <a class="sidebar-link" href="informe_mensual.php">Informe mensual <span class="sidebar-tag">Prioridad</span></a>
                <a class="sidebar-link" href="#">Carga PDF firmado <span class="sidebar-tag">Prox.</span></a>
                <a class="sidebar-link" href="#">Historial <span class="sidebar-tag">Prox.</span></a>
            </nav>
        </aside>

        <div class="content-shell">
            <header class="topbar">
                <div class="topbar-inner">
                    <a class="brand" href="dashboard.php">Honorarios • Convenios</a>
                    <nav class="actions">
                        <a class="btn btn-soft" href="dashboard.php">Volver dashboard</a>
                        <a class="btn btn-soft" href="decretos.php">Ver decretos</a>
                        <button class="btn btn-primary" type="button" id="openAgreementForm">Agregar Convenio</button>
                    </nav>
                </div>
            </header>

            <main class="page">
                <div class="page-header">
                    <h1 class="page-title">Convenios</h1>
                    <p class="page-subtitle">Registra tus convenios, funciones y documentos de respaldo en una sola vista clara para seguimiento mensual.</p>
                </div>

            <?php if ($success !== ''): ?><div class="alert alert-ok"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="alert alert-err"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

            <section class="card">
            <div class="card-head">
                <h2>Mis convenios</h2>
                <span style="color: var(--c-muted); font-size: .84rem; font-weight: 700;">
                    <?php echo count($agreements); ?> convenio<?php echo count($agreements) !== 1 ? 's' : ''; ?>
                </span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Convenio</th>
                            <th>Vigencia</th>
                            <th>Decreto</th>
                            <th>Funciones</th>
                            <th>PDF</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($agreements as $a): ?>
                            <?php
                                $status = resolveAgreementStatus((string) $a['start_date'], (string) $a['end_date']);
                                $statusClass = $status === 'VIGENTE' ? 'status-vigente' : ($status === 'PENDIENTE_FIRMA' ? 'status-pendiente' : 'status-no-vigente');
                            ?>
                            <tr>
                                <td>
                                    <p class="agreement-title"><?php echo htmlspecialchars((string) $a['agreement_number'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    <p class="agreement-meta"><?php echo htmlspecialchars((string) $a['program_item'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    <p class="agreement-meta" style="margin-top: 6px;">
                                        <span class="status-badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </p>
                                </td>
                                <td><?php echo htmlspecialchars((string) $a['start_date'] . ' a ' . (string) $a['end_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) ($a['decree_number'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php $items = $functionsByAgreement[(int) $a['id']] ?? []; ?>
                                    <?php foreach ($items as $fn): ?>
                                        <span class="list-chip"><?php echo htmlspecialchars((string) $fn['text'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endforeach; ?>
                                    <?php if (count($items) === 0): ?>
                                        <span class="agreement-meta">Sin funciones registradas</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($a['pdf_path'])): ?>
                                        <a class="pdf-link" href="<?php echo htmlspecialchars((string) $a['pdf_path'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Ver PDF</a>
                                    <?php else: ?>
                                        <span class="agreement-meta">Sin archivo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php $items = $functionsByAgreement[(int) $a['id']] ?? []; ?>
                                    <button
                                        class="btn btn-link openEditFunctionsModal"
                                        type="button"
                                        data-agreement-id="<?php echo (int) $a['id']; ?>"
                                        data-agreement-number="<?php echo htmlspecialchars((string) $a['agreement_number'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-functions="<?php echo htmlspecialchars((string) json_encode($items, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
                                    >
                                        Editar funciones
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (count($agreements) === 0): ?>
                            <tr>
                                <td colspan="6" class="empty">No hay convenios registrados.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            </section>

            <section class="card agreement-create-card<?php echo $showCreateAgreementForm ? ' is-visible' : ''; ?>" id="agregar-convenio">
            <div class="card-head">
                <h2>Agregar convenio</h2>
            </div>
            <div class="card-body">
                <p class="form-hint">Completa los datos obligatorios y agrega funciones separadas por línea para reutilizarlas al generar informes mensuales.</p>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="create_agreement" value="1">

                    <div class="grid">
                        <div class="field">
                            <label>Numero convenio</label>
                            <input name="agreement_number" required>
                        </div>
                        <div class="field">
                            <label>Fecha convenio</label>
                            <input type="date" name="agreement_date" required>
                        </div>
                        <div class="field">
                            <label>Vigencia inicio</label>
                            <input type="date" name="start_date" required>
                        </div>
                        <div class="field">
                            <label>Vigencia fin</label>
                            <input type="date" name="end_date" required>
                        </div>

                        <div class="field">
                            <label>Cuotas</label>
                            <input type="number" min="1" name="installments_total" placeholder="Ej: 12">
                        </div>
                        <div class="field">
                            <label>Programa o item</label>
                            <input name="program_item" required placeholder="Ej: Programa de apoyo comunitario">
                        </div>
                        <div class="field">
                            <label>Profesión, oficio y/o experiencia</label>
                            <input name="profession_experience" required placeholder="Ej: Administrador Público">
                        </div>
                        <div class="field">
                            <label>Dirección o unidad de supervisión</label>
                            <input name="supervision_unit" required placeholder="Ej: Dirección de Desarrollo Comunitario">
                        </div>
                        <div class="field">
                            <label>N° decreto que lo aprueba</label>
                            <select name="decree_id">
                                <option value="">-- Seleccionar --</option>
                                <?php foreach ($decrees as $d): ?>
                                    <option value="<?php echo (int) $d['id']; ?>"><?php echo htmlspecialchars((string) $d['decree_number'] . ' (' . (string) $d['decree_date'] . ')', ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="decree-helper">
                                <button class="btn btn-link" type="button" id="openDecreeModal">No existe? Agregar decreto</button>
                            </div>
                        </div>
                        <div class="field">
                            <label>Estado (automatico)</label>
                            <input type="text" value="Se calcula segun vigencia" readonly>
                        </div>
                    </div>

                    <div class="grid-2" style="margin-top:12px;">
                        <div class="field">
                            <label>Funciones del convenio</label>
                            <div class="fn-preview" id="createFunctionsPreview">
                                <p class="fn-empty">Aun no agregas funciones.</p>
                            </div>
                            <div id="createFunctionsHidden"></div>
                            <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
                                <button class="btn btn-link" type="button" id="openCreateFunctionsModal">Agregar funciones</button>
                            </div>
                        </div>
                        <div class="field">
                            <label>Documento PDF</label>
                            <input type="file" name="agreement_pdf" accept="application/pdf">
                        </div>
                    </div>

                    <div class="form-footer">
                        <button class="btn btn-primary" type="submit">Guardar convenio</button>
                        <button class="btn btn-soft" type="button" id="cancelAgreementForm">Cancelar</button>
                    </div>
                </form>
            </div>
                </section>
            </main>
        </div>
    </div>

    <div id="decreeModalBg" class="modal-bg" aria-hidden="true">
        <div class="modal">
            <div class="modal-head">
                <h3>Agregar decreto sin salir</h3>
            </div>
            <div class="modal-body">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="create_decree_inline" value="1">

                    <div class="field">
                        <label>N° decreto</label>
                        <input name="decree_number_inline" required>
                    </div>

                    <div class="field" style="margin-top:10px;">
                        <label>Fecha</label>
                        <input type="date" name="decree_date_inline" required>
                    </div>

                    <div class="field" style="margin-top:10px;">
                        <label>PDF decreto</label>
                        <input type="file" name="decree_pdf_inline" accept="application/pdf">
                    </div>

                    <div class="modal-actions">
                        <button class="btn btn-primary" type="submit">Guardar decreto</button>
                        <button class="btn btn-soft" type="button" id="closeDecreeModal">Cerrar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="createFunctionsModalBg" class="modal-bg" aria-hidden="true">
        <div class="modal">
            <div class="modal-head">
                <h3>Funciones del convenio</h3>
            </div>
            <div class="modal-body">
                <p class="form-hint">Agrega una funcion por campo. Puedes sumar o quitar filas segun necesites.</p>
                <div id="createFunctionsRows"></div>
                <div class="modal-actions">
                    <button class="btn btn-soft" type="button" id="addCreateFunctionRow">Agregar otra funcion</button>
                    <button class="btn btn-primary" type="button" id="saveCreateFunctions">Guardar funciones</button>
                    <button class="btn btn-soft" type="button" id="closeCreateFunctionsModal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <div id="editFunctionsModalBg" class="modal-bg" aria-hidden="true">
        <div class="modal">
            <div class="modal-head">
                <h3 id="editFunctionsTitle">Editar funciones</h3>
            </div>
            <div class="modal-body">
                <p class="form-hint">Puedes editar o agregar funciones. Si una funcion ya tiene actividades en informes, no podra eliminarse.</p>
                <form method="post" id="editFunctionsForm">
                    <input type="hidden" name="update_agreement_functions" value="1">
                    <input type="hidden" name="agreement_id" id="editAgreementId" value="0">
                    <div id="editFunctionsRows"></div>
                    <div class="modal-actions">
                        <button class="btn btn-soft" type="button" id="addEditFunctionRow">Agregar otra funcion</button>
                        <button class="btn btn-primary" type="submit">Guardar cambios</button>
                        <button class="btn btn-soft" type="button" id="closeEditFunctionsModal">Cerrar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        const open = document.getElementById('openDecreeModal');
        const close = document.getElementById('closeDecreeModal');
        const bg = document.getElementById('decreeModalBg');
        if (open && close && bg) {
            open.addEventListener('click', function () {
                bg.style.display = 'flex';
            });
            close.addEventListener('click', function () {
                bg.style.display = 'none';
            });
            bg.addEventListener('click', function (e) {
                if (e.target === bg) {
                    bg.style.display = 'none';
                }
            });
        }

        function createFunctionRow(value, hiddenId) {
            const row = document.createElement('div');
            row.className = 'fn-row';

            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = hiddenId ? 'function_ids[]' : '';
            idInput.value = hiddenId || '0';

            const input = document.createElement('input');
            input.type = 'text';
            input.value = value || '';
            input.placeholder = 'Describe una funcion...';

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn-danger';
            remove.textContent = 'Quitar';
            remove.addEventListener('click', () => {
                row.remove();
            });

            row.appendChild(idInput);
            row.appendChild(input);
            row.appendChild(remove);

            row.getValue = () => input.value.trim();
            row.getIdValue = () => idInput.value;
            row.bindForEdit = () => {
                idInput.name = 'function_ids[]';
                input.name = 'function_texts[]';
            };

            return row;
        }

        const agreementFormCard = document.getElementById('agregar-convenio');
        const openAgreementFormButton = document.getElementById('openAgreementForm');
        const cancelAgreementFormButton = document.getElementById('cancelAgreementForm');

        function setAgreementFormVisible(visible) {
            if (!agreementFormCard) return;
            agreementFormCard.classList.toggle('is-visible', visible);
            if (visible) {
                agreementFormCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
                const firstInput = agreementFormCard.querySelector('input:not([type="hidden"])');
                if (firstInput) setTimeout(() => firstInput.focus(), 350);
            }
        }

        if (openAgreementFormButton) {
            openAgreementFormButton.addEventListener('click', () => setAgreementFormVisible(true));
        }
        if (cancelAgreementFormButton) {
            cancelAgreementFormButton.addEventListener('click', () => setAgreementFormVisible(false));
        }
        const createModalBg = document.getElementById('createFunctionsModalBg');
        const createRows = document.getElementById('createFunctionsRows');
        const createHidden = document.getElementById('createFunctionsHidden');
        const createPreview = document.getElementById('createFunctionsPreview');
        const openCreateModal = document.getElementById('openCreateFunctionsModal');
        const closeCreateModal = document.getElementById('closeCreateFunctionsModal');
        const addCreateRow = document.getElementById('addCreateFunctionRow');
        const saveCreateRows = document.getElementById('saveCreateFunctions');

        function rebuildCreatePreview(values) {
            createPreview.innerHTML = '';
            if (values.length === 0) {
                const p = document.createElement('p');
                p.className = 'fn-empty';
                p.textContent = 'Aun no agregas funciones.';
                createPreview.appendChild(p);
                return;
            }
            values.forEach((txt) => {
                const chip = document.createElement('span');
                chip.className = 'list-chip';
                chip.textContent = txt;
                createPreview.appendChild(chip);
            });
        }

        function syncCreateHidden(values) {
            createHidden.innerHTML = '';
            values.forEach((txt) => {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'functions_items[]';
                hidden.value = txt;
                createHidden.appendChild(hidden);
            });
        }

        function currentCreateValues() {
            const values = [];
            const rows = createRows.querySelectorAll('.fn-row');
            rows.forEach((row) => {
                const v = row.getValue();
                if (v !== '') {
                    values.push(v);
                }
            });
            return values;
        }

        function openCreateFunctionsModal() {
            if (!createRows) {
                return;
            }
            if (createRows.children.length === 0) {
                createRows.appendChild(createFunctionRow('', 0));
            }
            createModalBg.style.display = 'flex';
        }

        if (openCreateModal && closeCreateModal && addCreateRow && saveCreateRows && createModalBg) {
            openCreateModal.addEventListener('click', openCreateFunctionsModal);
            closeCreateModal.addEventListener('click', () => {
                createModalBg.style.display = 'none';
            });
            addCreateRow.addEventListener('click', () => {
                createRows.appendChild(createFunctionRow('', 0));
            });
            saveCreateRows.addEventListener('click', () => {
                const values = currentCreateValues();
                syncCreateHidden(values);
                rebuildCreatePreview(values);
                createModalBg.style.display = 'none';
            });
            createModalBg.addEventListener('click', (e) => {
                if (e.target === createModalBg) {
                    createModalBg.style.display = 'none';
                }
            });

            rebuildCreatePreview([]);
            syncCreateHidden([]);
        }

        const editModalBg = document.getElementById('editFunctionsModalBg');
        const editRows = document.getElementById('editFunctionsRows');
        const editAgreementId = document.getElementById('editAgreementId');
        const editTitle = document.getElementById('editFunctionsTitle');
        const openEditButtons = document.querySelectorAll('.openEditFunctionsModal');
        const closeEditModal = document.getElementById('closeEditFunctionsModal');
        const addEditRow = document.getElementById('addEditFunctionRow');

        function addEditRowWithData(id, text) {
            const row = createFunctionRow(text, id);
            row.bindForEdit();
            editRows.appendChild(row);
        }

        if (editModalBg && editRows && editAgreementId && editTitle) {
            openEditButtons.forEach((btn) => {
                btn.addEventListener('click', () => {
                    const agreementId = btn.getAttribute('data-agreement-id') || '0';
                    const agreementNumber = btn.getAttribute('data-agreement-number') || 'Convenio';
                    const functionsRaw = btn.getAttribute('data-functions') || '[]';

                    let fnList = [];
                    try {
                        fnList = JSON.parse(functionsRaw);
                    } catch (e) {
                        fnList = [];
                    }

                    editRows.innerHTML = '';
                    editAgreementId.value = agreementId;
                    editTitle.textContent = 'Editar funciones - ' + agreementNumber;

                    if (fnList.length === 0) {
                        addEditRowWithData(0, '');
                    } else {
                        fnList.forEach((item) => {
                            const id = item && item.id ? Number(item.id) : 0;
                            const text = item && item.text ? String(item.text) : '';
                            addEditRowWithData(id, text);
                        });
                    }

                    editModalBg.style.display = 'flex';
                });
            });

            if (closeEditModal) {
                closeEditModal.addEventListener('click', () => {
                    editModalBg.style.display = 'none';
                });
            }

            if (addEditRow) {
                addEditRow.addEventListener('click', () => {
                    addEditRowWithData(0, '');
                });
            }

            editModalBg.addEventListener('click', (e) => {
                if (e.target === editModalBg) {
                    editModalBg.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>
