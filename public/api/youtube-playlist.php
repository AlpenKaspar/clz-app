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
        $dateLabel = youtube_playlist_find_date_text($renderer);
        $serviceDate = youtube_playlist_parse_date_label($dateLabel);
        $index = (int) ($renderer['navigationEndpoint']['watchEndpoint']['index'] ?? count($items));
        $items[] = [
            'videoId' => $itemVideoId,
            'title' => $itemTitle,
            'channel' => youtube_playlist_text($renderer['longBylineText'] ?? null),
            'duration' => youtube_playlist_text($renderer['lengthText'] ?? null),
            'dateLabel' => $dateLabel,
            'serviceDate' => $serviceDate,
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
    foreach (['var ytInitialData = ', 'window["ytInitialData"] = ', 'ytInitialData = '] as $marker) {
        $pos = strpos($html, $marker);
        if ($pos === false) {
            continue;
        }
        $start = strpos($html, '{', $pos);
        if ($start === false) {
            continue;
        }
        $json = youtube_playlist_extract_json_object($html, $start);
        if ($json === '') {
            continue;
        }
        $data = json_decode($json, true);
        if (is_array($data)) {
            return $data;
        }
    }
    return null;
}

function youtube_playlist_extract_json_object(string $html, int $start): string
{
    $len = strlen($html);
    $depth = 0;
    $inString = false;
    $escaped = false;

    for ($i = $start; $i < $len; $i++) {
        $char = $html[$i];
        if ($inString) {
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($char === '\\') {
                $escaped = true;
                continue;
            }
            if ($char === '"') {
                $inString = false;
            }
            continue;
        }
        if ($char === '"') {
            $inString = true;
            continue;
        }
        if ($char === '{') {
            $depth++;
            continue;
        }
        if ($char === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($html, $start, $i - $start + 1);
            }
        }
    }
    return '';
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

function youtube_playlist_find_date_text(mixed $value): string
{
    if (is_string($value)) {
        $text = trim($value);
        return youtube_playlist_text_looks_like_date($text) ? $text : '';
    }
    if (!is_array($value)) {
        return '';
    }
    foreach (['publishedTimeText', 'dateText', 'upcomingEventData', 'thumbnailOverlays'] as $key) {
        if (array_key_exists($key, $value)) {
            $text = youtube_playlist_text($value[$key]);
            if (youtube_playlist_text_looks_like_date($text)) return $text;
            $nested = youtube_playlist_find_date_text($value[$key]);
            if ($nested !== '') return $nested;
        }
    }
    foreach ($value as $child) {
        $nested = youtube_playlist_find_date_text($child);
        if ($nested !== '') return $nested;
    }
    return '';
}

function youtube_playlist_text_looks_like_date(string $text): bool
{
    return (bool) preg_match('/(\d{1,2}\.\d{1,2}\.\d{2,4}|\d{1,2}\.?\s+[A-Za-zÄÖÜäöüéèÉÈ]+\s+\d{4}|\bheute\b|\bgestern\b|vor\s+\d+\s+(?:tag|tagen|woche|wochen|monat|monaten|jahr|jahren))/iu', $text);
}

function youtube_playlist_parse_date_label(string $text): string
{
    $plain = trim($text);
    if ($plain === '') return '';
    $timezone = new DateTimeZone(env('APP_TIMEZONE', 'Europe/Zurich') ?: 'Europe/Zurich');
    $now = new DateTimeImmutable('now', $timezone);
    if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{2,4})/u', $plain, $match)) {
        $year = (int) $match[3];
        if ($year < 100) $year += 2000;
        return sprintf('%04d-%02d-%02d', $year, (int) $match[2], (int) $match[1]);
    }
    if (preg_match('/(\d{1,2})\.?\s+([A-Za-zÄÖÜäöüéèÉÈ]+)\s+(\d{4})/u', $plain, $match)) {
        $month = youtube_playlist_month_number($match[2]);
        return $month ? sprintf('%04d-%02d-%02d', (int) $match[3], $month, (int) $match[1]) : '';
    }
    if (preg_match('/\bheute\b/iu', $plain)) return $now->format('Y-m-d');
    if (preg_match('/\bgestern\b/iu', $plain)) return $now->modify('-1 day')->format('Y-m-d');
    if (preg_match('/vor\s+(\d+)\s+(tag|tagen|woche|wochen|monat|monaten|jahr|jahren)\b/iu', $plain, $match)) {
        $amount = max(1, (int) $match[1]);
        $unit = mb_strtolower($match[2], 'UTF-8');
        $modifier = str_starts_with($unit, 'tag') ? "-{$amount} day"
            : (str_starts_with($unit, 'woche') ? "-{$amount} week"
            : (str_starts_with($unit, 'monat') ? "-{$amount} month" : "-{$amount} year"));
        return $now->modify($modifier)->format('Y-m-d');
    }
    return '';
}

function youtube_playlist_month_number(string $monthName): ?int
{
    $months = [
        'januar' => 1,
        'februar' => 2,
        'maerz' => 3,
        'marz' => 3,
        'märz' => 3,
        'april' => 4,
        'mai' => 5,
        'juni' => 6,
        'juli' => 7,
        'august' => 8,
        'september' => 9,
        'oktober' => 10,
        'november' => 11,
        'dezember' => 12,
    ];
    $key = mb_strtolower(trim($monthName), 'UTF-8');
    $key = str_replace(['ä', 'ö', 'ü'], ['ae', 'oe', 'ue'], $key);
    return $months[$key] ?? null;
}
