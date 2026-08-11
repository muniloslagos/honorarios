<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const ROLE_ADMIN = 'ADMINISTRADOR';
const ROLE_RRHH = 'RRHH';
const ROLE_FINANZAS = 'FINANZAS';
const ROLE_HONORARIO = 'HONORARIO';
const ROLE_DIRECTOR = 'DIRECTOR';

function extractRunFromUserInfo(array $userInfo): ?string
{
    $possibleKeys = [
        'run',
        'rut',
        'RolUnico',
        'rolUnico',
        'sub',
    ];

    foreach ($possibleKeys as $key) {
        if (!array_key_exists($key, $userInfo)) {
            continue;
        }

        $value = $userInfo[$key];
        if (is_array($value)) {
            $number = $value['numero'] ?? $value['number'] ?? null;
            $dv = $value['DV'] ?? $value['dv'] ?? null;
            if ($number !== null && $dv !== null) {
                return normalizeRun((string) $number . '-' . (string) $dv);
            }

            continue;
        }

        return normalizeRun((string) $value);
    }

    return null;
}

function normalizeRun(string $run): string
{
    $value = strtoupper(trim($run));
    $value = str_replace('.', '', $value);
    $value = preg_replace('/\s+/', '', $value) ?? $value;

    return $value;
}

function getHonorarioWhitelist(): array
{
    $raw = envValue('HONORARIO_RUN_WHITELIST', '') ?? '';
    if ($raw === '') {
        return [];
    }

    $parts = array_map('trim', explode(',', $raw));
    $parts = array_filter($parts, static fn (string $value): bool => $value !== '');

    return array_map('normalizeRun', $parts);
}

function resolveRoleForUser(array $userInfo): ?string
{
    $run = extractRunFromUserInfo($userInfo);
    if ($run === null) {
        return null;
    }

    $whitelist = getHonorarioWhitelist();
    if (in_array($run, $whitelist, true)) {
        return ROLE_HONORARIO;
    }

    return null;
}

function displayNameFromUserInfo(array $userInfo): string
{
    $possibleKeys = ['name', 'nombreCompleto', 'given_name'];
    foreach ($possibleKeys as $key) {
        $value = $userInfo[$key] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    return 'Usuario';
}
