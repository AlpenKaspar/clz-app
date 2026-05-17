<?php

declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';

try {
    require_admin_token();

    $limit = max(1, min(1000, (int) ($_GET['limit'] ?? 250)));
    $q = trim((string) ($_GET['q'] ?? ''));

    $params = [];
    $where = '';
    if ($q !== '') {
        $where = 'WHERE title LIKE :q OR artist LIKE :q OR category LIKE :q OR default_key_name LIKE :q';
        $params[':q'] = '%' . $q . '%';
    }

    $stmt = db()->prepare(
        "SELECT song_id, title, artist, category, default_key_name, bpm, raw_json, imported_at
         FROM songs
         {$where}
         ORDER BY title
         LIMIT {$limit}"
    );
    $stmt->execute($params);

    $songs = [];
    foreach ($stmt->fetchAll() as $row) {
        $raw = json_decode((string) ($row['raw_json'] ?? ''), true);
        $songs[] = [
            'songId' => (string) ($row['song_id'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'artist' => (string) ($row['artist'] ?? ''),
            'category' => (string) ($row['category'] ?? ''),
            'keyName' => (string) ($row['default_key_name'] ?? ''),
            'bpm' => (string) ($row['bpm'] ?? ''),
            'importedAt' => (string) ($row['imported_at'] ?? ''),
            'rawKeys' => is_array($raw) ? array_keys($raw) : [],
            'raw' => is_array($raw) ? $raw : null,
        ];
    }

    json_response([
        'ok' => true,
        'count' => count($songs),
        'limit' => $limit,
        'query' => $q,
        'songs' => $songs,
    ]);
} catch (Throwable $e) {
    json_error('Songs konnten nicht geladen werden.', 500, [
        'detail' => env('APP_DEBUG', '0') === '1' ? $e->getMessage() : null,
    ]);
}
