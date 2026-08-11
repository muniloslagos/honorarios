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

    $chileTime = new DateTimeImmutable('now', new DateTimeZone('America/Santiago'));
    $expiration = $chileTime->modify('+10 minutes')->format('Y-m-d\TH:i:s');

    $header = base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
    $payload = base64UrlEncode(json_encode([
        'entity' => $entity,
        'run' => firmaGobRunBody($run),
        'expiration' => $expiration,
        'purpose' => 'Desatendido',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $signature = base64UrlEncode(hash_hmac('sha256', $header . '.' . $payload, $secret, true));
    return $header . '.' . $payload . '.' . $signature;
}

function firmaGobFitFontSize(string $text, string $fontPath, int $preferred, int $minimum, int $maximumWidth): int
{
    for ($size = $preferred; $size >= $minimum; $size--) {
        $box = imagettfbbox($size, 0, $fontPath, $text);
        if ($box !== false && abs($box[2] - $box[0]) <= $maximumWidth) {
            return $size;
        }
    }
    return $minimum;
}

function firmaGobCreateVisibleStamp(string $sourceImagePath, string $signerName, array $roleLines, DateTimeInterface $signedAt): string
{
    if (!extension_loaded('gd') || !function_exists('imagettftext')) {
        throw new RuntimeException('La extension GD de PHP es necesaria para preparar el sello visible de FirmaGob.');
    }
    $sourceBytes = @file_get_contents($sourceImagePath);
    $source = $sourceBytes === false ? false : @imagecreatefromstring($sourceBytes);
    if ($source === false) {
        throw new RuntimeException('No fue posible leer la imagen de firma del director.');
    }

    $width = 885;
    $height = 293;
    $stamp = imagecreatetruecolor($width, $height);
    if ($stamp === false) {
        imagedestroy($source);
        throw new RuntimeException('No fue posible preparar la apariencia visible de la firma.');
    }
    imagealphablending($stamp, false);
    imagesavealpha($stamp, true);
    $transparent = imagecolorallocatealpha($stamp, 255, 255, 255, 127);
    imagefilledrectangle($stamp, 0, 0, $width, $height, $transparent);
    imagealphablending($stamp, true);
    imagecopyresampled($stamp, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
    imagedestroy($source);

    $regularFont = __DIR__ . '/../assets/fonts/Ubuntu-R.ttf';
    $boldFont = __DIR__ . '/../assets/fonts/Ubuntu-B.ttf';
    if (!is_file($regularFont) || !is_file($boldFont)) {
        imagedestroy($stamp);
        throw new RuntimeException('No estan disponibles las fuentes necesarias para preparar el sello de FirmaGob.');
    }

    $black = imagecolorallocate($stamp, 0, 0, 0);
    $textX = 316;
    $maximumWidth = 545;
    $heading = 'Firmado digitalmente por:';
    $name = function_exists('mb_strtoupper') ? mb_strtoupper($signerName, 'UTF-8') : strtoupper($signerName);
    $dateLine = 'Fecha: ' . $signedAt->format('d.m.Y H:i:s');
    imagettftext($stamp, firmaGobFitFontSize($heading, $regularFont, 24, 17, $maximumWidth), 0, $textX, 62, $black, $regularFont, $heading);
    imagettftext($stamp, firmaGobFitFontSize($name, $boldFont, 25, 17, $maximumWidth), 0, $textX, 106, $black, $boldFont, $name);
    imagettftext($stamp, firmaGobFitFontSize($dateLine, $regularFont, 24, 17, $maximumWidth), 0, $textX, 150, $black, $regularFont, $dateLine);

    $roles = array_values(array_filter(array_map(static fn($value): string => trim((string) $value), $roleLines)));
    if ($roles !== []) {
        imagettftext($stamp, firmaGobFitFontSize($roles[0], $regularFont, 25, 16, $maximumWidth), 0, $textX, 219, $black, $regularFont, $roles[0]);
    }
    if (isset($roles[1])) {
        imagettftext($stamp, firmaGobFitFontSize($roles[1], $regularFont, 23, 15, $maximumWidth), 0, $textX, 258, $black, $regularFont, $roles[1]);
    }

    ob_start();
    imagepng($stamp, null, 6);
    $pngBytes = ob_get_clean();
    imagedestroy($stamp);
    if (!is_string($pngBytes) || $pngBytes === '') {
        throw new RuntimeException('No fue posible generar la imagen visible de la firma.');
    }
    return $pngBytes;
}

function firmaGobVisibleLayout(string $stampImageBytes, array $rectangle, string $page = 'LAST'): string
{
    $coordinates = [];
    foreach (['llx', 'lly', 'urx', 'ury'] as $key) {
        if (!isset($rectangle[$key]) || !is_numeric($rectangle[$key])) {
            throw new RuntimeException('Las coordenadas de la firma visible no son validas.');
        }
        $coordinates[$key] = (int) round((float) $rectangle[$key]);
        if ($coordinates[$key] < 0 || $coordinates[$key] > 2000) {
            throw new RuntimeException('Las coordenadas de la firma visible estan fuera del documento.');
        }
    }
    if ($coordinates['urx'] <= $coordinates['llx'] || $coordinates['ury'] <= $coordinates['lly']) {
        throw new RuntimeException('El rectangulo de la firma visible no es valido.');
    }
    if (@getimagesizefromstring($stampImageBytes) === false) {
        throw new RuntimeException('La apariencia visible de la firma no es una imagen valida.');
    }
    $pageValue = strtoupper($page) === 'LAST' ? 'LAST' : (string) max(1, (int) $page);
    $encodedImage = base64_encode($stampImageBytes);
    return '<AgileSignerConfig><Application id="THIS-CONFIG"><pdfPassword/><Signature>'
        . '<Visible active="true" layer2="false" label="true" pos="1">'
        . '<llx>' . $coordinates['llx'] . '</llx><lly>' . $coordinates['lly'] . '</lly>'
        . '<urx>' . $coordinates['urx'] . '</urx><ury>' . $coordinates['ury'] . '</ury>'
        . '<page>' . $pageValue . '</page><image>BASE64</image><BASE64VALUE>' . $encodedImage . '</BASE64VALUE>'
        . '</Visible></Signature></Application></AgileSignerConfig>';
}

function signPdfWithFirmaGob(string $pdfBytes, string $run, string $description, ?string $layout = null): string
{
    $endpoint = trim((string) envValue('FIRMAGOB_API_URL', 'https://api.firma.digital.gob.cl/firma/v2/files/tickets'));
    $tokenKey = trim((string) envValue('FIRMAGOB_TOKEN_KEY', ''));
    if ($tokenKey === '') {
        throw new RuntimeException('FirmaGob no está configurado. Falta FIRMAGOB_TOKEN_KEY.');
    }
    if (strlen($pdfBytes) > 5 * 1024 * 1024) {
        throw new RuntimeException('El PDF supera el máximo de 5 MB permitido por FirmaGob.');
    }

    $filePayload = [
        'description' => $description,
        'checksum' => hash('sha256', $pdfBytes),
        'content' => base64_encode($pdfBytes),
        'content-type' => 'application/pdf',
    ];
    if ($layout !== null && $layout !== '') {
        $filePayload['layout'] = $layout;
    }
    $payload = json_encode([
        'token' => firmaGobJwt($run),
        'api_token_key' => $tokenKey,
        'files' => [$filePayload],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new RuntimeException('No fue posible preparar la solicitud de FirmaGob.');
    }

    if (strlen($payload) > 5 * 1024 * 1024) {
        throw new RuntimeException('La solicitud completa supera el maximo de 5 MB permitido por FirmaGob.');
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

