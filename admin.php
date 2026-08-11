<?php
declare(strict_types=1);

require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/mailer.php';
require_once __DIR__ . '/src/admin_navigation.php';

header('Content-Type: text/html; charset=UTF-8');

$authUser = requireRole(ROLE_ADMIN);
$pdo = db();

$adminRun = (string) ($authUser['run'] ?? '');
$adminName = (string) ($authUser['name'] ?? 'Administrador');
$adminProfession = (string) ($authUser['user_info']['profesion'] ?? 'Administración');

$adminStmt = $pdo->prepare('SELECT id FROM system_users WHERE run = :run AND role = :role LIMIT 1');
$adminStmt->execute(['run' => $adminRun, 'role' => ROLE_ADMIN]);
$adminId = $adminStmt->fetchColumn();
if ($adminId === false) {
    $insertAdmin = $pdo->prepare('INSERT INTO system_users (run, first_names, full_name, role, profession_experience, is_active) VALUES (:run, :name, :name, :role, :profession, 1)');
    $insertAdmin->execute(['run' => $adminRun, 'name' => $adminName, 'role' => ROLE_ADMIN, 'profession' => $adminProfession]);
    $adminId = (int) $pdo->lastInsertId();
} else {
    $adminId = (int) $adminId;
}

$pdo->exec("CREATE TABLE IF NOT EXISTS honorario_signatures (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    honorario_user_id BIGINT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(80) NOT NULL,
    size_bytes BIGINT UNSIGNED NULL,
    uploaded_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_honorario_signatures_user (honorario_user_id),
    CONSTRAINT fk_honorario_signatures_user FOREIGN KEY (honorario_user_id) REFERENCES system_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_honorario_signatures_uploader FOREIGN KEY (uploaded_by_user_id) REFERENCES system_users(id) ON DELETE SET NULL
) ENGINE=InnoDB");

