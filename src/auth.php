<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/roles.php';

function isAuthenticated(): bool
{
    return isset($_SESSION['auth']) && is_array($_SESSION['auth']);
}

function currentUser(): ?array
{
    if (!isAuthenticated()) {
        return null;
    }

    return $_SESSION['auth'];
}

function requireLogin(): array
{
    $user = currentUser();
    if ($user === null) {
        redirectTo('index.php');
    }

    return $user;
}

function requireRole(string $role): array
{
    $user = requireLogin();
    if (($user['role'] ?? null) !== $role) {
        http_response_code(403);
        echo 'No tienes permisos para acceder a esta seccion.';
        exit;
    }

    return $user;
}

function loginUser(array $userInfo, string $role): void
{
    $_SESSION['auth'] = [
        'role' => $role,
        'name' => displayNameFromUserInfo($userInfo),
        'run' => extractRunFromUserInfo($userInfo),
        'user_info' => $userInfo,
        'logged_at' => date('c'),
    ];
}

function logoutUser(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
}
