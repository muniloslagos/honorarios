<?php
declare(strict_types=1);

require_once __DIR__ . '/src/claveunica.php';
require_once __DIR__ . '/src/auth.php';

$state = $_GET['state'] ?? '';
$code = $_GET['code'] ?? '';
$error = $_GET['error'] ?? '';

if ($error !== '') {
    http_response_code(401);
    echo 'Autenticacion cancelada o rechazada: ' . htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8');
    exit;
}

if (!isset($_SESSION['oauth_state']) || !hash_equals((string) $_SESSION['oauth_state'], (string) $state)) {
    http_response_code(400);
    echo 'Estado OAuth invalido. Intenta nuevamente.';
    exit;
}

unset($_SESSION['oauth_state']);

if (!is_string($code) || trim($code) === '') {
    http_response_code(400);
    echo 'No se recibio el codigo de autorizacion.';
    exit;
}

$tokenResponse = exchangeCodeForToken($code, (string) $state);
if (isset($tokenResponse['error']) || !isset($tokenResponse['access_token'])) {
    http_response_code(401);
    echo 'No se pudo obtener token de acceso.';
    echo '<pre>' . htmlspecialchars(json_encode($tokenResponse, JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

$userInfo = fetchUserInfo((string) $tokenResponse['access_token']);
if (isset($userInfo['error'])) {
    http_response_code(401);
    echo 'No se pudo obtener la informacion del usuario.';
    echo '<pre>' . htmlspecialchars(json_encode($userInfo, JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

$run = extractRunFromUserInfo($userInfo);
$role = null;
if ($run !== null) {
    try {
        require_once __DIR__ . '/src/db.php';
        $roleStmt = db()->prepare('SELECT role FROM system_users WHERE run = :run AND is_active = 1 ORDER BY id LIMIT 1');
        $roleStmt->execute(['run' => $run]);
        $storedRole = $roleStmt->fetchColumn();
        if (is_string($storedRole) && $storedRole !== '') $role = $storedRole;
    } catch (Throwable $e) {
        $role = null;
    }
}
$role = $role ?? resolveRoleForUser($userInfo);
if (!in_array($role, [ROLE_HONORARIO, ROLE_DIRECTOR, ROLE_ADMIN, ROLE_RRHH, ROLE_FINANZAS], true)) {
    http_response_code(403);
    echo 'Acceso denegado. El RUN ' . htmlspecialchars((string) ($run ?? 'desconocido'), ENT_QUOTES, 'UTF-8') . ' no tiene un perfil habilitado.';
    exit;
}

loginUser($userInfo, (string) $role);
redirectToRoleHome();
