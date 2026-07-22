<?php
declare(strict_types=1);

require_once __DIR__ . '/src/claveunica.php';

$config = getClaveUnicaConfig();
$authUrl = buildClaveUnicaAuthUrl();

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Diagnostico ClaveUnica</title>
    <style>
        body { font-family: "Segoe UI", Tahoma, sans-serif; margin: 24px; color: #10253f; }
        .box { background: #f7fbff; border: 1px solid #cfe0f5; border-radius: 10px; padding: 16px; margin-bottom: 14px; }
        code { white-space: pre-wrap; word-break: break-word; }
        .ok { color: #146c43; }
        .warn { color: #8a4b00; }
    </style>
</head>
<body>
    <h1>Diagnostico de integracion ClaveUnica</h1>

    <div class="box">
        <h2>Redirect URI enviada por el sistema</h2>
        <code><?php echo h((string) ($config['redirect_uri'] ?? '')); ?></code>
    </div>

    <div class="box">
        <h2>Authorization URL generada</h2>
        <code><?php echo h($authUrl); ?></code>
    </div>

    <div class="box">
        <h2>Checklist rapido</h2>
        <p class="warn">La Redirect URI registrada en ClaveUnica debe ser exactamente igual (incluye protocolo, dominio, ruta, slash y puerto).</p>
        <ul>
            <li>Si la registrada usa https y aqui sale http, fallara.</li>
            <li>Si la registrada usa 127.0.0.1 y aqui sale localhost, fallara.</li>
            <li>Si falta o sobra slash al final, puede fallar.</li>
            <li>Si el Client ID es de QA/Produccion y la URI es de Sandbox, fallara.</li>
        </ul>
        <p class="ok">Si todo coincide de forma exacta, el error de Redirect URI desaparece.</p>
    </div>
</body>
</html>
