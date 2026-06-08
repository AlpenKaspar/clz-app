<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/song_live_uploads.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Methode nicht erlaubt.', 405);
}

$user = require_user();
$role = strtolower(trim((string) ($user['role'] ?? '')));
$originalRole = strtolower(trim((string) ($user['impersonating']['originalUser']['role'] ?? '')));
if (!in_array($role, ['admin', 'super_admin'], true) && !in_array($originalRole, ['admin', 'super_admin'], true)) {
    json_error('Nur Admins duerfen Live-Songs hochladen.', 403);
}

$file = $_FILES['file'] ?? null;
if (!is_array($file)) {
    json_error('Keine Datei erhalten.', 400);
}

$error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($error !== UPLOAD_ERR_OK) {
    json_error('Upload fehlgeschlagen.', 400, ['uploadError' => $error]);
}

$tmpName = (string) ($file['tmp_name'] ?? '');
$originalName = trim((string) ($file['name'] ?? ''));
if ($tmpName === '' || $originalName === '' || !is_uploaded_file($tmpName)) {
    json_error('Ungueltige Upload-Datei.', 400);
}

$ext = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
if (!in_array($ext, song_live_uploads_allowed_extensions(), true)) {
    json_error('Dieses Audioformat wird nicht unterstuetzt.', 400, [
        'allowed' => song_live_uploads_allowed_extensions(),
    ]);
}

$size = (int) ($file['size'] ?? 0);
$maxSize = 250 * 1024 * 1024;
if ($size <= 0 || $size > $maxSize) {
    json_error('Die Datei ist zu gross oder leer.', 400);
}

$dir = song_live_uploads_storage_dir();
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    json_error('Upload-Ordner konnte nicht erstellt werden.', 500);
}

$storedName = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
$target = $dir . '/' . $storedName;
if (!move_uploaded_file($tmpName, $target)) {
    json_error('Datei konnte nicht gespeichert werden.', 500);
}

$title = song_live_uploads_normalize_title((string) ($_POST['title'] ?? ''), $originalName);
$mimeType = trim((string) ($file['type'] ?? ''));
$upload = song_live_uploads_insert([
    'title' => $title,
    'file_name' => $storedName,
    'original_name' => $originalName,
    'mime_type' => $mimeType,
    'file_size' => $size,
    'public_url' => song_live_uploads_public_url($storedName),
    'uploaded_by' => $user['id'] ?? null,
    'uploaded_by_email' => (string) ($user['email'] ?? ''),
]);

set_app_setting('DATA_VERSION', (string) time());
set_app_setting('IMPORT_SONGS_LAST', date('c'));

json_response([
    'ok' => true,
    'upload' => $upload,
    'dataVersion' => app_setting('DATA_VERSION', '1'),
]);
