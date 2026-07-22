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
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Informe mensual | Honorarios</title>
    <style>
        body{font-family:"Segoe UI",Tahoma,sans-serif;background:#f4f8fb;margin:0;color:#16364f}
        .wrap{max-width:1180px;margin:20px auto;padding:0 16px}
        .top{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
        .btn{background:#0b7285;color:#fff;text-decoration:none;padding:10px 14px;border-radius:9px;border:0;cursor:pointer;font-weight:700}
        .btn-light{background:#eaf3fb;color:#224f78}
        .btn-disabled{background:#cddbe8;color:#4a647c}
        .card{background:#fff;border:1px solid #dce8f3;border-radius:12px;padding:16px;margin-top:14px}
        .grid{display:grid;grid-template-columns:repeat(4,minmax(170px,1fr));gap:10px}
        input,select,textarea{width:100%;padding:9px;border:1px solid #c9dced;border-radius:8px}
        textarea{min-height:84px}
        table{width:100%;border-collapse:collapse}
        th,td{border-bottom:1px solid #e7eff6;padding:10px;text-align:left;font-size:.93rem;vertical-align:top}
        .ok{background:#eaf9ef;color:#155b37;padding:8px;border-radius:8px;margin:10px 0}
        .err{background:#fff0ef;color:#9a1b14;padding:8px;border-radius:8px;margin:10px 0}
        @media(max-width:900px){.grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
    <div class="wrap">
        <div class="top">
            <h1>Informe mensual</h1>
            <div>
                <a class="btn btn-light" href="dashboard.php">Volver dashboard</a>
                <a class="btn btn-light" href="convenios.php">Gestionar convenios</a>
            </div>
        </div>

        <?php if ($success !== ''): ?><div class="ok"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="err"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

        <section class="card">
            <h2>Crear informe</h2>
            <form method="post">
                <div class="grid">
                    <div><label>Mes</label><input type="number" min="1" max="12" name="report_month" required value="<?php echo (int) date('n'); ?>"></div>
                    <div><label>Anio</label><input type="number" min="2020" max="2100" name="report_year" required value="<?php echo (int) date('Y'); ?>"></div>
                    <div><label>Nombre prestador del servicio</label><input name="provider_name" required value="<?php echo htmlspecialchars((string) $dbUser['full_name'], ENT_QUOTES, 'UTF-8'); ?>"></div>
                    <div><label>Profesion, Oficio y/o Experiencia</label><input name="profession_experience" required value="<?php echo htmlspecialchars((string) ($dbUser['profession_experience'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                </div>

                <div class="grid" style="margin-top:10px;">
                    <div>
                        <label>Origen de datos</label>
                        <select name="source_type" id="sourceType">
                            <option value="CONVENIO">Seleccionar convenio desde BD</option>
                            <option value="MANUAL">Ingreso manual</option>
                        </select>
                    </div>
                    <div id="agreementSelectWrap">
                        <label>Convenio</label>
                        <select name="agreement_id" id="agreementSelect">
                            <option value="">-- Seleccionar convenio --</option>
                            <?php foreach ($agreements as $a): ?>
                                <option
                                    value="<?php echo (int) $a['id']; ?>"
                                    data-program="<?php echo htmlspecialchars((string) $a['program_item'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-decree="<?php echo htmlspecialchars((string) ($a['decree_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-start="<?php echo htmlspecialchars((string) $a['start_date'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-end="<?php echo htmlspecialchars((string) $a['end_date'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-installments="<?php echo htmlspecialchars((string) ($a['installments_total'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                >
                                    <?php echo htmlspecialchars((string) $a['agreement_number'] . ' | ' . (string) $a['program_item'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div><label>N° decreto que aprueba el convenio</label><input name="decree_number_text" id="decreeInput"></div>
                    <div><label>N° cuota si corresponde</label><input type="number" min="1" name="installment_number" id="installmentInput"></div>
                </div>

                <div class="grid" style="margin-top:10px;">
                    <div style="grid-column:span 2;"><label>Programa, Convenio y/o actividad</label><textarea name="program_activity_text" id="programInput" required></textarea></div>
                    <div><label>Vigencia inicio</label><input type="date" name="agreement_start_date" id="startInput" required></div>
                    <div><label>Vigencia fin</label><input type="date" name="agreement_end_date" id="endInput" required></div>
                </div>

                <p><button class="btn" type="submit">Crear informe</button></p>
            </form>
            <p>Luego de crear el informe, la seccion de actividades quedara en desarrollo (proxima etapa).</p>
        </section>

        <section class="card">
            <h2>Informes creados</h2>
            <table>
                <thead><tr><th>Periodo</th><th>Origen</th><th>Convenio</th><th>Estado</th><th>Actividades</th></tr></thead>
                <tbody>
                    <?php foreach ($reports as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) $r['report_month'] . '/' . (string) $r['report_year'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) $r['source_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($agreementMap[(int) ($r['agreement_id'] ?? 0)] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) $r['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="btn btn-disabled">En desarrollo</span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (count($reports) === 0): ?>
                        <tr><td colspan="5">No hay informes creados.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    </div>

    <script>
        const sourceType = document.getElementById('sourceType');
        const agreementSelect = document.getElementById('agreementSelect');
        const agreementWrap = document.getElementById('agreementSelectWrap');
        const programInput = document.getElementById('programInput');
        const decreeInput = document.getElementById('decreeInput');
        const startInput = document.getElementById('startInput');
        const endInput = document.getElementById('endInput');
        const installmentInput = document.getElementById('installmentInput');

        function applyAgreementData() {
            const opt = agreementSelect.options[agreementSelect.selectedIndex];
            if (!opt || !opt.value) {
                return;
            }
            programInput.value = opt.dataset.program || programInput.value;
            decreeInput.value = opt.dataset.decree || decreeInput.value;
            startInput.value = opt.dataset.start || startInput.value;
            endInput.value = opt.dataset.end || endInput.value;
            installmentInput.value = opt.dataset.installments || installmentInput.value;
        }

        function toggleSource() {
            const isManual = sourceType.value === 'MANUAL';
            agreementWrap.style.display = isManual ? 'none' : 'block';
            agreementSelect.disabled = isManual;
            if (!isManual) {
                applyAgreementData();
            }
        }

        sourceType.addEventListener('change', toggleSource);
        agreementSelect.addEventListener('change', applyAgreementData);
        toggleSource();
    </script>
</body>
</html>
