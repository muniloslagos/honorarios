<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src/Exception.php';
require_once dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

function ensureSmtpSettingsTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS smtp_settings (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        host VARCHAR(255) NOT NULL,
        port SMALLINT UNSIGNED NOT NULL DEFAULT 587,
        encryption ENUM('tls', 'ssl', 'none') NOT NULL DEFAULT 'tls',
        username VARCHAR(255) NOT NULL,
        password_encrypted TEXT NOT NULL,
        from_email VARCHAR(180) NOT NULL,
        from_name VARCHAR(180) NOT NULL,
        reply_to_email VARCHAR(180) NULL,
        is_enabled TINYINT(1) NOT NULL DEFAULT 0,
        updated_by_user_id BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_smtp_settings_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES system_users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");
}

function smtpEncryptionKey(): string
{
    $configured = trim((string) envValue('SMTP_CONFIG_KEY', ''));
    $decoded = $configured !== '' ? base64_decode($configured, true) : false;
    if ($decoded === false || strlen($decoded) !== 32) {
        throw new RuntimeException('SMTP_CONFIG_KEY debe ser una clave Base64 de 32 bytes.');
    }
    return $decoded;
}

function encryptSmtpPassword(string $password): string
{
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($password, 'aes-256-gcm', smtpEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false) throw new RuntimeException('No fue posible cifrar la contraseña SMTP.');
    return base64_encode($iv . $tag . $ciphertext);
}

function decryptSmtpPassword(string $encrypted): string
{
    $payload = base64_decode($encrypted, true);
    if ($payload === false || strlen($payload) < 29) throw new RuntimeException('La contraseña SMTP almacenada no es válida.');
    $iv = substr($payload, 0, 12);
    $tag = substr($payload, 12, 16);
    $ciphertext = substr($payload, 28);
    $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', smtpEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) throw new RuntimeException('No fue posible descifrar la contraseña SMTP.');
    return $plain;
}

function getSmtpSettings(PDO $pdo): ?array
{
    ensureSmtpSettingsTable($pdo);
    $stmt = $pdo->query('SELECT * FROM smtp_settings WHERE id = 1 LIMIT 1');
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function sendSmtpMail(string $recipientEmail, string $recipientName, string $subject, string $htmlBody, string $textBody = ''): void
{
    $pdo = db();
    $settings = getSmtpSettings($pdo);
    if ($settings === null || (int) $settings['is_enabled'] !== 1) {
        throw new RuntimeException('El envío SMTP no está configurado o se encuentra deshabilitado.');
    }

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = (string) $settings['host'];
    $mail->Port = (int) $settings['port'];
    $mail->SMTPAuth = (string) $settings['username'] !== '';
    $mail->Username = (string) $settings['username'];
    $mail->Password = decryptSmtpPassword((string) $settings['password_encrypted']);
    $encryption = (string) $settings['encryption'];
    if ($encryption === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } elseif ($encryption === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }
    $mail->CharSet = PHPMailer::CHARSET_UTF8;
    $mail->Timeout = 20;
    $mail->setFrom((string) $settings['from_email'], (string) $settings['from_name']);
    $replyTo = trim((string) ($settings['reply_to_email'] ?? ''));
    if ($replyTo !== '') $mail->addReplyTo($replyTo);
    $mail->addAddress($recipientEmail, $recipientName);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $htmlBody;
    $mail->AltBody = $textBody !== '' ? $textBody : trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody)));
    $mail->send();
}