$success = '';
$error = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');
        $userId = (int) ($_POST['user_id'] ?? 0);

        if ($action === 'save_smtp') {
            $host = trim((string) ($_POST['smtp_host'] ?? ''));
            $port = (int) ($_POST['smtp_port'] ?? 0);
            $encryption = (string) ($_POST['smtp_encryption'] ?? 'tls');
            $username = trim((string) ($_POST['smtp_username'] ?? ''));
            $password = (string) ($_POST['smtp_password'] ?? '');
            $fromEmail = trim((string) ($_POST['smtp_from_email'] ?? ''));
            $fromName = trim((string) ($_POST['smtp_from_name'] ?? ''));
            $replyTo = trim((string) ($_POST['smtp_reply_to'] ?? ''));
            $enabled = isset($_POST['smtp_enabled']) ? 1 : 0;

            if ($host === '' || $port < 1 || $port > 65535 || !in_array($encryption, ['tls', 'ssl', 'none'], true)) {
                throw new RuntimeException('Servidor, puerto y seguridad SMTP son obligatorios.');
            }
            if (filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false || ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) === false)) {
                throw new RuntimeException('El correo remitente o de respuesta no es válido.');
            }
            if ($fromName === '') throw new RuntimeException('Debes indicar el nombre del remitente.');

            $currentSmtp = getSmtpSettings($pdo);
            if ($password === '') {
                if ($currentSmtp === null) throw new RuntimeException('Debes ingresar la contraseña SMTP.');
                $encryptedPassword = (string) $currentSmtp['password_encrypted'];
            } else {
                $encryptedPassword = encryptSmtpPassword($password);
            }

            $saveSmtp = $pdo->prepare('INSERT INTO smtp_settings (id, host, port, encryption, username, password_encrypted, from_email, from_name, reply_to_email, is_enabled, updated_by_user_id)
                                       VALUES (1, :host, :port, :encryption, :username, :password, :from_email, :from_name, :reply_to, :enabled, :admin)
                                       ON DUPLICATE KEY UPDATE host=VALUES(host), port=VALUES(port), encryption=VALUES(encryption), username=VALUES(username), password_encrypted=VALUES(password_encrypted), from_email=VALUES(from_email), from_name=VALUES(from_name), reply_to_email=VALUES(reply_to_email), is_enabled=VALUES(is_enabled), updated_by_user_id=VALUES(updated_by_user_id)');
            $saveSmtp->execute(['host'=>$host,'port'=>$port,'encryption'=>$encryption,'username'=>$username,'password'=>$encryptedPassword,'from_email'=>$fromEmail,'from_name'=>$fromName,'reply_to'=>$replyTo !== '' ? $replyTo : null,'enabled'=>$enabled,'admin'=>$adminId]);
            $success = 'Configuración SMTP guardada correctamente.';
        } elseif ($action === 'test_smtp') {
            $testEmail = trim((string) ($_POST['test_email'] ?? ''));
            if (filter_var($testEmail, FILTER_VALIDATE_EMAIL) === false) throw new RuntimeException('Ingresa un correo de prueba válido.');
            sendSmtpMail($testEmail, 'Administrador', 'Prueba SMTP - Sistema Honorarios', '<h2>Configuración SMTP correcta</h2><p>Este mensaje confirma que el Sistema de Honorarios puede enviar correos mediante PHPMailer.</p>', 'Configuración SMTP correcta.');
            $success = 'Correo de prueba enviado correctamente a ' . $testEmail . '.';
        } else {        $targetStmt = $pdo->prepare('SELECT id, run, role FROM system_users WHERE id = :id LIMIT 1');
        $targetStmt->execute(['id' => $userId]);
        $targetUser = $targetStmt->fetch();
        if ($targetUser === false) {
            throw new RuntimeException('Usuario no encontrado.');
        }

        if ($action === 'update_user') {
            $firstNames = trim((string) ($_POST['first_names'] ?? ''));
            $lastNames = trim((string) ($_POST['last_names'] ?? ''));
            $fullName = trim($firstNames . ' ' . $lastNames);
            $email = trim((string) ($_POST['email'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $directionId = (int) ($_POST['direction_id'] ?? 0);
            $role = (string) ($_POST['role'] ?? '');
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $allowedRoles = [ROLE_ADMIN, ROLE_RRHH, ROLE_FINANZAS, ROLE_HONORARIO, ROLE_DIRECTOR];

            if ($fullName === '' || !in_array($role, $allowedRoles, true)) {
                throw new RuntimeException('Nombre y perfil son obligatorios.');
            }
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('El correo electrónico no es válido.');
            }
            if ($userId === $adminId && ($role !== ROLE_ADMIN || $isActive !== 1)) {
                throw new RuntimeException('No puedes quitar tu propio perfil administrador ni deshabilitar tu cuenta.');
            }

            $update = $pdo->prepare('UPDATE system_users SET first_names = :first_names, last_names = :last_names, full_name = :name, email = :email, phone = :phone, direction_id = :direction, role = :role, is_active = :active WHERE id = :id');
            $update->execute(['first_names' => $firstNames, 'last_names' => $lastNames !== '' ? $lastNames : null, 'name' => $fullName, 'email' => $email !== '' ? $email : null, 'phone' => $phone !== '' ? $phone : null, 'direction' => $directionId > 0 ? $directionId : null, 'role' => $role, 'active' => $isActive, 'id' => $userId]);
            $success = 'Usuario y permisos actualizados correctamente.';
        } elseif ($action === 'upload_signature') {
            if ((string) $targetUser['role'] !== ROLE_HONORARIO) {
                throw new RuntimeException('Solo se pueden registrar firmas para personal a honorarios.');
            }

            $file = $_FILES['signature_image'] ?? [];
            if (!isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Debes seleccionar una imagen de firma.');
            }
            if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
                throw new RuntimeException('La imagen de firma no puede superar 5 MB.');
            }

            $tmpName = (string) ($file['tmp_name'] ?? '');
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpName) ?: '';
            $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
            if (!isset($extensions[$mime])) {
                throw new RuntimeException('La firma debe ser una imagen PNG, JPG o WEBP.');
            }

            $signatureDir = __DIR__ . '/uploads/signatures';
            if (!is_dir($signatureDir) && !mkdir($signatureDir, 0775, true) && !is_dir($signatureDir)) {
                throw new RuntimeException('No fue posible crear la carpeta de firmas.');
            }
            $storedName = 'firma_' . $userId . '_' . date('YmdHis') . '.' . $extensions[$mime];
            $fullPath = $signatureDir . '/' . $storedName;
            if (!move_uploaded_file($tmpName, $fullPath)) {
                throw new RuntimeException('No fue posible guardar la imagen de firma.');
            }

            $previousStmt = $pdo->prepare('SELECT stored_path FROM honorario_signatures WHERE honorario_user_id = :uid LIMIT 1');
            $previousStmt->execute(['uid' => $userId]);
            $previousPath = $previousStmt->fetchColumn();

            $save = $pdo->prepare('INSERT INTO honorario_signatures (honorario_user_id, original_name, stored_path, mime_type, size_bytes, uploaded_by_user_id)
                                   VALUES (:uid, :name, :path, :mime, :size, :admin)
                                   ON DUPLICATE KEY UPDATE original_name=VALUES(original_name), stored_path=VALUES(stored_path), mime_type=VALUES(mime_type), size_bytes=VALUES(size_bytes), uploaded_by_user_id=VALUES(uploaded_by_user_id)');
            $save->execute([
                'uid' => $userId,
                'name' => (string) ($file['name'] ?? 'firma.' . $extensions[$mime]),
                'path' => 'uploads/signatures/' . $storedName,
                'mime' => $mime,
                'size' => (int) ($file['size'] ?? 0),
                'admin' => $adminId,
            ]);

            if (is_string($previousPath) && $previousPath !== '') {
                $oldPath = realpath(__DIR__ . '/' . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $previousPath));
                $root = realpath($signatureDir);
                if ($oldPath !== false && $root !== false && str_starts_with($oldPath, $root . DIRECTORY_SEPARATOR) && is_file($oldPath)) {
                    unlink($oldPath);
                }
            }
            $success = 'Firma actualizada correctamente.';
        } elseif ($action === 'delete_signature') {
            $signatureStmt = $pdo->prepare('SELECT stored_path FROM honorario_signatures WHERE honorario_user_id = :uid LIMIT 1');
            $signatureStmt->execute(['uid' => $userId]);
            $signaturePath = $signatureStmt->fetchColumn();
            $pdo->prepare('DELETE FROM honorario_signatures WHERE honorario_user_id = :uid')->execute(['uid' => $userId]);

            if (is_string($signaturePath) && $signaturePath !== '') {
                $fullPath = realpath(__DIR__ . '/' . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $signaturePath));
                $root = realpath(__DIR__ . '/uploads/signatures');
                if ($fullPath !== false && $root !== false && str_starts_with($fullPath, $root . DIRECTORY_SEPARATOR) && is_file($fullPath)) {
                    unlink($fullPath);
                }
            }
            $success = 'Firma eliminada correctamente.';
        } else {
            throw new RuntimeException('Acción no válida.');
        }
        }
    }
} catch (Throwable $e) {
    $error = $e instanceof PDOException && (string) $e->getCode() === '23000'
        ? 'No fue posible cambiar el perfil porque ya existe otro registro con el mismo RUN y ese rol.'
        : $e->getMessage();
}

