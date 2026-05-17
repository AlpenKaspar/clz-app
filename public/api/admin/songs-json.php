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
        $category = (string) ($row['category'] ?? '');
        if ($category === '' && is_array($raw)) {
            $category = admin_song_category_name($raw);
        }

        $songs[] = [
            'songId' => (string) ($row['song_id'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'artist' => (string) ($row['artist'] ?? ''),
            'category' => $category,
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

function admin_song_category_name(array $song): string
{
    $category = $song['category'] ?? ($song['category_name'] ?? '');
    if (is_array($category)) {
        $value = trim((string) ($category['name'] ?? ($category['title'] ?? '')));
        if ($value !== '') {
            return $value;
        }
    } elseif (is_scalar($category)) {
        $value = trim((string) $category);
        if ($value !== '') {
            return $value;
        }
    }

    $items = $song['categories']['category'] ?? [];
    if (!is_array($items)) {
        return '';
    }
    if (!array_is_list($items)) {
        $items = [$items];
    }

    $names = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = trim((string) ($item['name'] ?? ($item['title'] ?? '')));
        if ($name !== '') {
            $names[] = $name;
        }
    }

    return implode(', ', array_values(array_unique($names)));
}
