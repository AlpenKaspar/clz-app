<?php

declare(strict_types=1);

function song_live_uploads_ensure_schema(): void
{
    db()->exec(
        "CREATE TABLE IF NOT EXISTS song_live_uploads (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            file_name varchar(255) NOT NULL,
            original_name varchar(255) NOT NULL,
            mime_type varchar(120) NOT NULL DEFAULT '',
            file_size bigint unsigned NOT NULL DEFAULT 0,
            public_url text NOT NULL,
            uploaded_by bigint unsigned NULL,
            uploaded_by_email varchar(190) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY idx_song_live_uploads_created (created_at),
            KEY idx_song_live_uploads_uploader (uploaded_by_email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function song_live_uploads_storage_dir(): string
{
    return dirname(__DIR__) . '/public/uploads/live-songs';
}

function song_live_uploads_public_url(string $fileName): string
{
    return '/uploads/live-songs/' . rawurlencode($fileName);
}

function song_live_uploads_allowed_extensions(): array
{
    return ['mp3', 'm4a', 'wav', 'aac', 'ogg', 'opus', 'flac', 'aif', 'aiff'];
}

function song_live_uploads_normalize_title(string $title, string $originalName): string
{
    $title = trim($title);
    if ($title !== '') {
        return substr($title, 0, 255);
    }
    $fallback = trim((string) pathinfo($originalName, PATHINFO_FILENAME));
    return substr($fallback !== '' ? $fallback : 'Live Song', 0, 255);
}

function song_live_uploads_list(): array
{
    song_live_uploads_ensure_schema();
    $stmt = db()->query(
        'SELECT id, title, file_name, original_name, mime_type, file_size, public_url, uploaded_by_email, created_at
         FROM song_live_uploads
         ORDER BY created_at DESC, id DESC'
    );

    $rows = [];
    foreach ($stmt as $row) {
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'name' => (string) ($row['original_name'] ?? ''),
            'fileName' => (string) ($row['file_name'] ?? ''),
            'url' => (string) ($row['public_url'] ?? ''),
            'mimeType' => (string) ($row['mime_type'] ?? ''),
            'fileSize' => (int) ($row['file_size'] ?? 0),
            'uploadedByEmail' => (string) ($row['uploaded_by_email'] ?? ''),
            'createdAt' => (string) ($row['created_at'] ?? ''),
            'kind' => 'live',
        ];
    }
    return $rows;
}

function song_live_uploads_insert(array $data): array
{
    song_live_uploads_ensure_schema();
    $createdAt = date('Y-m-d H:i:s');
    $stmt = db()->prepare(
        'INSERT INTO song_live_uploads
            (title, file_name, original_name, mime_type, file_size, public_url, uploaded_by, uploaded_by_email, created_at)
         VALUES
            (:title, :file_name, :original_name, :mime_type, :file_size, :public_url, :uploaded_by, :uploaded_by_email, :created_at)'
    );
    $stmt->execute([
        ':title' => (string) ($data['title'] ?? ''),
        ':file_name' => (string) ($data['file_name'] ?? ''),
        ':original_name' => (string) ($data['original_name'] ?? ''),
        ':mime_type' => (string) ($data['mime_type'] ?? ''),
        ':file_size' => (int) ($data['file_size'] ?? 0),
        ':public_url' => (string) ($data['public_url'] ?? ''),
        ':uploaded_by' => isset($data['uploaded_by']) ? (int) $data['uploaded_by'] : null,
        ':uploaded_by_email' => (string) ($data['uploaded_by_email'] ?? ''),
        ':created_at' => $createdAt,
    ]);

    $id = (int) db()->lastInsertId();
    return [
        'id' => $id,
        'title' => (string) ($data['title'] ?? ''),
        'name' => (string) ($data['original_name'] ?? ''),
        'fileName' => (string) ($data['file_name'] ?? ''),
        'url' => (string) ($data['public_url'] ?? ''),
        'mimeType' => (string) ($data['mime_type'] ?? ''),
        'fileSize' => (int) ($data['file_size'] ?? 0),
        'uploadedByEmail' => (string) ($data['uploaded_by_email'] ?? ''),
        'createdAt' => $createdAt,
        'kind' => 'live',
    ];
}
