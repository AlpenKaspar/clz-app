<?php

declare(strict_types=1);

require_once __DIR__ . '/import_people.php';

const SERVICE_MEDIA_YOUTUBE_STREAMS_URL = 'https://www.youtube.com/@CLZ_Spiez/streams?ucbcb=1';
const SERVICE_MEDIA_PREDIGT_URL = 'https://www.clzspiez.ch/predigtskript/';

function import_service_media(): array
{
    $startedAt = date('Y-m-d H:i:s');
    $runId = import_run_start('service_media');

    try {
        ensure_service_media_schema();

        $youtubeHtml = service_media_fetch_url(SERVICE_MEDIA_YOUTUBE_STREAMS_URL);
        $predigtHtml = service_media_fetch_url(SERVICE_MEDIA_PREDIGT_URL);

        $youtubeItems = parse_service_media_youtube_streams($youtubeHtml);
        $predigtItems = parse_service_media_predigtscripts($predigtHtml);

        replace_service_media_resources('youtube', $youtubeItems);
        replace_service_media_resources('predigtscript', $predigtItems);

        $count = count($youtubeItems) + count($predigtItems);
        set_app_setting('IMPORT_SERVICE_MEDIA_LAST', date('c'));
        import_run_finish($runId, 'ok', $count, "Imported " . count($youtubeItems) . " livestreams and " . count($predigtItems) . " predigtscripts.");

        return [
            'ok' => true,
            'type' => 'service_media',
            'livestreams' => count($youtubeItems),
            'predigtscripts' => count($predigtItems),
            'total' => $count,
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

function ensure_service_media_schema(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS service_media_resources (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            resource_type varchar(40) NOT NULL,
            resource_key varchar(190) NOT NULL,
            service_date date NOT NULL,
            service_time time NULL,
            title varchar(255) NOT NULL DEFAULT \'\',
            speaker varchar(190) NOT NULL DEFAULT \'\',
            url text NOT NULL,
            video_id varchar(40) NULL,
            thumbnail_url text NULL,
            scheduled_at datetime NULL,
            source varchar(120) NOT NULL DEFAULT \'\',
            raw_json longtext NULL,
            imported_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_service_media_resource (resource_type, resource_key),
            KEY idx_service_media_date (service_date, service_time),
            KEY idx_service_media_video (video_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function service_media_fetch_url(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 18,
            'follow_location' => 1,
            'max_redirects' => 4,
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36\r\nAccept-Language: de-CH,de;q=0.9,en;q=0.8\r\nAccept: text/html,*/*;q=0.8\r\n",
        ],
    ]);

    $html = @file_get_contents($url, false, $context);
    if (!is_string($html) || trim($html) === '') {
        throw new RuntimeException('Service-Media konnte nicht geladen werden: ' . $url);
    }

    return $html;
}

function parse_service_media_youtube_streams(string $html): array
{
    $decoded = str_replace(
        ['\\"', '\\u0026', '\\u003d', '\\u002F', '\\/'],
        ['"', '&', '=', '/', '/'],
        $html
    );

    $items = [];
    $seen = [];
    $offset = 0;
    while (($pos = strpos($decoded, '"lockupMetadataViewModel"', $offset)) !== false) {
        $after = substr($decoded, $pos, 5000);
        $prefixStart = max(0, $pos - 7000);
        $prefix = substr($decoded, $prefixStart, $pos - $prefixStart);
        $around = $prefix . $after;

        $title = '';
        if (preg_match('/"title"\s*:\s*\{\s*"content"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/s', $after, $titleMatch)) {
            $title = service_media_decode_js_string($titleMatch[1]);
        }

        $scheduledAt = service_media_parse_youtube_planned_at($after);
        $videoId = service_media_nearest_youtube_video_id($prefix);
        if ($videoId === '' && preg_match('/"videoId"\s*:\s*"([A-Za-z0-9_-]{6,})"/', $around, $videoMatch)) {
            $videoId = $videoMatch[1];
        }

        if ($scheduledAt !== null && $videoId !== '' && !isset($seen[$videoId])) {
            $seen[$videoId] = true;
            $thumbnail = '';
            if (preg_match('~https://i\.ytimg\.com/vi/' . preg_quote($videoId, '~') . '/[^"\\\\<]+~', $around, $thumbMatch)) {
                $thumbnail = service_media_decode_js_string($thumbMatch[0]);
            }
            $items[] = [
                'resource_type' => 'youtube',
                'resource_key' => $videoId,
                'service_date' => $scheduledAt->format('Y-m-d'),
                'service_time' => $scheduledAt->format('H:i:s'),
                'title' => $title !== '' ? $title : 'Livestream',
                'speaker' => service_media_extract_speaker_from_title($title),
                'url' => 'https://www.youtube.com/watch?v=' . $videoId,
                'video_id' => $videoId,
                'thumbnail_url' => $thumbnail,
                'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
                'source' => SERVICE_MEDIA_YOUTUBE_STREAMS_URL,
                'raw_json' => json_encode(['title' => $title, 'videoId' => $videoId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        $offset = $pos + 30;
    }

    usort($items, static function (array $a, array $b): int {
        return strcmp((string) ($a['scheduled_at'] ?? ''), (string) ($b['scheduled_at'] ?? ''));
    });

    return $items;
}

function service_media_nearest_youtube_video_id(string $prefix): string
{
    $matches = [];
    preg_match_all('~/watch\?v=([A-Za-z0-9_-]{6,})~', $prefix, $matches, PREG_SET_ORDER);
    if ($matches) {
        $last = end($matches);
        return is_array($last) ? (string) ($last[1] ?? '') : '';
    }

    preg_match_all('/"videoId"\s*:\s*"([A-Za-z0-9_-]{6,})"/', $prefix, $matches, PREG_SET_ORDER);
    if ($matches) {
        $last = end($matches);
        return is_array($last) ? (string) ($last[1] ?? '') : '';
    }

    return '';
}

function service_media_parse_youtube_planned_at(string $chunk): ?DateTimeImmutable
{
    if (!preg_match('/Geplant\s+f(?:ür|uer|ur|\\\\u00fcr):?\s*(\d{1,2})\.(\d{1,2})\.(\d{2,4}),?\s*(\d{1,2}):(\d{2})/iu', $chunk, $match)) {
        return null;
    }

    $year = (int) $match[3];
    if ($year < 100) {
        $year += 2000;
    }

    $timezone = new DateTimeZone(env('APP_TIMEZONE', 'Europe/Zurich') ?: 'Europe/Zurich');
    return new DateTimeImmutable(sprintf(
        '%04d-%02d-%02d %02d:%02d:00',
        $year,
        (int) $match[2],
        (int) $match[1],
        (int) $match[4],
        (int) $match[5]
    ), $timezone);
}

function service_media_decode_js_string(string $value): string
{
    $decoded = json_decode('"' . str_replace('"', '\\"', $value) . '"', true);
    if (is_string($decoded)) {
        return trim(html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    return trim(html_entity_decode(stripslashes($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function service_media_extract_speaker_from_title(string $title): string
{
    $parts = array_values(array_filter(array_map('trim', explode('|', $title))));
    if (count($parts) >= 2) {
        return mb_substr($parts[count($parts) - 2], 0, 190);
    }
    return '';
}

function parse_service_media_predigtscripts(string $html): array
{
    $items = [];
    $seen = [];
    if (!preg_match_all('/<p\b[^>]*>(.*?)<\/p>/isu', $html, $paragraphs)) {
        return [];
    }

    foreach ($paragraphs[1] as $paragraph) {
        if (!preg_match('/href=["\']([^"\']+\.pdf(?:\?[^"\']*)?)["\']/iu', $paragraph, $hrefMatch)) {
            continue;
        }

        $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($paragraph), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        $date = service_media_parse_predigt_date($plain);
        if ($date === null) {
            continue;
        }

        $url = html_entity_decode($hrefMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!preg_match('~^https?://~i', $url)) {
            $url = rtrim('https://www.clzspiez.ch', '/') . '/' . ltrim($url, '/');
        }

        $title = '';
        if (preg_match('/<a\b[^>]*href=["\'][^"\']+\.pdf(?:\?[^"\']*)?["\'][^>]*>(.*?)<\/a>/isu', $paragraph, $titleMatch)) {
            $title = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($titleMatch[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        }
        if ($title === '') {
            $title = 'Predigtscript';
        }

        $speaker = service_media_extract_predigt_speaker($plain);
        $key = sha1($date->format('Y-m-d') . '|' . $url);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $items[] = [
            'resource_type' => 'predigtscript',
            'resource_key' => $key,
            'service_date' => $date->format('Y-m-d'),
            'service_time' => null,
            'title' => mb_substr($title, 0, 255),
            'speaker' => mb_substr($speaker, 0, 190),
            'url' => $url,
            'video_id' => null,
            'thumbnail_url' => null,
            'scheduled_at' => null,
            'source' => SERVICE_MEDIA_PREDIGT_URL,
            'raw_json' => json_encode(['line' => $plain], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    usort($items, static function (array $a, array $b): int {
        return strcmp((string) ($b['service_date'] ?? ''), (string) ($a['service_date'] ?? ''));
    });

    return $items;
}

function service_media_parse_predigt_date(string $plain): ?DateTimeImmutable
{
    if (!preg_match('/Sonntag,?\s*(\d{1,2})\.?\s+([A-Za-zäöüÄÖÜéèÉÈ]+)\s+(\d{4})/u', $plain, $match)) {
        return null;
    }

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
    $monthName = mb_strtolower($match[2], 'UTF-8');
    $monthName = str_replace(['ä', 'ö', 'ü'], ['ae', 'oe', 'ue'], $monthName);
    $month = $months[$monthName] ?? null;
    if (!$month) {
        return null;
    }

    $timezone = new DateTimeZone(env('APP_TIMEZONE', 'Europe/Zurich') ?: 'Europe/Zurich');
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', (int) $match[3], $month, (int) $match[1]), $timezone);
}

function service_media_extract_predigt_speaker(string $plain): string
{
    if (preg_match('/\d{4}\s*(?:\([^)]*\))?,\s*([^:]+):/u', $plain, $match)) {
        return trim($match[1]);
    }
    return '';
}

function replace_service_media_resources(string $type, array $items): void
{
    $pdo = db();
    $pdo->beginTransaction();
    $delete = $pdo->prepare('DELETE FROM service_media_resources WHERE resource_type = ?');
    $delete->execute([$type]);

    $stmt = $pdo->prepare(
        'INSERT INTO service_media_resources
            (resource_type, resource_key, service_date, service_time, title, speaker, url, video_id, thumbnail_url, scheduled_at, source, raw_json, imported_at)
         VALUES
            (:resource_type, :resource_key, :service_date, :service_time, :title, :speaker, :url, :video_id, :thumbnail_url, :scheduled_at, :source, :raw_json, :imported_at)'
    );

    foreach ($items as $item) {
        $stmt->execute([
            ':resource_type' => $type,
            ':resource_key' => (string) ($item['resource_key'] ?? ''),
            ':service_date' => (string) ($item['service_date'] ?? ''),
            ':service_time' => $item['service_time'] ?? null,
            ':title' => mb_substr(normalize_string($item['title'] ?? ''), 0, 255),
            ':speaker' => mb_substr(normalize_string($item['speaker'] ?? ''), 0, 190),
            ':url' => (string) ($item['url'] ?? ''),
            ':video_id' => $item['video_id'] ?? null,
            ':thumbnail_url' => $item['thumbnail_url'] ?? null,
            ':scheduled_at' => $item['scheduled_at'] ?? null,
            ':source' => mb_substr((string) ($item['source'] ?? ''), 0, 120),
            ':raw_json' => $item['raw_json'] ?? null,
            ':imported_at' => date('Y-m-d H:i:s'),
        ]);
    }

    $pdo->commit();
}
