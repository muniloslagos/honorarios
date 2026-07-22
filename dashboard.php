<?php
declare(strict_types=1);

require_once __DIR__ . '/src/auth.php';

$user = requireRole(ROLE_HONORARIO);
$name = htmlspecialchars((string) ($user['name'] ?? 'Usuario'), ENT_QUOTES, 'UTF-8');
$run = htmlspecialchars((string) ($user['run'] ?? '-'), ENT_QUOTES, 'UTF-8');
$loggedAt = htmlspecialchars((string) ($user['logged_at'] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard Honorario</title>
    <style>
        :root {
            --bg: #f3f7fb;
            --card: #ffffff;
            --text: #17324a;
            --muted: #4e657b;
            --ok: #157347;
            --accent: #0b7285;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, sans-serif;
            background: linear-gradient(180deg, #e8f4f2 0%, var(--bg) 40%);
            color: var(--text);
        }
        header {
            padding: 22px 20px;
            background: white;
            border-bottom: 1px solid #dce8f3;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .title { font-size: 1.25rem; margin: 0; }
        .logout {
            text-decoration: none;
            background: #f1f7ff;
            color: #1e4d8d;
            border: 1px solid #cfe1fb;
            padding: 8px 12px;
            border-radius: 10px;
            font-weight: 600;
        }
        .container {
            width: min(1100px, 100%);
            margin: 22px auto;
            padding: 0 20px 24px;
        }
        .hero {
            background: var(--card);
            border-radius: 18px;
            padding: 22px;
            box-shadow: 0 16px 40px rgba(23, 50, 74, 0.09);
            margin-bottom: 18px;
        }
        .badge {
            display: inline-block;
            background: #e6f6ee;
            color: var(--ok);
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 0.86rem;
            margin-bottom: 10px;
        }
        .meta {
            color: var(--muted);
            font-size: 0.95rem;
            margin-top: 8px;
        }
        .cards {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        }
        .card {
            background: var(--card);
            border-radius: 14px;
            padding: 16px;
            border: 1px solid #e4edf5;
        }
        .card h3 { margin-top: 0; margin-bottom: 8px; }
        .card p { margin: 0; color: var(--muted); line-height: 1.45; }
        .card a {
            margin-top: 10px;
            display: inline-block;
            text-decoration: none;
            color: var(--accent);
            font-weight: 700;
        }
    </style>
</head>
<body>
    <header>
        <h1 class="title">Panel del Personal a Honorarios</h1>
        <a class="logout" href="logout.php">Cerrar sesion</a>
    </header>

    <main class="container">
        <section class="hero">
            <span class="badge">Perfil activo: HONORARIO</span>
            <h2>Bienvenido, <?php echo $name; ?></h2>
            <p class="meta">RUN: <?php echo $run; ?> | Ingreso: <?php echo $loggedAt; ?></p>
        </section>

        <section class="cards">
            <article class="card">
                <h3>Mis convenios</h3>
                <p>Revisa el historial de convenios cargados y su estado de firma.</p>
                <a href="#">Ver convenios</a>
            </article>
            <article class="card">
                <h3>Mis decretos</h3>
                <p>Consulta decretos asociados a tus periodos de trabajo.</p>
                <a href="#">Ver decretos</a>
            </article>
            <article class="card">
                <h3>Crear informe</h3>
                <p>Acceso al editor de informes mensuales para su envio.</p>
                <a href="#">Crear informe</a>
            </article>
            <article class="card">
                <h3>Cargar PDF firmado</h3>
                <p>Sube tu convenio firmado para validacion administrativa.</p>
                <a href="#">Subir PDF</a>
            </article>
        </section>
    </main>
</body>
</html>
