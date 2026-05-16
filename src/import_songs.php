<?php

declare(strict_types=1);

require_once __DIR__ . '/import_people.php';

function import_songs(): array
{
    $startedAt = date('Y-m-d H:i:s');
    $runId = import_run_start('songs');

    try {
        $songs = fetch_elvanto_songs();
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM songs');

        $count = 0;
        foreach ($songs as $song) {
            if (!is_array($song)) {
                continue;
            }
            if (upsert_song($song)) {
                $count++;
            }
        }

        set_app_setting('DATA_VERSION', (string) time());
        set_app_setting('IMPORT_SONGS_LAST', date('c'));
        $pdo->commit();

        import_run_finish($runId, 'ok', $count, "Imported {$count} songs.");

        return [
            'ok' => true,
            'type' => 'songs',
            'count' => $count,
            'startedAt' => $startedAt,
            'finishedAt' => date('Y-m-d H:i:s'),
        ];
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        import_run_finish($runId, 'error', 0, $e->getMessage());
        throw $e;
    }
}

function fetch_elvanto_songs(): array
{
    $songs = [];
    $page = 1;
    $pageSize = 200;

    while (true) {
        $data = elvanto_post('songs/getAll.json', [
            'page' => $page,
            'page_size' => $pageSize,
        ]);

        $batch = extract_songs_payload($data);
        if (!$batch) {
            break;
        }

        foreach ($batch as $song) {
            if (is_array($song)) {
                $songs[] = $song;
            }
        }

        if (count($batch) < $pageSize) {
            break;
        }
        $page++;
    }

    return $songs;
}

function extract_songs_payload(array $data): array
{
    foreach ([['songs', 'song'], ['songs'], ['song'], ['data', 'songs', 'song'], ['data', 'songs']] as $path) {
        $items = songs_get_path_array($data, $path);
        if ($items) {
            return $items;
        }
    }
    if (isset($data['id']) || isset($data['title']) || isset($data['name'])) {
        return [$data];
    }
    return [];
}

function songs_get_path_array(array $data, array $path): array
{
    $value = $data;
    foreach ($path as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return [];
        }
        $value = $value[$segment];
    }
    return normalize_collection($value);
}

function upsert_song(array $song): bool
{
    $title = normalize_string($song['title'] ?? ($song['name'] ?? ''));
    if ($title === '') {
        return false;
    }

    $id = normalize_string($song['id'] ?? ($song['song_id'] ?? ''));
    if ($id === '') {
        $id = 'song-' . sha1($title . '|' . normalize_string($song['artist'] ?? ''));
    }

    $stmt = db()->prepare(
        'INSERT INTO songs (song_id, title, artist, category, default_key_name, bpm, raw_json, imported_at)
         VALUES (:song_id, :title, :artist, :category, :default_key_name, :bpm, :raw_json, :imported_at)
         ON DUPLICATE KEY UPDATE title = VALUES(title), artist = VALUES(artist), category = VALUES(category),
            default_key_name = VALUES(default_key_name), bpm = VALUES(bpm), raw_json = VALUES(raw_json), imported_at = VALUES(imported_at)'
    );
    $stmt->execute([
        ':song_id' => $id,
        ':title' => $title,
        ':artist' => song_first_string($song, ['artist', 'author', 'authors', 'writer']),
        ':category' => song_category_name($song),
        ':default_key_name' => song_default_key_name($song),
        ':bpm' => song_default_bpm($song),
        ':raw_json' => json_encode($song, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':imported_at' => date('Y-m-d H:i:s'),
    ]);

    return true;
}

function song_first_string(array $data, array $keys): string
{
    foreach ($keys as $key) {
        $value = normalize_string($data[$key] ?? '');
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function song_category_name(array $song): string
{
    $category = $song['category'] ?? ($song['category_name'] ?? '');
    if (is_array($category)) {
        return normalize_string($category['name'] ?? ($category['title'] ?? ''));
    }
    return normalize_string($category);
}

function song_default_key_name(array $song): string
{
    $direct = song_first_string($song, ['key_name', 'key', 'default_key_name']);
    if ($direct !== '') {
        return $direct;
    }
    $arrangements = song_arrangements_from_payload($song);
    foreach ($arrangements as $arrangement) {
        if (!is_array($arrangement)) {
            continue;
        }
        $key = song_first_string($arrangement, ['key_name', 'key', 'default_key_name']);
        if ($key !== '') {
            return $key;
        }
    }
    return '';
}

function song_default_bpm(array $song): string
{
    $direct = song_first_string($song, ['bpm', 'tempo']);
    if ($direct !== '') {
        return $direct;
    }
    $arrangements = song_arrangements_from_payload($song);
    foreach ($arrangements as $arrangement) {
        if (!is_array($arrangement)) {
            continue;
        }
        $bpm = song_first_string($arrangement, ['bpm', 'tempo']);
        if ($bpm !== '') {
            return $bpm;
        }
    }
    return '';
}

function song_arrangements_from_payload(array $song): array
{
    foreach ([['arrangements', 'arrangement'], ['arrangements'], ['song_arrangements', 'arrangement'], ['song_arrangements']] as $path) {
        $items = songs_get_path_array($song, $path);
        if ($items) {
            return $items;
        }
    }
    return [];
}
