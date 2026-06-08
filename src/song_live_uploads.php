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
    db()->exec(
        "CREATE TABLE IF NOT EXISTS song_live_upload_links (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            upload_id bigint unsigned NOT NULL,
            song_id varchar(80) NOT NULL,
            start_seconds int unsigned NOT NULL DEFAULT 0,
            sort_order int unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_song_live_upload_link (upload_id, song_id),
            KEY idx_song_live_upload_links_upload (upload_id, sort_order),
            KEY idx_song_live_upload_links_song (song_id)
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
            'songLinks' => [],
        ];
    }
    song_live_uploads_attach_links($rows);
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
        'songLinks' => [],
    ];
}

function song_live_uploads_attach_links(array &$uploads): void
{
    $ids = array_values(array_filter(array_map(static fn ($row) => (int) ($row['id'] ?? 0), $uploads)));
    if (!$ids) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT l.upload_id, l.song_id, l.start_seconds, l.sort_order, s.title, s.artist, s.default_key_name
         FROM song_live_upload_links l
         LEFT JOIN songs s ON s.song_id = l.song_id
         WHERE l.upload_id IN ({$placeholders})
         ORDER BY l.upload_id, l.sort_order, l.start_seconds, s.title"
    );
    $stmt->execute($ids);
    $linksByUpload = [];
    foreach ($stmt as $row) {
        $uploadId = (int) ($row['upload_id'] ?? 0);
        if ($uploadId <= 0) {
            continue;
        }
        $linksByUpload[$uploadId][] = [
            'songId' => (string) ($row['song_id'] ?? ''),
            'songTitle' => (string) ($row['title'] ?? ''),
            'artist' => (string) ($row['artist'] ?? ''),
            'keyName' => (string) ($row['default_key_name'] ?? ''),
            'startSeconds' => (int) ($row['start_seconds'] ?? 0),
            'sortOrder' => (int) ($row['sort_order'] ?? 0),
        ];
    }

    foreach ($uploads as &$upload) {
        $upload['songLinks'] = $linksByUpload[(int) ($upload['id'] ?? 0)] ?? [];
    }
}

function song_live_uploads_save_links(int $uploadId, array $links): array
{
    song_live_uploads_ensure_schema();
    if ($uploadId <= 0) {
        throw new RuntimeException('Ungueltiger Live-Upload.');
    }

    $stmt = db()->prepare('SELECT id FROM song_live_uploads WHERE id = ?');
    $stmt->execute([$uploadId]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Live-Upload nicht gefunden.');
    }

    $clean = [];
    $seen = [];
    foreach ($links as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $songId = trim((string) ($entry['songId'] ?? ''));
        if ($songId === '' || isset($seen[$songId])) {
            continue;
        }
        $seen[$songId] = true;
        $startSeconds = max(0, (int) floor((float) ($entry['startSeconds'] ?? 0)));
        $clean[] = [
            'songId' => $songId,
            'startSeconds' => $startSeconds,
        ];
    }

    if ($clean) {
        $songPlaceholders = implode(',', array_fill(0, count($clean), '?'));
        $songIds = array_column($clean, 'songId');
        $songStmt = db()->prepare("SELECT song_id FROM songs WHERE song_id IN ({$songPlaceholders})");
        $songStmt->execute($songIds);
        $existing = [];
        foreach ($songStmt as $row) {
            $existing[(string) ($row['song_id'] ?? '')] = true;
        }
        $clean = array_values(array_filter($clean, static fn ($entry) => isset($existing[$entry['songId']])));
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare('DELETE FROM song_live_upload_links WHERE upload_id = ?');
        $delete->execute([$uploadId]);
        if ($clean) {
            $insert = $pdo->prepare(
                'INSERT INTO song_live_upload_links (upload_id, song_id, start_seconds, sort_order, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $now = date('Y-m-d H:i:s');
            foreach ($clean as $idx => $entry) {
                $insert->execute([$uploadId, $entry['songId'], $entry['startSeconds'], $idx, $now, $now]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $uploads = song_live_uploads_list();
    foreach ($uploads as $upload) {
        if ((int) ($upload['id'] ?? 0) === $uploadId) {
            return $upload;
        }
    }
    return ['id' => $uploadId, 'songLinks' => []];
}