$smtpSettings = getSmtpSettings($pdo);

$usersStmt = $pdo->query('SELECT u.id, u.run, u.first_names, u.last_names, u.full_name, u.email, u.phone, u.role, u.profession_experience, u.direction_id, u.is_active, s.original_name AS signature_name, s.stored_path AS signature_path
                          FROM system_users u
                          LEFT JOIN honorario_signatures s ON s.honorario_user_id = u.id
                          ORDER BY FIELD(u.role, \'ADMINISTRADOR\', \'RRHH\', \'FINANZAS\', \'HONORARIO\'), u.full_name ASC');
$users = $usersStmt->fetchAll();
$directions = $pdo->query('SELECT id, name FROM directions WHERE is_active = 1 ORDER BY name')->fetchAll();
$counts = ['total' => count($users), 'active' => 0, 'honorarios' => 0, 'signatures' => 0];
foreach ($users as $row) {
    if ((int) $row['is_active'] === 1) $counts['active']++;
    if ((string) $row['role'] === ROLE_HONORARIO) $counts['honorarios']++;
    if ((string) ($row['signature_path'] ?? '') !== '') $counts['signatures']++;
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administración | Sistema Honorarios</title>
    <style>
        :root{--brand:#0b7285;--brand-dark:#075766;--bg:#f3f7fb;--text:#17324a;--muted:#60778b;--line:#dbe7f1;--danger:#b42318;--ok:#18794e}
        *{box-sizing:border-box} body{margin:0;font-family:"Segoe UI",Tahoma,sans-serif;background:var(--bg);color:var(--text)}
        .shell{display:grid;grid-template-columns:250px 1fr;min-height:100vh}.sidebar{background:#fff;border-right:1px solid var(--line);padding:26px 20px}.brand{margin:0 0 6px;font-size:1.15rem}.muted{color:var(--muted)}
        .menu{display:grid;gap:8px;margin-top:26px}.menu a,.menu summary{padding:11px 12px;border-radius:10px;text-decoration:none;color:var(--text);font-weight:650;cursor:pointer;list-style:none}.menu summary::-webkit-details-marker{display:none}.menu a.active,.menu summary.active{background:#eaf6f8;color:var(--brand-dark)}.menu summary{display:flex;justify-content:space-between}.menu-group[open] .menu-chevron{transform:rotate(180deg)}.submenu{display:grid;gap:4px;margin:5px 0 4px 14px;padding-left:10px;border-left:2px solid #dcecf0}.submenu a{font-size:.92rem;padding:9px 10px}
        .content{padding:28px;min-width:0}.top{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:22px}.top h1{margin:0}.top p{margin:5px 0 0}
        .btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:9px;padding:9px 13px;background:var(--brand);color:#fff;text-decoration:none;font-weight:700;cursor:pointer}.btn:hover{background:var(--brand-dark)}.btn.light{background:#eef4f8;color:var(--text)}.btn.danger{background:#fff0ef;color:var(--danger);border:1px solid #f3c3be}.btn.small{padding:7px 10px;font-size:.82rem}
        .stats{display:grid;grid-template-columns:repeat(4,minmax(130px,1fr));gap:12px;margin-bottom:20px}.stat,.card{background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 8px 25px rgba(24,52,75,.05)}.stat{padding:16px}.stat strong{display:block;font-size:1.6rem}.stat span{color:var(--muted);font-size:.86rem}
        .alert{padding:12px 15px;border-radius:10px;margin-bottom:16px}.ok{background:#edf9f2;color:var(--ok);border:1px solid #bfe7ce}.error{background:#fff0ef;color:var(--danger);border:1px solid #f3c3be}
        .card{overflow:hidden}.card-head{padding:17px 20px;border-bottom:1px solid var(--line)}.card-head h2{margin:0;font-size:1.05rem}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:1050px}th,td{padding:13px 12px;text-align:left;border-bottom:1px solid #edf2f6;vertical-align:top}th{font-size:.74rem;text-transform:uppercase;color:var(--muted);background:#f8fafc}
        input,select{width:100%;border:1px solid #cddce7;border-radius:8px;padding:8px;font:inherit}.user-form{display:grid;grid-template-columns:1fr 1fr 1.15fr .8fr 1fr 1fr auto auto;gap:8px;align-items:center}.switch{display:flex;align-items:center;gap:6px;white-space:nowrap;font-size:.84rem}.switch input{width:auto}
.smtp-grid{display:grid;grid-template-columns:2fr .7fr 1fr 1.5fr;gap:12px}.smtp-actions{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}.smtp-form{padding:18px 20px}.smtp-form label{display:block;font-size:.78rem;font-weight:700;color:var(--muted);margin-bottom:5px}.smtp-span-2{grid-column:span 2}.smtp-note{font-size:.84rem;color:var(--muted);margin:12px 0 0}
                .signature{display:flex;align-items:center;gap:9px;min-width:260px}.signature img{width:86px;height:46px;object-fit:contain;border:1px solid var(--line);border-radius:7px;background:#fff}.signature-actions{display:flex;gap:5px;align-items:center;flex-wrap:wrap}.signature input[type=file]{max-width:185px;font-size:.76rem}
        .role{display:inline-flex;padding:4px 8px;border-radius:20px;background:#eef4f8;font-size:.78rem;font-weight:700}@media(max-width:900px){.smtp-grid{grid-template-columns:1fr 1fr}.smtp-span-2{grid-column:span 2}.shell{grid-template-columns:1fr}.sidebar{border-right:0;border-bottom:1px solid var(--line)}.stats{grid-template-columns:1fr 1fr}.content{padding:18px}}
    </style>
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <h2 class="brand">Sistema Honorarios</h2>
        <div class="muted">Perfil administrador</div>
        <?php renderAdminNavigation('users'); ?>

    </aside>
    <main class="content">
        <header class="top">
            <div><h1>Panel de administración</h1><p class="muted">Gestiona usuarios, accesos y firmas del personal a honorarios.</p></div>
            <div><strong><?php echo htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8'); ?></strong><br><span class="muted"><?php echo htmlspecialchars($adminRun, ENT_QUOTES, 'UTF-8'); ?></span></div>
        </header>
        <?php if ($success !== ''): ?><div class="alert ok"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="alert error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        <section class="card" id="smtp" style="margin-bottom:20px">
            <div class="card-head"><h2>Configuración SMTP</h2></div>
            <form method="post" class="smtp-form">
                <input type="hidden" name="action" value="save_smtp">
                <div class="smtp-grid">
                    <div><label>Servidor SMTP</label><input name="smtp_host" value="<?php echo htmlspecialchars((string) ($smtpSettings['host'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="smtp.gmail.com" required></div>
                    <div><label>Puerto</label><input type="number" min="1" max="65535" name="smtp_port" value="<?php echo (int) ($smtpSettings['port'] ?? 587); ?>" required></div>
                    <div><label>Seguridad</label><select name="smtp_encryption"><option value="tls" <?php echo ($smtpSettings['encryption'] ?? 'tls') === 'tls' ? 'selected' : ''; ?>>TLS / STARTTLS</option><option value="ssl" <?php echo ($smtpSettings['encryption'] ?? '') === 'ssl' ? 'selected' : ''; ?>>SSL / SMTPS</option><option value="none" <?php echo ($smtpSettings['encryption'] ?? '') === 'none' ? 'selected' : ''; ?>>Sin cifrado</option></select></div>
                    <div><label>Usuario SMTP</label><input name="smtp_username" value="<?php echo htmlspecialchars((string) ($smtpSettings['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" autocomplete="username" placeholder="correo@dominio.cl"></div>
                    <div class="smtp-span-2"><label>Contraseña SMTP</label><input type="password" name="smtp_password" autocomplete="new-password" placeholder="<?php echo $smtpSettings !== null ? 'Dejar vacío para conservar la contraseña actual' : 'Contraseña o clave de aplicación'; ?>"></div>
                    <div><label>Correo remitente</label><input type="email" name="smtp_from_email" value="<?php echo htmlspecialchars((string) ($smtpSettings['from_email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="no-responder@dominio.cl" required></div>
                    <div><label>Nombre remitente</label><input name="smtp_from_name" value="<?php echo htmlspecialchars((string) ($smtpSettings['from_name'] ?? 'Sistema Honorarios'), ENT_QUOTES, 'UTF-8'); ?>" required></div>
                    <div class="smtp-span-2"><label>Responder a (opcional)</label><input type="email" name="smtp_reply_to" value="<?php echo htmlspecialchars((string) ($smtpSettings['reply_to_email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="rrhh@dominio.cl"></div>
                    <div style="display:flex;align-items:end"><label class="switch" style="padding-bottom:9px"><input type="checkbox" name="smtp_enabled" <?php echo (int) ($smtpSettings['is_enabled'] ?? 0) === 1 ? 'checked' : ''; ?>> Habilitar envíos SMTP</label></div>
                    <div style="display:flex;align-items:end"><button class="btn" type="submit">Guardar SMTP</button></div>
                </div>
                <p class="smtp-note">La contraseña se almacena cifrada. En producción debes conservar la misma variable <strong>SMTP_CONFIG_KEY</strong> del archivo de entorno.</p>
            </form>
            <form method="post" class="smtp-form" style="border-top:1px solid var(--line)">
                <input type="hidden" name="action" value="test_smtp">
                <div class="smtp-actions"><div style="min-width:280px"><label>Correo para prueba</label><input type="email" name="test_email" value="<?php echo htmlspecialchars((string) ($smtpSettings['from_email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required></div><button class="btn light" type="submit">Enviar correo de prueba</button></div>
            </form>
        </section>
        <section class="stats">
            <div class="stat"><strong><?php echo $counts['total']; ?></strong><span>Usuarios registrados</span></div>
            <div class="stat"><strong><?php echo $counts['active']; ?></strong><span>Accesos habilitados</span></div>
            <div class="stat"><strong><?php echo $counts['honorarios']; ?></strong><span>Personal a honorarios</span></div>
            <div class="stat"><strong><?php echo $counts['signatures']; ?></strong><span>Firmas registradas</span></div>
        </section>
        <section class="card">
            <div class="card-head"><h2>Usuarios y permisos</h2></div>
            <div class="table-wrap"><table>
                <thead><tr><th>RUN</th><th>Datos y permisos</th><th>Profesión</th><th>Firma del honorario</th></tr></thead>
                <tbody>
                <?php foreach ($users as $managedUser): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars((string) $managedUser['run'], ENT_QUOTES, 'UTF-8'); ?></strong><br><span class="role"><?php echo htmlspecialchars((string) $managedUser['role'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td>
                            <form method="post" class="user-form">
                                <input type="hidden" name="action" value="update_user"><input type="hidden" name="user_id" value="<?php echo (int) $managedUser['id']; ?>">
                                <input name="first_names" value="<?php echo htmlspecialchars((string) ($managedUser['first_names'] ?? $managedUser['full_name']), ENT_QUOTES, 'UTF-8'); ?>" required placeholder="Nombres" aria-label="Nombres">
                                <input name="last_names" value="<?php echo htmlspecialchars((string) ($managedUser['last_names'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Apellidos" aria-label="Apellidos">
                                <input type="email" name="email" value="<?php echo htmlspecialchars((string) ($managedUser['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Correo" aria-label="Correo">
                                <input name="phone" value="<?php echo htmlspecialchars((string) ($managedUser['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Teléfono" aria-label="Teléfono">
                                <select name="direction_id" aria-label="Dirección"><option value="">Sin dirección</option><?php foreach ($directions as $direction): ?><option value="<?php echo (int) $direction['id']; ?>" <?php echo (int) ($managedUser['direction_id'] ?? 0) === (int) $direction['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $direction['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select>
                                <select name="role" aria-label="Perfil">
                                    <?php foreach ([ROLE_ADMIN => 'Administrador', ROLE_RRHH => 'RRHH', ROLE_FINANZAS => 'Finanzas', ROLE_HONORARIO => 'Honorario', ROLE_DIRECTOR => 'Director'] as $roleValue => $roleLabel): ?>
                                        <option value="<?php echo $roleValue; ?>" <?php echo (string) $managedUser['role'] === $roleValue ? 'selected' : ''; ?>><?php echo $roleLabel; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label class="switch"><input type="checkbox" name="is_active" <?php echo (int) $managedUser['is_active'] === 1 ? 'checked' : ''; ?>> Habilitado</label>
                                <button class="btn small" type="submit">Guardar</button>
                            </form>
                        </td>
                        <td><?php echo htmlspecialchars((string) ($managedUser['profession_experience'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?php if ((string) $managedUser['role'] === ROLE_HONORARIO): ?>
                                <div class="signature">
                                    <?php if ((string) ($managedUser['signature_path'] ?? '') !== ''): ?>
                                        <img src="<?php echo htmlspecialchars((string) $managedUser['signature_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="Firma de <?php echo htmlspecialchars((string) $managedUser['full_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php endif; ?>
                                    <div class="signature-actions">
                                        <form method="post" enctype="multipart/form-data" class="signature-actions">
                                            <input type="hidden" name="action" value="upload_signature"><input type="hidden" name="user_id" value="<?php echo (int) $managedUser['id']; ?>">
                                            <input type="file" name="signature_image" accept="image/png,image/jpeg,image/webp" required>
                                            <button class="btn small" type="submit"><?php echo (string) ($managedUser['signature_path'] ?? '') !== '' ? 'Reemplazar' : 'Adjuntar firma'; ?></button>
                                        </form>
                                        <?php if ((string) ($managedUser['signature_path'] ?? '') !== ''): ?>
                                            <form method="post" onsubmit="return confirm('¿Eliminar esta firma?');"><input type="hidden" name="action" value="delete_signature"><input type="hidden" name="user_id" value="<?php echo (int) $managedUser['id']; ?>"><button class="btn danger small" type="submit">Borrar</button></form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php else: ?><span class="muted">No aplica</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </section>
    </main>
</div>
</body>
</html>

