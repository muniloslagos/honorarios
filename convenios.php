<?php
declare(strict_types=1);

require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/honorario_data.php';

$authUser = requireRole(ROLE_HONORARIO);
$dbUser = ensureHonorarioDbUser($authUser);
$pdo = db();

$success = '';
$error = '';

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
            $decreeId = trim((string) ($_POST['decree_id'] ?? ''));
            $status = trim((string) ($_POST['status'] ?? 'VIGENTE'));
            $functionsRaw = trim((string) ($_POST['functions_text'] ?? ''));

            if ($agreementNumber === '' || $agreementDate === '' || $startDate === '' || $endDate === '' || $programItem === '') {
                throw new RuntimeException('Completa los campos obligatorios del convenio.');
            }

            $agreementPdf = uploadPdf($_FILES['agreement_pdf'] ?? [], 'agreements/' . $dbUser['run']);

            $stmt = $pdo->prepare('INSERT INTO agreements (
                    honorario_user_id, agreement_number, agreement_date, start_date, end_date, installments_total,
                    program_item, decree_id, pdf_original_name, pdf_path, status, created_by_user_id
                ) VALUES (
                    :uid, :num, :ad, :sd, :ed, :ins, :prog, :decree, :pdfn, :pdfp, :st, :actor
                )
                ON DUPLICATE KEY UPDATE
                    agreement_date=VALUES(agreement_date),
                    start_date=VALUES(start_date),
                    end_date=VALUES(end_date),
                    installments_total=VALUES(installments_total),
                    program_item=VALUES(program_item),
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
                'decree' => $decreeId !== '' ? (int) $decreeId : null,
                'pdfn' => $agreementPdf['original_name'] ?? null,
                'pdfp' => $agreementPdf['stored_path'] ?? null,
                'st' => in_array($status, ['VIGENTE', 'NO_VIGENTE', 'PENDIENTE_FIRMA'], true) ? $status : 'VIGENTE',
                'actor' => $dbUser['id'],
            ]);

            $agFind = $pdo->prepare('SELECT id FROM agreements WHERE honorario_user_id = :uid AND agreement_number = :num LIMIT 1');
            $agFind->execute(['uid' => $dbUser['id'], 'num' => $agreementNumber]);
            $agreement = $agFind->fetch();

            if ($agreement !== false) {
                $agreementId = (int) $agreement['id'];
                $pdo->prepare('DELETE FROM agreement_functions WHERE agreement_id = :id')->execute(['id' => $agreementId]);

                $lines = preg_split('/\r\n|\r|\n/', $functionsRaw) ?: [];
                $order = 1;
                foreach ($lines as $line) {
                    $text = trim((string) $line);
                    if ($text === '') {
                        continue;
                    }
                    $insFn = $pdo->prepare('INSERT INTO agreement_functions (agreement_id, function_text, sort_order) VALUES (:aid, :txt, :ord)');
                    $insFn->execute(['aid' => $agreementId, 'txt' => $text, 'ord' => $order]);
                    $order++;
                }
            }

            $success = 'Convenio guardado correctamente.';
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
    $fnStmt = $pdo->prepare("SELECT agreement_id, function_text FROM agreement_functions WHERE agreement_id IN ($in) ORDER BY sort_order ASC, id ASC");
    $fnStmt->execute($ids);
    foreach ($fnStmt->fetchAll() as $row) {
        $aid = (int) $row['agreement_id'];
        if (!isset($functionsByAgreement[$aid])) {
            $functionsByAgreement[$aid] = [];
        }
        $functionsByAgreement[$aid][] = (string) $row['function_text'];
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
            .topbar-inner { padding: 12px 14px; }
            .grid { grid-template-columns: 1fr; }
            .actions .btn { flex: 1 1 auto; }
            .card-head h2 { font-size: 1.08rem; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-inner">
            <a class="brand" href="dashboard.php">Honorarios • Convenios</a>
            <nav class="actions">
                <a class="btn btn-soft" href="dashboard.php">Volver dashboard</a>
                <a class="btn btn-soft" href="decretos.php">Ver decretos</a>
                <a class="btn btn-primary" href="informe_mensual.php">Crear informe</a>
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
                            <label>Estado</label>
                            <select name="status">
                                <option value="VIGENTE">VIGENTE</option>
                                <option value="PENDIENTE_FIRMA">PENDIENTE_FIRMA</option>
                                <option value="NO_VIGENTE">NO_VIGENTE</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid-2" style="margin-top:12px;">
                        <div class="field">
                            <label>Funciones (una por linea)</label>
                            <textarea name="functions_text" placeholder="Funcion 1&#10;Funcion 2&#10;Funcion 3"></textarea>
                        </div>
                        <div class="field">
                            <label>Documento PDF</label>
                            <input type="file" name="agreement_pdf" accept="application/pdf">
                        </div>
                    </div>

                    <div class="form-footer">
                        <button class="btn btn-primary" type="submit">Guardar convenio</button>
                    </div>
                </form>
            </div>
        </section>

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
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($agreements as $a): ?>
                            <?php
                                $status = (string) $a['status'];
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
                                        <span class="list-chip"><?php echo htmlspecialchars($fn, ENT_QUOTES, 'UTF-8'); ?></span>
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
                            </tr>
                        <?php endforeach; ?>
                        <?php if (count($agreements) === 0): ?>
                            <tr>
                                <td colspan="5" class="empty">No hay convenios registrados.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

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
    </script>
</body>
</html>
