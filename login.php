<?php
declare(strict_types=1);

require_once __DIR__ . '/src/claveunica.php';

$authUrl = buildClaveUnicaAuthUrl();
header('Location: ' . $authUrl);
exit;
