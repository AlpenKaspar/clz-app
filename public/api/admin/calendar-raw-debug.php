<?php

declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';

try {
    require_admin_token();

    $q = trim((string) ($_GET['q'] ?? ''));
    $limit = max(1, min(50, (int) ($_GET['limit'] ?? 20)));
    $where = 'start_date >= ?';
    $params = [(new DateTimeImmutable('today', new DateTimeZone(env('APP_TIMEZONE', 'Europe/Zurich') ?: 'Europe/Zurich')))->modify('-14 days')->format('Y-m-d')];
    if ($q !== '') {
        $where .= ' AND title LIKE ?';
        $params[] = '%' . $q . '%';
    }

    $stmt = db()->prepare(
        "SELECT id, elvanto_id, title, start_date, start_time, category, status, raw_json, imported_at
         FROM calendar_events
         WHERE {$where}
         ORDER BY start_date, start_time, title
         LIMIT {$limit}"
    );
    $stmt->execute($params);

    $events = [];
    foreach ($stmt->fetchAll() as $row) {
        $raw = json_decode((string) ($row['raw_json'] ?? ''), true);
        if (!is_array($raw)) {
            $raw = [];
        }
        $events[] = [
            'id' => (int) ($row['id'] ?? 0),
            'elvantoId' => (string) ($row['elvanto_id'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'date' => (string) ($row['start_date'] ?? ''),
            'time' => substr((string) ($row['start_time'] ?? ''), 0, 5),
            'category' => (string) ($row['category'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'importedAt' => (string) ($row['imported_at'] ?? ''),
            'rawKeys' => array_slice(array_keys($raw), 0, 80),
            'picture' => debug_pick_picture($raw),
            'pictureRaw' => $raw['picture'] ?? null,
            'assetsSummary' => debug_assets_summary($raw),
            'organizerCandidates' => debug_pick_person_candidates($raw, ['organizer', 'organiser', 'owner']),
            'createdByCandidates' => debug_pick_person_candidates($raw, ['created_by', 'created_by_person']),
            'bookedByCandidates' => debug_pick_person_candidates($raw, ['booked_by', 'booked_by_person', 'booking_person', 'contact', 'person', 'person_id']),
        ];
    }

    json_response([
        'ok' => true,
        'hint' => 'q filtert den Titel, limit ist 1-50. Default-Elvanto-Eventbild wird als defaultAvatar=true markiert.',
        'query' => $q,
        'count' => count($events),
        'events' => $events,
    ]);
} catch (Throwable $e) {
    json_error('Kalender raw_json Debug konnte nicht geladen werden.', 500, [
        'detail' => env('APP_DEBUG', '0') === '1' ? $e->getMessage() : null,
    ]);
}

function debug_pick_picture(array $raw): array
{
    $urls = [];
    $picture = $raw['picture'] ?? null;
    if (is_string($picture)) {
        $urls[] = $picture;
    } elseif (is_array($picture)) {
        foreach (['url', 'medium', 'large', 'small', 'original', 'thumbnail_url', 'thumb_url'] as $key) {
            if (!empty($picture[$key]) && is_scalar($picture[$key])) {
                $urls[] = (string) $picture[$key];
            }
        }
    }
    foreach (debug_normalize_collection($raw['assets'] ?? ($raw['asset'] ?? [])) as $asset) {
        if (is_string($asset)) {
            $urls[] = $asset;
        } elseif (is_array($asset)) {
            foreach (['url', 'medium', 'large', 'small', 'original', 'thumbnail_url', 'thumb_url'] as $key) {
                if (!empty($asset[$key]) && is_scalar($asset[$key])) {
                    $urls[] = (string) $asset[$key];
                }
            }
        }
    }
    $urls = array_values(array_unique(array_filter(array_map('trim', $urls))));
    $first = $urls[0] ?? '';
    return [
        'url' => $first,
        'defaultAvatar' => $first !== '' && str_contains(strtolower($first), 'cdn.elvanto.eu/img/default-event-avatar.svg'),
        'allUrls' => $urls,
    ];
}

function debug_assets_summary(array $raw): array
{
    $out = [];
    foreach (debug_normalize_collection($raw['assets'] ?? ($raw['asset'] ?? [])) as $asset) {
        if (is_array($asset)) {
            $out[] = [
                'keys' => array_slice(array_keys($asset), 0, 20),
                'name' => (string) ($asset['name'] ?? ($asset['title'] ?? '')),
                'type' => (string) ($asset['type'] ?? ($asset['mime_type'] ?? ($asset['content_type'] ?? ''))),
                'url' => (string) ($asset['url'] ?? ''),
            ];
        } elseif (is_scalar($asset)) {
            $out[] = ['value' => (string) $asset];
        }
    }
    return $out;
}

function debug_pick_person_candidates(array $raw, array $keys): array
{
    $out = [];
    foreach ($keys as $key) {
        if (!array_key_exists($key, $raw)) {
            continue;
        }
        $value = $raw[$key];
        $out[$key] = is_array($value)
            ? [
                'keys' => array_slice(array_keys($value), 0, 20),
                'id' => (string) ($value['id'] ?? ($value['person_id'] ?? '')),
                'name' => (string) ($value['name'] ?? ($value['display_name'] ?? trim((string) ($value['firstname'] ?? '') . ' ' . (string) ($value['lastname'] ?? '')))),
                'raw' => $value,
            ]
            : $value;
    }
    return $out;
}

function debug_normalize_collection(mixed $value): array
{
    if (!is_array($value)) {
        return $value === null || $value === '' ? [] : [$value];
    }
    foreach (['asset', 'resource', 'item'] as $childKey) {
        if (isset($value[$childKey])) {
            return debug_normalize_collection($value[$childKey]);
        }
    }
    if (array_is_list($value)) {
        return $value;
    }
    return [$value];
}
