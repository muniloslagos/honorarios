<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function base64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function firmaGobRunBody(string $run): string
{
    $normalized = strtoupper(preg_replace('/[^0-9K]/i', '', $run) ?? '');
    if (strlen($normalized) < 2) {
        throw new RuntimeException('El RUN del director no es válido.');
    }
    return substr($normalized, 0, -1);
}

function firmaGobIsConfigured(): bool
{
    return trim((string) envValue('FIRMAGOB_ENTITY', '')) !== ''
        && trim((string) envValue('FIRMAGOB_TOKEN_KEY', '')) !== ''
        && trim((string) envValue('FIRMAGOB_SECRET', '')) !== '';
}

function firmaGobJwt(string $run): string
{
    $secret = trim((string) envValue('FIRMAGOB_SECRET', ''));
    $entity = trim((string) envValue('FIRMAGOB_ENTITY', ''));
    if ($secret === '' || $entity === '') {
        throw new RuntimeException('FirmaGob no está configurado. Faltan FIRMAGOB_SECRET o FIRMAGOB_ENTITY.');
    }

    $header = base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
    $payload = base64UrlEncode(json_encode([
        'entity' => $entity,
        'run' => firmaGobRunBody($run),
        'expiration' => (new DateTimeImmutable('+10 minutes'))->format('Y-m-d\TH:i:s'),
        'purpose' => 'Desatendido',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $signature = base64UrlEncode(hash_hmac('sha256', $header . '.' . $payload, $secret, true));
    return $header . '.' . $payload . '.' . $signature;
}

function signPdfWithFirmaGob(string $pdfBytes, string $run, string $description): string
{
    $endpoint = trim((string) envValue('FIRMAGOB_API_URL', 'https://api.firma.digital.gob.cl/firma/v2/files/tickets'));
    $tokenKey = trim((string) envValue('FIRMAGOB_TOKEN_KEY', ''));
    if ($tokenKey === '') {
        throw new RuntimeException('FirmaGob no está configurado. Falta FIRMAGOB_TOKEN_KEY.');
    }
    if (strlen($pdfBytes) > 5 * 1024 * 1024) {
        throw new RuntimeException('El PDF supera el máximo de 5 MB permitido por FirmaGob.');
    }

    $payload = json_encode([
        'token' => firmaGobJwt($run),
        'api_token_key' => $tokenKey,
        'files' => [[
            'description' => $description,
            'checksum' => hash('sha256', $pdfBytes),
            'content' => base64_encode($pdfBytes),
            'content-type' => 'application/pdf',
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new RuntimeException('No fue posible preparar la solicitud de FirmaGob.');
    }

    $curl = curl_init($endpoint);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($response === false || $curlError !== '') {
        throw new RuntimeException('No fue posible conectar con FirmaGob: ' . $curlError);
    }
    $decoded = json_decode((string) $response, true);
    if ($status !== 200 || !is_array($decoded)) {
        $apiMessage = is_array($decoded) ? (string) ($decoded['error'] ?? $decoded['message'] ?? '') : '';
        throw new RuntimeException('FirmaGob rechazó la solicitud' . ($apiMessage !== '' ? ': ' . $apiMessage : ' (HTTP ' . $status . ').'));
    }

    $file = $decoded['files'][0] ?? null;
    if (!is_array($file) || strtoupper((string) ($file['status'] ?? '')) !== 'OK' || empty($file['content'])) {
        throw new RuntimeException('FirmaGob no devolvió el documento firmado.');
    }
    $signed = base64_decode((string) $file['content'], true);
    if ($signed === false || !str_starts_with($signed, '%PDF-')) {
        throw new RuntimeException('La respuesta de FirmaGob no contiene un PDF válido.');
    }
    return $signed;
}

