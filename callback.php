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

$tokenResponse = exchangeCodeForToken($code);
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

$role = resolveRoleForUser($userInfo);
if ($role !== ROLE_HONORARIO) {
    http_response_code(403);
    $run = extractRunFromUserInfo($userInfo) ?? 'desconocido';
    echo 'Acceso denegado. El RUN ' . htmlspecialchars($run, ENT_QUOTES, 'UTF-8') . ' no tiene perfil HONORARIO habilitado.';
    exit;
}

loginUser($userInfo, $role);
redirectTo('dashboard.php');
