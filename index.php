<?php
declare(strict_types=1);

require_once __DIR__ . '/src/auth.php';

if (isAuthenticated()) {
    redirectTo('dashboard.php');
}

$envExists = is_file(__DIR__ . '/.env');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ingreso | Sistema Honorarios</title>
    <style>
        :root {
            --bg-1: #f5f8ff;
            --bg-2: #dff3f0;
            --text: #10253f;
            --accent: #006d77;
            --accent-2: #0a9396;
            --card: #ffffff;
            --muted: #49627a;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, sans-serif;
            color: var(--text);
            background: radial-gradient(circle at top right, var(--bg-2), var(--bg-1) 55%);
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 20px;
        }
        .card {
            width: min(700px, 100%);
            background: var(--card);
            border-radius: 20px;
            padding: 32px;
            box-shadow: 0 24px 60px rgba(16, 37, 63, 0.12);
        }
        h1 { margin: 0 0 8px; font-size: clamp(1.7rem, 2vw, 2.2rem); }
        p { margin: 0 0 18px; color: var(--muted); line-height: 1.45; }
        .roles {
            display: grid;
            gap: 10px;
            margin: 18px 0 28px;
        }
        .role {
            background: #f7fafc;
            border: 1px solid #e6edf5;
            border-radius: 12px;
            padding: 10px 12px;
            font-size: 0.95rem;
        }
        .btn {
            border: 0;
            border-radius: 12px;
            padding: 12px 18px;
            font-weight: 700;
            cursor: pointer;
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            color: #fff;
            text-decoration: none;
            display: inline-block;
        }
        .foot {
            margin-top: 14px;
            font-size: 0.9rem;
            color: var(--muted);
        }
        .warn {
            margin-top: 8px;
            color: #8c3b0e;
            background: #fff6ee;
            border: 1px solid #ffd9bf;
            padding: 10px;
            border-radius: 10px;
        }
    </style>
</head>
<body>
    <main class="card">
        <h1>Sistema de Administracion de Honorarios</h1>
        <p>Inicio de sesion con ClaveUnica o con credenciales locales de desarrollo para trabajar en paralelo.</p>

        <div class="roles">
            <div class="role"><strong>Administrador:</strong> habilitado para pruebas locales</div>
            <div class="role"><strong>RRHH:</strong> habilitado para pruebas locales</div>
            <div class="role"><strong>Finanzas:</strong> habilitado para pruebas locales</div>
            <div class="role"><strong>Honorario:</strong> activo para login y dashboard</div>
        </div>

        <a class="btn" href="login.php">Ingresar modo local</a>
        <a class="btn" href="login.php?mode=claveunica" style="margin-left: 8px; background: linear-gradient(135deg, #0b7285, #2c7a7b);">Ingresar con ClaveUnica</a>

        <?php if (!$envExists): ?>
            <div class="warn">No existe .env en este despliegue. El sistema cargo .env.example como respaldo. Crea .env en el servidor para definir credenciales reales y RUN autorizados.</div>
        <?php endif; ?>

        <p class="foot">Si tu RUN no esta en la lista permitida del perfil Honorario, el acceso sera rechazado.</p>
    </main>
</body>
</html>
