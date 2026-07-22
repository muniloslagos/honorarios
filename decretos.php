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
        $number = trim((string) ($_POST['decree_number'] ?? ''));
        $date = trim((string) ($_POST['decree_date'] ?? ''));

        if ($number === '' || $date === '') {
            throw new RuntimeException('Debes completar numero y fecha de decreto.');
        }

        $pdfData = uploadPdf($_FILES['decree_pdf'] ?? [], 'decrees/' . $dbUser['run']);

        $sql = 'INSERT INTO decrees (honorario_user_id, decree_number, decree_date, pdf_original_name, pdf_path, created_by_user_id)
                VALUES (:uid, :num, :d, :pdf_name, :pdf_path, :actor)
                ON DUPLICATE KEY UPDATE decree_date = VALUES(decree_date), pdf_original_name = VALUES(pdf_original_name), pdf_path = VALUES(pdf_path)';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'uid' => $dbUser['id'],
            'num' => $number,
            'd' => $date,
            'pdf_name' => $pdfData['original_name'] ?? null,
            'pdf_path' => $pdfData['stored_path'] ?? null,
            'actor' => $dbUser['id'],
        ]);

        $success = 'Decreto guardado correctamente.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$list = $pdo->prepare('SELECT id, decree_number, decree_date, pdf_path FROM decrees WHERE honorario_user_id = :uid ORDER BY decree_date DESC, id DESC');
$list->execute(['uid' => $dbUser['id']]);
$decrees = $list->fetchAll();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Decretos | Honorarios</title>
    <style>
        body{font-family:"Segoe UI",Tahoma,sans-serif;background:#f4f8fb;margin:0;color:#16364f}
        .wrap{max-width:1100px;margin:20px auto;padding:0 16px}
        .top{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
        .btn{background:#0b7285;color:#fff;text-decoration:none;padding:10px 14px;border-radius:9px;border:0;cursor:pointer;font-weight:700}
        .btn-light{background:#eaf3fb;color:#224f78}
        .card{background:#fff;border:1px solid #dce8f3;border-radius:12px;padding:16px;margin-top:14px}
        .grid{display:grid;grid-template-columns:repeat(3,minmax(180px,1fr));gap:10px}
        input{width:100%;padding:9px;border:1px solid #c9dced;border-radius:8px}
        table{width:100%;border-collapse:collapse}
        th,td{border-bottom:1px solid #e7eff6;padding:10px;text-align:left;font-size:.93rem}
        .ok{background:#eaf9ef;color:#155b37;padding:8px;border-radius:8px;margin:10px 0}
        .err{background:#fff0ef;color:#9a1b14;padding:8px;border-radius:8px;margin:10px 0}
    </style>
</head>
<body>
    <div class="wrap">
        <div class="top">
            <h1>Decretos</h1>
            <div>
                <a class="btn btn-light" href="dashboard.php">Volver al dashboard</a>
                <a class="btn" href="convenios.php">Ir a convenios</a>
            </div>
        </div>

        <?php if ($success !== ''): ?><div class="ok"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="err"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

        <section class="card">
            <h2>Agregar decreto</h2>
            <form method="post" enctype="multipart/form-data">
                <div class="grid">
                    <div>
                        <label>N° decreto</label>
                        <input name="decree_number" required>
                    </div>
                    <div>
                        <label>Fecha</label>
                        <input type="date" name="decree_date" required>
                    </div>
                    <div>
                        <label>Documento PDF</label>
                        <input type="file" name="decree_pdf" accept="application/pdf">
                    </div>
                </div>
                <p><button class="btn" type="submit">Guardar decreto</button></p>
            </form>
        </section>

        <section class="card">
            <h2>Mis decretos</h2>
            <table>
                <thead><tr><th>N° decreto</th><th>Fecha</th><th>PDF</th></tr></thead>
                <tbody>
                    <?php foreach ($decrees as $d): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) $d['decree_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) $d['decree_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <?php if (!empty($d['pdf_path'])): ?>
                                    <a href="<?php echo htmlspecialchars((string) $d['pdf_path'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank">Ver PDF</a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (count($decrees) === 0): ?>
                        <tr><td colspan="3">No hay decretos registrados.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    </div>
</body>
</html>
