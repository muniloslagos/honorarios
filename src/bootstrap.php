<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$envPath = __DIR__ . '/../.env';
$envExamplePath = __DIR__ . '/../.env.example';

loadEnv(is_file($envPath) ? $envPath : $envExamplePath);

function loadEnv(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', $trimmed, 2), 2, '');
        $key = trim($key);
        $value = trim($value);

        if ($key === '') {
            continue;
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }
}

function envValue(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return (string) $value;
}

function appUrl(string $path = ''): string
{
    $base = rtrim(envValue('APP_URL', 'https://app.muniloslagos.cl/honorarios') ?? 'https://app.muniloslagos.cl/honorarios', '/');
    $path = ltrim($path, '/');

    return $path === '' ? $base : $base . '/' . $path;
}

function redirectTo(string $path): never
{
    header('Location: ' . appUrl($path));
    exit;
}
