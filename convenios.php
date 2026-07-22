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
        body{font-family:"Segoe UI",Tahoma,sans-serif;background:#f4f8fb;margin:0;color:#16364f}
        .wrap{max-width:1180px;margin:20px auto;padding:0 16px}
        .top{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
        .btn{background:#0b7285;color:#fff;text-decoration:none;padding:10px 14px;border-radius:9px;border:0;cursor:pointer;font-weight:700}
        .btn-light{background:#eaf3fb;color:#224f78}
        .card{background:#fff;border:1px solid #dce8f3;border-radius:12px;padding:16px;margin-top:14px}
        .grid{display:grid;grid-template-columns:repeat(4,minmax(170px,1fr));gap:10px}
        .grid-2{display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:10px}
        input,select,textarea{width:100%;padding:9px;border:1px solid #c9dced;border-radius:8px}
        textarea{min-height:90px}
        table{width:100%;border-collapse:collapse}
        th,td{border-bottom:1px solid #e7eff6;padding:10px;text-align:left;font-size:.93rem;vertical-align:top}
        .ok{background:#eaf9ef;color:#155b37;padding:8px;border-radius:8px;margin:10px 0}
        .err{background:#fff0ef;color:#9a1b14;padding:8px;border-radius:8px;margin:10px 0}
        .modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.35);display:none;align-items:center;justify-content:center;padding:16px}
        .modal{background:#fff;border-radius:12px;max-width:540px;width:100%;padding:16px}
        .list-chip{display:inline-block;background:#eff6ff;border:1px solid #d7e5f5;border-radius:999px;padding:2px 9px;margin:2px;font-size:.83rem}
        @media(max-width:900px){.grid,.grid-2{grid-template-columns:1fr}}
    </style>
</head>
<body>
    <div class="wrap">
        <div class="top">
            <h1>Convenios</h1>
            <div>
                <a class="btn btn-light" href="dashboard.php">Volver dashboard</a>
                <a class="btn btn-light" href="decretos.php">Ver decretos</a>
                <a class="btn" href="informe_mensual.php">Crear informe</a>
            </div>
        </div>

        <?php if ($success !== ''): ?><div class="ok"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="err"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

        <section class="card">
            <h2>Agregar convenio</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="create_agreement" value="1">
                <div class="grid">
                    <div><label>Numero convenio</label><input name="agreement_number" required></div>
                    <div><label>Fecha convenio</label><input type="date" name="agreement_date" required></div>
                    <div><label>Vigencia inicio</label><input type="date" name="start_date" required></div>
                    <div><label>Vigencia fin</label><input type="date" name="end_date" required></div>
                    <div><label>Cuotas</label><input type="number" min="1" name="installments_total"></div>
                    <div><label>Programa o item</label><input name="program_item" required></div>
                    <div>
                        <label>N° decreto que lo aprueba</label>
                        <select name="decree_id">
                            <option value="">-- Seleccionar --</option>
                            <?php foreach ($decrees as $d): ?>
                                <option value="<?php echo (int) $d['id']; ?>"><?php echo htmlspecialchars((string) $d['decree_number'] . ' (' . (string) $d['decree_date'] . ')', ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small><a href="#" id="openDecreeModal">No existe? Agregar decreto</a></small>
                    </div>
                    <div>
                        <label>Estado</label>
                        <select name="status">
                            <option value="VIGENTE">VIGENTE</option>
                            <option value="PENDIENTE_FIRMA">PENDIENTE_FIRMA</option>
                            <option value="NO_VIGENTE">NO_VIGENTE</option>
                        </select>
                    </div>
                </div>
                <div class="grid-2" style="margin-top:10px;">
                    <div>
                        <label>Funciones (una por linea)</label>
                        <textarea name="functions_text" placeholder="Funcion 1&#10;Funcion 2&#10;Funcion 3"></textarea>
                    </div>
                    <div>
                        <label>Documento PDF</label>
                        <input type="file" name="agreement_pdf" accept="application/pdf">
                    </div>
                </div>
                <p><button class="btn" type="submit">Guardar convenio</button></p>
            </form>
        </section>

        <section class="card">
            <h2>Mis convenios</h2>
            <table>
                <thead><tr><th>Convenio</th><th>Vigencia</th><th>Decreto</th><th>Funciones</th><th>PDF</th></tr></thead>
                <tbody>
                    <?php foreach ($agreements as $a): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars((string) $a['agreement_number'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
                                <?php echo htmlspecialchars((string) $a['program_item'], ENT_QUOTES, 'UTF-8'); ?><br>
                                Estado: <?php echo htmlspecialchars((string) $a['status'], ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td><?php echo htmlspecialchars((string) $a['start_date'] . ' a ' . (string) $a['end_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($a['decree_number'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <?php $items = $functionsByAgreement[(int) $a['id']] ?? []; ?>
                                <?php foreach ($items as $fn): ?>
                                    <span class="list-chip"><?php echo htmlspecialchars($fn, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endforeach; ?>
                                <?php if (count($items) === 0): ?>-
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($a['pdf_path'])): ?>
                                    <a href="<?php echo htmlspecialchars((string) $a['pdf_path'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank">Ver PDF</a>
                                <?php else: ?>-
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (count($agreements) === 0): ?>
                        <tr><td colspan="5">No hay convenios registrados.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    </div>

    <div id="decreeModalBg" class="modal-bg" aria-hidden="true">
        <div class="modal">
            <h3>Agregar decreto (sin salir)</h3>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="create_decree_inline" value="1">
                <p><label>N° decreto</label><input name="decree_number_inline" required></p>
                <p><label>Fecha</label><input type="date" name="decree_date_inline" required></p>
                <p><label>PDF decreto</label><input type="file" name="decree_pdf_inline" accept="application/pdf"></p>
                <p>
                    <button class="btn" type="submit">Guardar decreto</button>
                    <button class="btn btn-light" type="button" id="closeDecreeModal">Cerrar</button>
                </p>
            </form>
        </div>
    </div>

    <script>
        const open = document.getElementById('openDecreeModal');
        const close = document.getElementById('closeDecreeModal');
        const bg = document.getElementById('decreeModalBg');
        if (open && close && bg) {
            open.addEventListener('click', function (e) {
                e.preventDefault();
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
