<?php
declare(strict_types=1);

require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/claveunica.php';

if (isAuthenticated()) {
    redirectTo('dashboard.php');
}

$error = '';
$requestedMode = (string) ($_GET['mode'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $localUser = authenticateLocalUser($username, $password);
    if ($localUser !== null) {
        loginUser($localUser['user_info'], $localUser['role']);
        redirectTo($localUser['role'] === ROLE_HONORARIO ? 'dashboard.php' : 'index.php');
    }

    $error = 'Credenciales locales invalidas. Revisa el usuario y la clave de desarrollo.';
} elseif (!isLocalAuthEnabled() || $requestedMode === 'claveunica') {
    $authUrl = buildClaveUnicaAuthUrl();
    header('Location: ' . $authUrl);
    exit;
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ingreso local | Sistema Honorarios</title>
    <style>
        body{font-family:"Segoe UI",Tahoma,sans-serif;background:#f3f7fb;margin:0;color:#12324a}
        .wrap{max-width:520px;margin:40px auto;padding:20px}
        .card{background:#fff;border:1px solid #dce9f5;border-radius:16px;padding:24px;box-shadow:0 20px 60px rgba(11,32,54,.1)}
        h1{margin-top:0}
        label{display:block;margin-bottom:6px;font-weight:700}
        input{width:100%;padding:10px 12px;border:1px solid #cfdce9;border-radius:10px;margin-bottom:10px}
        button{background:#0b7285;color:#fff;border:0;border-radius:10px;padding:10px 16px;cursor:pointer;font-weight:700}
        .hint{background:#f7fbff;border:1px solid #dceaf6;padding:10px;border-radius:10px;margin-top:12px;font-size:.95rem}
        .error{background:#fff0ef;border:1px solid #f2b5ae;color:#9a1b14;padding:10px;border-radius:10px;margin-bottom:12px}
        .muted{color:#567388;font-size:.92rem;margin-top:10px}
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Acceso local</h1>
            <p>Usa este formulario para trabajar de forma local sin depender de ClaveÚnica.</p>
            <?php if ($error !== ''): ?><div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <form method="post">
                <label>Usuario</label>
                <input name="username" required>
                <label>Clave</label>
                <input type="password" name="password" required>
                <button type="submit">Ingresar</button>
            </form>
            <div class="hint">
                <strong>Credenciales de desarrollo:</strong>
                <ul>
                    <li>Administrador: admin / admin123</li>
                    <li>RRHH: rrhh / rrhh123</li>
                    <li>Finanzas: finanzas / finanzas123</li>
                    <li>Honorario: honorario / honorario123</li>
                </ul>
            </div>
            <p class="muted"><a href="login.php?mode=claveunica">Usar ClaveÚnica</a> si prefieres el flujo de producción.</p>
        </div>
    </div>
</body>
</html>
