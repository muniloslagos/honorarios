<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function getClaveUnicaConfig(): array
{
    return [
        'client_id' => envValue('CU_CLIENT_ID', ''),
        'client_secret' => envValue('CU_CLIENT_SECRET', ''),
        'redirect_uri' => envValue('CU_REDIRECT_URI', appUrl('callback.php')),
        'auth_url' => rtrim(envValue('CU_AUTH_URL', 'https://accounts.claveunica.gob.cl/openid/authorize/') ?? '', '/'),
        'token_url' => rtrim(envValue('CU_TOKEN_URL', 'https://accounts.claveunica.gob.cl/openid/token/') ?? '', '/'),
        'userinfo_url' => rtrim(envValue('CU_USERINFO_URL', 'https://accounts.claveunica.gob.cl/openid/userinfo/') ?? '', '/'),
    ];
}

function requireClaveUnicaConfig(): array
{
    $config = getClaveUnicaConfig();
    $requiredKeys = ['client_id', 'client_secret', 'redirect_uri', 'auth_url', 'token_url', 'userinfo_url'];

    foreach ($requiredKeys as $key) {
        if (($config[$key] ?? '') === '') {
            http_response_code(500);
            echo 'Falta configurar ' . $key . ' en .env';
            exit;
        }
    }

    return $config;
}

function generateState(): string
{
    return bin2hex(random_bytes(16));
}

function buildClaveUnicaAuthUrl(): string
{
    $config = requireClaveUnicaConfig();
    $state = generateState();
    $_SESSION['oauth_state'] = $state;

    $params = [
        'client_id' => $config['client_id'],
        'response_type' => 'code',
        'redirect_uri' => $config['redirect_uri'],
        'scope' => 'openid run name',
        'state' => $state,
    ];

    return $config['auth_url'] . '?' . http_build_query($params);
}

function exchangeCodeForToken(string $code): array
{
    $config = requireClaveUnicaConfig();

    $postData = http_build_query([
        'grant_type' => 'authorization_code',
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'code' => $code,
        'redirect_uri' => $config['redirect_uri'],
    ]);

    return httpRequest($config['token_url'], 'POST', [
        'Content-Type: application/x-www-form-urlencoded',
    ], $postData);
}

function fetchUserInfo(string $accessToken): array
{
    $config = requireClaveUnicaConfig();

    return httpRequest($config['userinfo_url'], 'GET', [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json',
    ]);
}

function httpRequest(string $url, string $method, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['error' => 'No fue posible iniciar CURL'];
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $responseBody = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false || $error !== '') {
        return ['error' => 'Error HTTP: ' . $error];
    }

    $data = json_decode($responseBody, true);
    if (!is_array($data)) {
        return [
            'error' => 'Respuesta no valida del servidor de autenticacion',
            'http_code' => $httpCode,
            'raw' => $responseBody,
        ];
    }

    $data['http_code'] = $httpCode;

    return $data;
}
