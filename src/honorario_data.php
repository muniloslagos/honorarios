<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function ensureHonorarioDbUser(array $authUser): array
{
    $pdo = db();

    $run = (string) ($authUser['run'] ?? '');
    $name = (string) ($authUser['name'] ?? 'Usuario Honorario');
    $profession = (string) (($authUser['user_info']['profesion'] ?? $authUser['user_info']['ocupacion'] ?? '') ?: '');

    if ($run === '') {
        throw new RuntimeException('No existe RUN en la sesion autenticada.');
    }

    $find = $pdo->prepare('SELECT id, run, full_name, profession_experience FROM system_users WHERE run = :run AND role = :role LIMIT 1');
    $find->execute(['run' => $run, 'role' => 'HONORARIO']);
    $row = $find->fetch();

    if ($row !== false) {
        return $row;
    }

    $insert = $pdo->prepare('INSERT INTO system_users (run, full_name, role, profession_experience, is_active) VALUES (:run, :full_name, :role, :profession, 1)');
    $insert->execute([
        'run' => $run,
        'full_name' => $name,
        'role' => 'HONORARIO',
        'profession' => $profession,
    ]);

    return [
        'id' => (int) $pdo->lastInsertId(),
        'run' => $run,
        'full_name' => $name,
        'profession_experience' => $profession,
    ];
}

function uploadPdf(array $file, string $folder): ?array
{
    if (!isset($file['error']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Error al subir archivo PDF.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $originalName = (string) ($file['name'] ?? 'archivo.pdf');

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        throw new RuntimeException('Solo se permiten archivos PDF.');
    }

    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName) ?? 'archivo.pdf';
    $targetDir = dirname(__DIR__) . '/uploads/' . trim($folder, '/');
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        throw new RuntimeException('No fue posible crear carpeta de subida.');
    }

    $finalName = date('YmdHis') . '_' . $safeName;
    $fullPath = $targetDir . '/' . $finalName;

    if (!move_uploaded_file($tmpName, $fullPath)) {
        throw new RuntimeException('No fue posible guardar el archivo PDF.');
    }

    return [
        'original_name' => $originalName,
        'stored_path' => 'uploads/' . trim($folder, '/') . '/' . $finalName,
    ];
}
