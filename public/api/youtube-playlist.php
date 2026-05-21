<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

try {
    $playlistId = trim((string) ($_GET['list'] ?? ''));
    $videoId = trim((string) ($_GET['v'] ?? ''));
    if ($playlistId === '' || !preg_match('/^[A-Za-z0-9_-]{6,}$/', $playlistId)) {
        json_error('Playlist fehlt.', 400);
    }
    if ($videoId === '' || !preg_match('/^[A-Za-z0-9_-]{6,}$/', $videoId)) {
        $videoId = '';
    }

    $watchUrl = 'https://www.youtube.com/watch?' . http_build_query(array_filter([
        'v' => $videoId,
        'list' => $playlistId,
    ]));

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 14,
            'follow_location' => 1,
            'max_redirects' => 4,
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36\r\nAccept-Language: de-DE,de;q=0.9,en;q=0.8\r\nAccept: text/html,*/*;q=0.8\r\n",
        ],
    ]);
    $html = @file_get_contents($watchUrl, false, $context);
    if (!is_string($html) || $html === '') {
        json_error('YouTube-Playlist konnte nicht geladen werden.', 502);
    }

    $initial = youtube_playlist_extract_initial_data($html);
    if (!is_array($initial)) {
        json_error('YouTube-Playlist konnte nicht gelesen werden.', 502);
    }

    $playlist = $initial['contents']['twoColumnWatchNextResults']['playlist']['playlist'] ?? null;
    if (!is_array($playlist)) {
        json_error('YouTube-Playlist ist nicht verfuegbar.', 502);
    }

    $title = trim((string) ($playlist['title'] ?? ''));
    $items = [];
    foreach (($playlist['contents'] ?? []) as $row) {
        $renderer = $row['playlistPanelVideoRenderer'] ?? null;
        if (!is_array($renderer)) {
            continue;
        }
        $itemVideoId = (string) (
            $renderer['videoId']
            ?? $renderer['navigationEndpoint']['watchEndpoint']['videoId']
            ?? ''
        );
        if ($itemVideoId === '' || !preg_match('/^[A-Za-z0-9_-]{6,}$/', $itemVideoId)) {
            continue;
        }
        $itemTitle = youtube_playlist_text($renderer['title'] ?? null);
        if ($itemTitle === '') {
            $itemTitle = 'Livestream';
        }
        $index = (int) ($renderer['navigationEndpoint']['watchEndpoint']['index'] ?? count($items));
        $items[] = [
            'videoId' => $itemVideoId,
            'title' => $itemTitle,
            'channel' => youtube_playlist_text($renderer['longBylineText'] ?? null),
            'duration' => youtube_playlist_text($renderer['lengthText'] ?? null),
            'index' => max(0, $index),
            'selected' => (bool) ($renderer['selected'] ?? false),
            'thumbnail' => youtube_playlist_thumbnail($renderer['thumbnail'] ?? null),
            'url' => 'https://www.youtube.com/watch?' . http_build_query([
                'v' => $itemVideoId,
                'list' => $playlistId,
                'index' => max(1, $index + 1),
            ]),
        ];
    }

    json_response([
        'ok' => true,
        'playlistId' => $playlistId,
        'title' => $title !== '' ? $title : 'Livestream',
        'items' => $items,
        'count' => count($items),
    ]);
} catch (Throwable $e) {
    json_error('YouTube-Playlist konnte nicht geladen werden.', 500, [
        'detail' => env('APP_DEBUG', '0') === '1' ? $e->getMessage() : null,
    ]);
}

function youtube_playlist_extract_initial_data(string $html): ?array
{
    if (preg_match('/var ytInitialData\s*=\s*(\{.*?\});\s*<\/script>/s', $html, $m)
        || preg_match('/ytInitialData\s*=\s*(\{.*?\});/s', $html, $m)
    ) {
        $data = json_decode($m[1], true);
        return is_array($data) ? $data : null;
    }
    return null;
}

function youtube_playlist_text(mixed $value): string
{
    if (is_string($value)) {
        return trim($value);
    }
    if (!is_array($value)) {
        return '';
    }
    if (isset($value['simpleText']) && is_string($value['simpleText'])) {
        return trim($value['simpleText']);
    }
    if (isset($value['runs']) && is_array($value['runs'])) {
        $parts = [];
        foreach ($value['runs'] as $run) {
            if (isset($run['text']) && is_string($run['text'])) {
                $parts[] = $run['text'];
            }
        }
        return trim(implode('', $parts));
    }
    return '';
}

function youtube_playlist_thumbnail(mixed $value): string
{
    if (!is_array($value) || !isset($value['thumbnails']) || !is_array($value['thumbnails'])) {
        return '';
    }
    $thumbs = $value['thumbnails'];
    $last = end($thumbs);
    return is_array($last) && isset($last['url']) && is_string($last['url']) ? $last['url'] : '';
}
