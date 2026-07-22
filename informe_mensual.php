<?php
declare(strict_types=1);

require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/honorario_data.php';

$authUser = requireRole(ROLE_HONORARIO);
$dbUser = ensureHonorarioDbUser($authUser);
$pdo = db();

$success = '';
$error = '';

$agreementsStmt = $pdo->prepare('SELECT a.id, a.agreement_number, a.start_date, a.end_date, a.program_item, a.installments_total, d.decree_number
                                 FROM agreements a
                                 LEFT JOIN decrees d ON d.id = a.decree_id
                                 WHERE a.honorario_user_id = :uid
                                 ORDER BY a.start_date DESC');
$agreementsStmt->execute(['uid' => $dbUser['id']]);
$agreements = $agreementsStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $month = (int) ($_POST['report_month'] ?? 0);
        $year = (int) ($_POST['report_year'] ?? 0);
        $provider = trim((string) ($_POST['provider_name'] ?? ''));
        $profession = trim((string) ($_POST['profession_experience'] ?? ''));

        $sourceType = (string) ($_POST['source_type'] ?? 'CONVENIO');
        if ($sourceType !== 'MANUAL' && $sourceType !== 'CONVENIO') {
            $sourceType = 'CONVENIO';
        }

        $agreementIdRaw = trim((string) ($_POST['agreement_id'] ?? ''));
        $agreementId = $agreementIdRaw !== '' ? (int) $agreementIdRaw : null;

        $programText = trim((string) ($_POST['program_activity_text'] ?? ''));
        $decreeText = trim((string) ($_POST['decree_number_text'] ?? ''));
        $startDate = trim((string) ($_POST['agreement_start_date'] ?? ''));
        $endDate = trim((string) ($_POST['agreement_end_date'] ?? ''));
        $installmentRaw = trim((string) ($_POST['installment_number'] ?? ''));
        $installment = $installmentRaw !== '' ? (int) $installmentRaw : null;

        if ($month < 1 || $month > 12 || $year < 2020) {
            throw new RuntimeException('Mes o anio invalido.');
        }
        if ($provider === '' || $profession === '') {
            throw new RuntimeException('Nombre prestador y profesion/oficio son obligatorios.');
        }
        if ($programText === '') {
            throw new RuntimeException('Debes informar programa, convenio y/o actividad.');
        }
        if ($startDate === '' || $endDate === '') {
            throw new RuntimeException('Debes ingresar vigencia inicio y fin.');
        }

        if ($sourceType === 'CONVENIO') {
            if ($agreementId === null) {
                throw new RuntimeException('Debes seleccionar convenio para este tipo de informe.');
            }

            $check = $pdo->prepare('SELECT id FROM monthly_reports WHERE honorario_user_id = :uid AND report_month = :m AND report_year = :y AND source_type = :st AND agreement_id = :aid LIMIT 1');
            $check->execute([
                'uid' => $dbUser['id'],
                'm' => $month,
                'y' => $year,
                'st' => 'CONVENIO',
                'aid' => $agreementId,
            ]);
            if ($check->fetch() !== false) {
                throw new RuntimeException('Ya existe un informe para ese convenio en el mes/anio indicado.');
            }
        }

        $insert = $pdo->prepare('INSERT INTO monthly_reports (
            honorario_user_id,
            report_month,
            report_year,
            provider_name,
            profession_experience,
            source_type,
            agreement_id,
            program_activity_text,
            decree_number_text,
            agreement_start_date,
            agreement_end_date,
            installment_number,
            status
        ) VALUES (
            :uid,
            :m,
            :y,
            :provider,
            :profession,
            :source_type,
            :agreement_id,
            :program_text,
            :decree_text,
            :start_date,
            :end_date,
            :installment,
            :status
        )');

        $insert->execute([
            'uid' => $dbUser['id'],
            'm' => $month,
            'y' => $year,
            'provider' => $provider,
            'profession' => $profession,
            'source_type' => $sourceType,
            'agreement_id' => $sourceType === 'CONVENIO' ? $agreementId : null,
            'program_text' => $programText,
            'decree_text' => $decreeText !== '' ? $decreeText : null,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'installment' => $installment,
            'status' => 'BORRADOR',
        ]);

        $success = 'Informe creado en estado BORRADOR. La carga de actividades queda en desarrollo.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$reportsStmt = $pdo->prepare('SELECT id, report_month, report_year, source_type, status, created_at, agreement_id FROM monthly_reports WHERE honorario_user_id = :uid ORDER BY report_year DESC, report_month DESC, id DESC');
$reportsStmt->execute(['uid' => $dbUser['id']]);
$reports = $reportsStmt->fetchAll();

$agreementMap = [];
foreach ($agreements as $a) {
    $agreementMap[(int) $a['id']] = (string) $a['agreement_number'];
}

$monthNames = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
               'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

function statusBadge(string $s): string
{
    $map = [
        'BORRADOR'  => ['bg' => '#f1f5f9', 'color' => '#475569', 'label' => 'Borrador'],
        'ENVIADO'   => ['bg' => '#eff6ff', 'color' => '#1d4ed8', 'label' => 'Enviado'],
        'OBSERVADO' => ['bg' => '#fffbeb', 'color' => '#b45309', 'label' => 'Observado'],
        'APROBADO'  => ['bg' => '#f0fdf4', 'color' => '#15803d', 'label' => 'Aprobado'],
        'RECHAZADO' => ['bg' => '#fef2f2', 'color' => '#b91c1c', 'label' => 'Rechazado'],
    ];
    $d = $map[$s] ?? ['bg' => '#f1f5f9', 'color' => '#475569', 'label' => htmlspecialchars($s, ENT_QUOTES, 'UTF-8')];
    return '<span style="background:' . $d['bg'] . ';color:' . $d['color'] . ';padding:3px 10px;border-radius:20px;font-size:.8rem;font-weight:600;white-space:nowrap;">' . $d['label'] . '</span>';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Informe mensual | Honorarios</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }

        :root {
            --primary: #0b7285;
            --primary-hover: #086374;
            --primary-light: #e8f5f8;
            --surface: #ffffff;
            --bg: #f0f4f8;
            --border: #dde6ef;
            --text: #1e3a5f;
            --text-muted: #607d97;
            --radius: 14px;
            --radius-sm: 9px;
            --shadow: 0 1px 4px rgba(11,60,100,.06), 0 6px 20px rgba(11,60,100,.08);
        }

        body {
            font-family: "Segoe UI", system-ui, sans-serif;
            background: var(--bg);
            margin: 0;
            color: var(--text);
            min-height: 100vh;
        }

        /* ── Topbar ── */
        .topbar {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 0 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            height: 60px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .topbar-brand {
            font-weight: 700;
            font-size: 1rem;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        .topbar-brand svg { flex-shrink: 0; }
        .topbar-nav { display: flex; gap: 8px; align-items: center; }

        /* ── Buttons ── */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--primary);
            color: #fff;
            text-decoration: none;
            padding: 9px 18px;
            border-radius: var(--radius-sm);
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: .9rem;
            transition: background .15s, transform .1s;
            white-space: nowrap;
        }
        .btn:hover { background: var(--primary-hover); }
        .btn:active { transform: scale(.97); }
        .btn-ghost {
            background: transparent;
            color: var(--primary);
            border: 1.5px solid var(--border);
        }
        .btn-ghost:hover { background: var(--primary-light); }
        .btn-sm { padding: 6px 12px; font-size: .82rem; }
        .btn-muted { background: #e2eaf2; color: #607d97; cursor: default; }
        .btn-muted:hover { background: #e2eaf2; }

        /* ── Page layout ── */
        .page { max-width: 1100px; margin: 28px auto; padding: 0 20px 48px; }

        .page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }
        .page-title { margin: 0; font-size: 1.55rem; font-weight: 700; color: var(--text); }
        .page-subtitle { margin: 4px 0 0; color: var(--text-muted); font-size: .9rem; }

        /* ── Alerts ── */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 18px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
            font-size: .92rem;
            font-weight: 500;
        }
        .alert-ok { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        .alert-err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

        /* ── Cards ── */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 24px;
            overflow: hidden;
        }
        .card-header {
            padding: 18px 24px 14px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .card-header h2 {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .card-header h2 .step {
            width: 26px; height: 26px;
            background: var(--primary);
            color: #fff;
            border-radius: 50%;
            font-size: .78rem;
            font-weight: 700;
            display: grid;
            place-items: center;
            flex-shrink: 0;
        }
        .card-body { padding: 22px 24px; }

        /* ── Form ── */
        .field-group {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 18px;
        }
        .field-group-2 { grid-template-columns: repeat(2, 1fr); }
        .field-group-3 { grid-template-columns: repeat(3, 1fr); }
        .field-group-4 { grid-template-columns: repeat(4, 1fr); }
        .col-span-2 { grid-column: span 2; }
        .col-span-3 { grid-column: span 3; }

        .field { display: flex; flex-direction: column; gap: 5px; }
        .field label {
            font-size: .78rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .field input,
        .field select,
        .field textarea {
            width: 100%;
            padding: 9px 12px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: .92rem;
            color: var(--text);
            background: #fff;
            transition: border-color .15s, box-shadow .15s;
            outline: none;
            font-family: inherit;
        }
        .field input:focus,
        .field select:focus,
        .field textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(11,114,133,.12);
        }
        .field textarea { min-height: 90px; resize: vertical; }
        .field select { cursor: pointer; }

        /* ── Source type toggle ── */
        .source-toggle {
            display: flex;
            gap: 0;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            overflow: hidden;
            background: #f8fafc;
        }
        .source-toggle label {
            flex: 1;
            text-align: center;
            padding: 9px 10px;
            font-size: .85rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s, color .15s;
            color: var(--text-muted);
            user-select: none;
        }
        .source-toggle input[type="radio"] { display: none; }
        .source-toggle input[type="radio"]:checked + label {
            background: var(--primary);
            color: #fff;
        }

        /* ── Section separator ── */
        .form-section-title {
            font-size: .75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .07em;
            color: var(--text-muted);
            margin: 4px 0 14px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border);
        }

        /* ── Form footer ── */
        .form-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-top: 8px;
            flex-wrap: wrap;
        }
        .form-footer-note {
            font-size: .83rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* ── Table ── */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: .9rem; }
        thead tr { background: #f8fafc; }
        th {
            padding: 11px 16px;
            text-align: left;
            font-size: .75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        td {
            padding: 13px 16px;
            border-bottom: 1px solid #f0f4f8;
            color: var(--text);
            vertical-align: middle;
        }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background: #f8fafc; }
        .td-period { font-weight: 600; white-space: nowrap; }
        .td-origin {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: .78rem;
            font-weight: 600;
            padding: 3px 9px;
            border-radius: 20px;
        }
        .origin-convenio { background: #eff6ff; color: #1d4ed8; }
        .origin-manual   { background: #f5f3ff; color: #6d28d9; }
        .td-empty {
            text-align: center;
            padding: 36px;
            color: var(--text-muted);
            font-size: .92rem;
        }

        /* ── Responsive ── */
        @media (max-width: 860px) {
            .field-group, .field-group-3, .field-group-4 { grid-template-columns: 1fr 1fr; }
            .col-span-2, .col-span-3 { grid-column: span 2; }
        }
        @media (max-width: 540px) {
            .field-group, .field-group-2, .field-group-3, .field-group-4 { grid-template-columns: 1fr; }
            .col-span-2, .col-span-3 { grid-column: span 1; }
            .topbar-nav .btn-ghost { display: none; }
        }
    </style>
</head>
<body>

    <header class="topbar">
        <a class="topbar-brand" href="dashboard.php">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>
            </svg>
            Honorarios
        </a>
        <nav class="topbar-nav">
            <a class="btn btn-ghost btn-sm" href="dashboard.php">Dashboard</a>
            <a class="btn btn-ghost btn-sm" href="convenios.php">Convenios</a>
            <a class="btn btn-ghost btn-sm" href="decretos.php">Decretos</a>
        </nav>
    </header>

    <main class="page">

        <div class="page-header">
            <div>
                <h1 class="page-title">Informe mensual</h1>
                <p class="page-subtitle">Registra y gestiona tus informes de actividades mensuales</p>
            </div>
        </div>

        <?php if ($success !== ''): ?>
        <div class="alert alert-ok">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
        <div class="alert alert-err">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <?php endif; ?>

        <!-- Formulario -->
        <div class="card">
            <div class="card-header">
                <h2><span class="step">1</span> Crear nuevo informe</h2>
            </div>
            <div class="card-body">
                <form method="post" id="reportForm">

                    <p class="form-section-title">Datos del periodo y prestador</p>
                    <div class="field-group field-group-4">
                        <div class="field">
                            <label>Mes</label>
                            <select name="report_month" required>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $m === (int) date('n') ? 'selected' : ''; ?>>
                                        <?php echo $monthNames[$m]; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Año</label>
                            <input type="number" min="2020" max="2100" name="report_year" required value="<?php echo (int) date('Y'); ?>">
                        </div>
                        <div class="field">
                            <label>Nombre del prestador</label>
                            <input name="provider_name" required value="<?php echo htmlspecialchars((string) $dbUser['full_name'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="field">
                            <label>Profesión / Oficio / Experiencia</label>
                            <input name="profession_experience" required value="<?php echo htmlspecialchars((string) ($dbUser['profession_experience'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>

                    <p class="form-section-title">Origen del informe</p>
                    <div class="field-group field-group-4">
                        <div class="field">
                            <label>Tipo de origen</label>
                            <div class="source-toggle">
                                <input type="radio" name="source_type" id="srcConvenio" value="CONVENIO" checked>
                                <label for="srcConvenio">Convenio</label>
                                <input type="radio" name="source_type" id="srcManual" value="MANUAL">
                                <label for="srcManual">Manual</label>
                            </div>
                        </div>
                        <div class="field" id="agreementSelectWrap">
                            <label>Convenio</label>
                            <select name="agreement_id" id="agreementSelect">
                                <option value="">— Seleccionar —</option>
                                <?php foreach ($agreements as $a): ?>
                                    <option
                                        value="<?php echo (int) $a['id']; ?>"
                                        data-program="<?php echo htmlspecialchars((string) $a['program_item'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-decree="<?php echo htmlspecialchars((string) ($a['decree_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-start="<?php echo htmlspecialchars((string) $a['start_date'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-end="<?php echo htmlspecialchars((string) $a['end_date'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-installments="<?php echo htmlspecialchars((string) ($a['installments_total'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    >
                                        <?php echo htmlspecialchars((string) $a['agreement_number'] . ' — ' . (string) $a['program_item'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>N° decreto aprobatorio</label>
                            <input name="decree_number_text" id="decreeInput" placeholder="Ej: DEC-2026-101">
                        </div>
                        <div class="field">
                            <label>N° cuota (si aplica)</label>
                            <input type="number" min="1" name="installment_number" id="installmentInput" placeholder="Ej: 3">
                        </div>
                    </div>

                    <p class="form-section-title">Detalles del convenio</p>
                    <div class="field-group field-group-4">
                        <div class="field col-span-2">
                            <label>Programa, convenio y/o actividad</label>
                            <textarea name="program_activity_text" id="programInput" required placeholder="Describe el programa o actividad principal..."></textarea>
                        </div>
                        <div class="field">
                            <label>Vigencia inicio</label>
                            <input type="date" name="agreement_start_date" id="startInput" required>
                        </div>
                        <div class="field">
                            <label>Vigencia fin</label>
                            <input type="date" name="agreement_end_date" id="endInput" required>
                        </div>
                    </div>

                    <div class="form-footer">
                        <button class="btn" type="submit">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14m-7-7l7 7 7-7"/></svg>
                            Crear informe
                        </button>
                        <span class="form-footer-note">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            El informe se guardará en estado Borrador. La carga de actividades queda para la próxima etapa.
                        </span>
                    </div>

                </form>
            </div>
        </div>

        <!-- Listado -->
        <div class="card">
            <div class="card-header">
                <h2><span class="step">2</span> Informes registrados</h2>
                <span style="font-size:.82rem;color:var(--text-muted);"><?php echo count($reports); ?> informe<?php echo count($reports) !== 1 ? 's' : ''; ?></span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Periodo</th>
                            <th>Origen</th>
                            <th>Convenio</th>
                            <th>Estado</th>
                            <th>Actividades</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports as $r): ?>
                        <tr>
                            <td class="td-period">
                                <?php echo htmlspecialchars($monthNames[(int) $r['report_month']] ?? (string) $r['report_month'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php echo htmlspecialchars((string) $r['report_year'], ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td>
                                <span class="td-origin <?php echo $r['source_type'] === 'CONVENIO' ? 'origin-convenio' : 'origin-manual'; ?>">
                                    <?php echo $r['source_type'] === 'CONVENIO' ? 'Convenio' : 'Manual'; ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars((string) ($agreementMap[(int) ($r['agreement_id'] ?? 0)] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo statusBadge((string) $r['status']); ?></td>
                            <td><span class="btn btn-muted btn-sm">En desarrollo</span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (count($reports) === 0): ?>
                        <tr><td colspan="5" class="td-empty">
                            <svg width="32" height="32" style="display:block;margin:0 auto 10px;opacity:.35" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                            No hay informes creados aún. Usa el formulario de arriba para crear el primero.
                        </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <script>
        const srcConvenio    = document.getElementById('srcConvenio');
        const srcManual      = document.getElementById('srcManual');
        const agreementSelect = document.getElementById('agreementSelect');
        const agreementWrap  = document.getElementById('agreementSelectWrap');
        const programInput   = document.getElementById('programInput');
        const decreeInput    = document.getElementById('decreeInput');
        const startInput     = document.getElementById('startInput');
        const endInput       = document.getElementById('endInput');
        const installmentInput = document.getElementById('installmentInput');

        function applyAgreementData() {
            const opt = agreementSelect.options[agreementSelect.selectedIndex];
            if (!opt || !opt.value) return;
            if (opt.dataset.program)      programInput.value = opt.dataset.program;
            if (opt.dataset.decree)       decreeInput.value  = opt.dataset.decree;
            if (opt.dataset.start)        startInput.value   = opt.dataset.start;
            if (opt.dataset.end)          endInput.value     = opt.dataset.end;
            if (opt.dataset.installments) installmentInput.value = opt.dataset.installments;
        }

        function toggleSource() {
            const isManual = srcManual.checked;
            agreementWrap.style.display = isManual ? 'none' : 'flex';
            agreementSelect.disabled = isManual;
            if (!isManual) applyAgreementData();
        }

        srcConvenio.addEventListener('change', toggleSource);
        srcManual.addEventListener('change', toggleSource);
        agreementSelect.addEventListener('change', applyAgreementData);
        toggleSource();
    </script>
</body>
</html>
</body>
</html>
