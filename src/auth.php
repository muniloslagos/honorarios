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

function homePathForRole(string $role): string
{
    if ($role === ROLE_ADMIN) return 'admin.php';
    if ($role === ROLE_DIRECTOR) return 'director.php';
    if ($role === ROLE_FINANZAS) return 'finanzas.php';
    return 'dashboard.php';
}

function redirectToRoleHome(?array $user = null): never
{
    $resolvedUser = $user ?? requireLogin();
    redirectTo(homePathForRole((string) ($resolvedUser['role'] ?? '')));
}
function isLocalAuthEnabled(): bool
{
    $flag = envValue('LOCAL_AUTH_ENABLED', 'false');
    if (filter_var($flag, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== null) {
        return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
    }

    $mode = strtolower((string) envValue('AUTH_MODE', envValue('APP_ENV', 'production')));

    return $mode === 'local' || $mode === 'development' || $mode === 'dev';
}

function getLocalAuthProfiles(): array
{
    return [
        ROLE_ADMIN => [
            'username' => envValue('LOCAL_AUTH_ADMIN_USERNAME', 'admin'),
            'password' => envValue('LOCAL_AUTH_ADMIN_PASSWORD', 'admin123'),
            'run' => envValue('LOCAL_AUTH_ADMIN_RUN', '11111111-1'),
            'name' => envValue('LOCAL_AUTH_ADMIN_NAME', 'Administrador Local'),
            'profession' => envValue('LOCAL_AUTH_ADMIN_PROFESSION', 'Administración'),
        ],
        ROLE_RRHH => [
            'username' => envValue('LOCAL_AUTH_RRHH_USERNAME', 'rrhh'),
            'password' => envValue('LOCAL_AUTH_RRHH_PASSWORD', 'rrhh123'),
            'run' => envValue('LOCAL_AUTH_RRHH_RUN', '22222222-2'),
            'name' => envValue('LOCAL_AUTH_RRHH_NAME', 'RRHH Local'),
            'profession' => envValue('LOCAL_AUTH_RRHH_PROFESSION', 'RRHH'),
        ],
        ROLE_FINANZAS => [
            'username' => envValue('LOCAL_AUTH_FINANZAS_USERNAME', 'finanzas'),
            'password' => envValue('LOCAL_AUTH_FINANZAS_PASSWORD', 'finanzas123'),
            'run' => envValue('LOCAL_AUTH_FINANZAS_RUN', '33333333-3'),
            'name' => envValue('LOCAL_AUTH_FINANZAS_NAME', 'Finanzas Local'),
            'profession' => envValue('LOCAL_AUTH_FINANZAS_PROFESSION', 'Finanzas'),
        ],
        ROLE_HONORARIO => [
            'username' => envValue('LOCAL_AUTH_HONORARIO_USERNAME', 'honorario'),
            'password' => envValue('LOCAL_AUTH_HONORARIO_PASSWORD', 'honorario123'),
            'run' => envValue('LOCAL_AUTH_HONORARIO_RUN', '44444444-4'),
            'name' => envValue('LOCAL_AUTH_HONORARIO_NAME', 'Honorario Local'),
            'profession' => envValue('LOCAL_AUTH_HONORARIO_PROFESSION', 'Administración Pública'),
        ],
    ];
}

function authenticateLocalUser(string $username, string $password): ?array
{
    $normalizedUsername = strtolower(trim($username));
    $normalizedPassword = (string) $password;

    foreach (getLocalAuthProfiles() as $role => $profile) {
        $profileUsername = strtolower(trim((string) ($profile['username'] ?? '')));
        $profilePassword = (string) ($profile['password'] ?? '');

        if ($profileUsername === $normalizedUsername && $profilePassword === $normalizedPassword) {
            $resolvedRole = $role;
            try {
                require_once __DIR__ . '/db.php';
                $userStmt = db()->prepare('SELECT role, is_active FROM system_users WHERE run = :run ORDER BY (role = :configured_role) DESC, id ASC LIMIT 1');
                $userStmt->execute(['run' => (string) ($profile['run'] ?? ''), 'configured_role' => $role]);
                $storedUser = $userStmt->fetch();
                if ($storedUser !== false) {
                    if ((int) $storedUser['is_active'] !== 1) {
                        return null;
                    }
                    $resolvedRole = (string) $storedUser['role'];
                }
            } catch (Throwable $e) {
                // Permite el primer acceso local antes de inicializar la base de datos.
            }

            return [
                'role' => $resolvedRole,
                'user_info' => [
                    'run' => (string) ($profile['run'] ?? ''),
                    'name' => (string) ($profile['name'] ?? 'Usuario Local'),
                    'profesion' => (string) ($profile['profession'] ?? ''),
                    'local' => true,
                    'username' => $profileUsername,
                ],
            ];
        }
    }

    try {
        require_once __DIR__ . '/db.php';
        $stmt = db()->prepare("SELECT u.run, u.full_name, u.profession_experience, dp.local_password_hash FROM director_profiles dp INNER JOIN system_users u ON u.id = dp.system_user_id WHERE dp.local_username = :username AND dp.is_active = 1 AND u.is_active = 1 AND u.role = 'DIRECTOR' LIMIT 1");
        $stmt->execute(['username' => $normalizedUsername]);
        $director = $stmt->fetch();
        if ($director !== false && password_verify($normalizedPassword, (string) $director['local_password_hash'])) {
            return ['role' => ROLE_DIRECTOR, 'user_info' => ['run' => (string) $director['run'], 'name' => (string) $director['full_name'], 'profesion' => (string) ($director['profession_experience'] ?? ''), 'local' => true, 'username' => $normalizedUsername]];
        }
    } catch (Throwable $e) {
        // La migración de directores puede no estar instalada todavía.
    }

    return null;
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
