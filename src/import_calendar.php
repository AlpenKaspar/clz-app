<?php

declare(strict_types=1);

require_once __DIR__ . '/import_people.php';

function import_calendar_basic(bool $force = false): array
{
    $startedAt = date('Y-m-d H:i:s');
    $runId = import_run_start('calendar');

    try {
        $monthsIntoFuture = 16;
        $start = new DateTimeImmutable('first day of january this year', new DateTimeZone('Europe/Zurich'));
        $end = (new DateTimeImmutable('today', new DateTimeZone('Europe/Zurich')))->modify('+' . $monthsIntoFuture . ' months');
        $startStr = $start->format('Y-m-d');
        $endStr = $end->format('Y-m-d');
        $calendarMap = fetch_calendar_map();
        $calendarCategoryMap = fetch_calendar_category_map();
        $footprint = build_calendar_import_footprint($calendarMap, $calendarCategoryMap);
        $previousFootprint = app_setting('IMPORT_KALENDER_FOOTPRINT', '');

        if (!$force && $footprint['hash'] !== '' && hash_equals($previousFootprint, $footprint['hash'])) {
            set_app_setting('IMPORT_KALENDER_LAST_CHECK', date('c'));
            set_app_setting('IMPORT_KALENDER_FOOTPRINT_META', json_encode($footprint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            import_run_finish($runId, 'ok', 0, 'Skipped calendar import; footprint unchanged.');

            return [
                'ok' => true,
                'type' => 'calendar',
                'skipped' => true,
                'reason' => 'footprint_unchanged',
                'events' => 0,
                'services' => 0,
                'total' => 0,
                'footprint' => [
                    'hash' => $footprint['hash'],
                    'count' => $footprint['count'],
                    'range' => $footprint['range'],
                    'limit' => $footprint['limit'],
                ],
                'range' => [
                    'start' => $startStr,
                    'end' => $endStr,
                ],
                'startedAt' => $startedAt,
                'finishedAt' => date('Y-m-d H:i:s'),
            ];
        }

        $pdo = db();
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM calendar_events');

        $eventCount = import_calendar_events($startStr, $endStr, $calendarMap, $calendarCategoryMap);
        $serviceCount = import_calendar_services($startStr, $endStr);

        set_app_setting('IMPORT_KALENDER_LAST', date('c'));
        set_app_setting('IMPORT_KALENDER_LAST_CHECK', date('c'));
        set_app_setting('IMPORT_KALENDER_FOOTPRINT', $footprint['hash']);
        set_app_setting('IMPORT_KALENDER_FOOTPRINT_META', json_encode($footprint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $pdo->commit();

        $total = $eventCount + $serviceCount;
        $forceText = $force ? ' Forced import.' : '';
        import_run_finish($runId, 'ok', $total, "Imported {$eventCount} events and {$serviceCount} service entries.{$forceText}");

        return [
            'ok' => true,
            'type' => 'calendar',
            'skipped' => false,
            'forced' => $force,
            'events' => $eventCount,
            'services' => $serviceCount,
            'total' => $total,
            'footprint' => [
                'hash' => $footprint['hash'],
                'count' => $footprint['count'],
                'range' => $footprint['range'],
                'limit' => $footprint['limit'],
            ],
            'range' => [
                'start' => $startStr,
                'end' => $endStr,
            ],
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

function build_calendar_import_footprint(array $calendarMap, array $calendarCategoryMap, int $limit = 50): array
{
    $timezone = new DateTimeZone(env('APP_TIMEZONE', 'Europe/Zurich') ?: 'Europe/Zurich');
    $start = new DateTimeImmutable('today', $timezone);
    $end = $start->modify('+16 months');
    $entries = array_merge(
        collect_calendar_event_footprint_entries($start->format('Y-m-d'), $end->format('Y-m-d'), $calendarMap, $calendarCategoryMap),
        collect_calendar_service_footprint_entries($start->format('Y-m-d'), $end->format('Y-m-d'))
    );

    usort($entries, static function (array $a, array $b): int {
        $dateCmp = strcmp((string) ($a['startDate'] ?? ''), (string) ($b['startDate'] ?? ''));
        if ($dateCmp !== 0) {
            return $dateCmp;
        }
        $timeCmp = strcmp((string) ($a['startTime'] ?? ''), (string) ($b['startTime'] ?? ''));
        if ($timeCmp !== 0) {
            return $timeCmp;
        }
        return strcmp((string) ($a['key'] ?? ''), (string) ($b['key'] ?? ''));
    });

    $entries = array_slice($entries, 0, max(1, $limit));
    $payload = [
        'version' => 1,
        'limit' => $limit,
        'range' => [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
        ],
        'entries' => $entries,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return [
        'hash' => is_string($json) ? hash('sha256', $json) : '',
        'count' => count($entries),
        'limit' => $limit,
        'range' => $payload['range'],
        'entries' => $entries,
    ];
}

function collect_calendar_event_footprint_entries(string $startStr, string $endStr, array $calendarMap, array $calendarCategoryMap): array
{
    $data = elvanto_post('calendar/events/getAll.json', [
        'start' => $startStr,
        'end' => $endStr,
        'page' => 1,
        'page_size' => 500,
    ]);

    $entries = [];
    foreach (normalize_collection($data['events']['event'] ?? []) as $event) {
        if (!is_array($event) || empty($event['id'])) {
            continue;
        }

        $categoryInfo = calendar_event_category_info($event, $calendarMap, $calendarCategoryMap);
        $category = $categoryInfo['name'];
        $categoryKey = $categoryInfo['key'];
        if (should_exclude_calendar_category($category, $categoryKey)) {
            continue;
        }

        $start = parse_elvanto_datetime_local($event['start_date'] ?? '');
        $end = parse_elvanto_datetime_local($event['end_date'] ?? '');
        if (!$start) {
            continue;
        }

        $allDay = (string) ($event['all_day'] ?? '0') === '1';
        $startTime = $allDay ? '00:00:00' : $start['time'];
        $endTime = $allDay ? '23:59:00' : ($end['time'] ?? '');

        $entries[] = [
            'key' => 'EVENT-' . (string) $event['id'] . '|' . $start['date'] . '|' . $startTime,
            'type' => 'event',
            'id' => (string) $event['id'],
            'title' => normalize_string($event['name'] ?? ''),
            'startDate' => $start['date'],
            'startTime' => $startTime,
            'endDate' => $end['date'] ?? $start['date'],
            'endTime' => $endTime,
            'category' => $category,
            'location' => normalize_string($event['where'] ?? ''),
            'details' => normalize_calendar_footprint_text(extract_event_remark_text($event)),
            'modified' => normalize_string($event['date_modified'] ?? ($event['modified_date'] ?? ($event['updated'] ?? ''))),
        ];
    }
    return $entries;
}

function collect_calendar_service_footprint_entries(string $startStr, string $endStr): array
{
    $data = elvanto_post('services/getAll.json', [
        'start' => $startStr,
        'end' => $endStr,
        'page' => 1,
        'page_size' => 500,
        'fields' => ['service_times'],
    ]);

    $entries = [];
    foreach (normalize_collection($data['services']['service'] ?? []) as $service) {
        if (!is_array($service) || empty($service['id'])) {
            continue;
        }

        $category = get_service_category_name($service);
        $categoryKey = get_service_category_key($service, $category);
        if (should_exclude_calendar_category($category, $categoryKey)) {
            continue;
        }

        foreach (extract_service_times($service) as $time) {
            if (!is_array($time)) {
                continue;
            }
            $start = parse_elvanto_datetime_local($time['starts'] ?? '');
            $end = parse_elvanto_datetime_local($time['ends'] ?? '');
            if (!$start) {
                continue;
            }
            $timeKey = trim((string) ($time['id'] ?? ($time['starts'] ?? '')));
            $entries[] = [
                'key' => 'SERVICE-' . (string) $service['id'] . '|' . $timeKey . '|' . $start['date'] . '|' . $start['time'],
                'type' => 'service',
                'id' => (string) $service['id'],
                'title' => normalize_string($service['name'] ?? ''),
                'startDate' => $start['date'],
                'startTime' => $start['time'],
                'endDate' => $end['date'] ?? $start['date'],
                'endTime' => $end['time'] ?? '',
                'category' => $category,
                'location' => normalize_string($service['location']['name'] ?? ''),
                'details' => normalize_calendar_footprint_text(extract_event_remark_text($service)),
                'modified' => normalize_string($service['date_modified'] ?? ($service['modified_date'] ?? ($service['updated'] ?? ''))),
            ];
        }
    }
    return $entries;
}

function normalize_calendar_footprint_text(string $value): string
{
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim(mb_substr($value, 0, 800));
}

function import_calendar_events(string $startStr, string $endStr, array $calendarMap, array $calendarCategoryMap = []): int
{
    $count = 0;
    $seen = [];
    $page = 1;

    while (true) {
        $data = elvanto_post('calendar/events/getAll.json', [
            'start' => $startStr,
            'end' => $endStr,
            'page' => $page,
            'page_size' => 500,
            'fields' => ['assets', 'locations', 'register_url', 'organizer', 'organiser', 'owner', 'created_by', 'created_by_person'],
        ]);

        $events = normalize_collection($data['events']['event'] ?? []);
        if (!$events) {
            break;
        }

        foreach ($events as $event) {
            if (!is_array($event) || empty($event['id'])) {
                continue;
            }

            $categoryInfo = calendar_event_category_info($event, $calendarMap, $calendarCategoryMap);
            $category = $categoryInfo['name'];
            $categoryKey = $categoryInfo['key'];
            if (should_exclude_calendar_category($category, $categoryKey)) {
                continue;
            }

            $start = parse_elvanto_datetime_local($event['start_date'] ?? '');
            $end = parse_elvanto_datetime_local($event['end_date'] ?? '');
            if (!$start) {
                continue;
            }
            $allDay = (string) ($event['all_day'] ?? '0') === '1';
            $startTime = $allDay ? '00:00:00' : $start['time'];
            $endTime = $allDay ? '23:59:00' : ($end['time'] ?? null);
            $elvantoId = 'EVENT-' . (string) $event['id'];
            $uniqueKey = $elvantoId . '|' . $start['date'] . '|' . $startTime;
            if (isset($seen[$uniqueKey])) {
                continue;
            }
            $seen[$uniqueKey] = true;

            upsert_calendar_event([
                'elvanto_id' => $elvantoId,
                'start_date' => $start['date'],
                'start_time' => $startTime,
                'end_date' => $end['date'] ?? $start['date'],
                'end_time' => $endTime,
                'title' => normalize_string($event['name'] ?? ''),
                'category' => $category,
                'location' => normalize_string($event['where'] ?? ''),
                'details' => extract_event_remark_text($event),
                'status' => map_event_status($event, $categoryInfo['calendarInfo'] ?? []),
                'category_color' => calendar_event_color($event, $categoryInfo, $categoryKey),
                'category_key' => $categoryKey,
                'modified_raw' => normalize_string($event['date_modified'] ?? ($event['modified_date'] ?? ($event['updated'] ?? ''))),
                'modified_at' => parse_elvanto_datetime($event['date_modified'] ?? ($event['modified_date'] ?? ($event['updated'] ?? ''))),
                'resources' => extract_resource_names($event),
                'predigtskript_url' => '',
                'raw_json' => json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $count++;
        }

        if (count($events) < 500) {
            break;
        }
        $page++;
    }

    return $count;
}

function import_calendar_services(string $startStr, string $endStr): int
{
    $count = 0;
    $seen = [];
    $page = 1;

    while (true) {
        $data = elvanto_post('services/getAll.json', [
            'start' => $startStr,
            'end' => $endStr,
            'page' => $page,
            'page_size' => 500,
            'fields' => ['service_times', 'picture'],
        ]);

        $services = normalize_collection($data['services']['service'] ?? []);
        if (!$services) {
            break;
        }

        foreach ($services as $service) {
            if (!is_array($service) || empty($service['id'])) {
                continue;
            }

            $category = get_service_category_name($service);
            $categoryKey = get_service_category_key($service, $category);
            if (should_exclude_calendar_category($category, $categoryKey)) {
                continue;
            }

            $times = extract_service_times($service);
            if (!$times) {
                continue;
            }

            foreach ($times as $time) {
                if (!is_array($time)) {
                    continue;
                }
                $start = parse_elvanto_datetime_local($time['starts'] ?? '');
                $end = parse_elvanto_datetime_local($time['ends'] ?? '');
                if (!$start) {
                    continue;
                }

                $elvantoId = 'SERVICE-' . (string) $service['id'];
                $timeKey = trim((string) ($time['id'] ?? ($time['starts'] ?? '')));
                $uniqueKey = $elvantoId . '|' . $timeKey . '|' . $start['date'] . '|' . $start['time'];
                if (isset($seen[$uniqueKey])) {
                    continue;
                }
                $seen[$uniqueKey] = true;

                upsert_calendar_event([
                    'elvanto_id' => $elvantoId,
                    'start_date' => $start['date'],
                    'start_time' => $start['time'],
                    'end_date' => $end['date'] ?? $start['date'],
                    'end_time' => $end['time'] ?? null,
                    'title' => normalize_string($service['name'] ?? ''),
                    'category' => $category,
                    'location' => normalize_string($service['location']['name'] ?? ''),
                    'details' => extract_event_remark_text($service),
                    'status' => map_service_status($service['status'] ?? null),
                    'category_color' => service_category_color($service, $categoryKey),
                    'category_key' => $categoryKey,
                    'modified_raw' => normalize_string($service['date_modified'] ?? ($service['modified_date'] ?? ($service['updated'] ?? ''))),
                    'modified_at' => parse_elvanto_datetime($service['date_modified'] ?? ($service['modified_date'] ?? ($service['updated'] ?? ''))),
                    'resources' => extract_resource_names($service),
                    'predigtskript_url' => '',
                    'raw_json' => json_encode($service, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
                $count++;
            }
        }

        if (count($services) < 500) {
            break;
        }
        $page++;
    }

    return $count;
}

function fetch_calendar_map(): array
{
    $data = elvanto_post('calendar/getAll.json');
    $calendars = normalize_collection($data['calendars']['calendar'] ?? []);
    $out = [];

    foreach ($calendars as $calendar) {
        if (!is_array($calendar) || empty($calendar['id'])) {
            continue;
        }
        $out[(string) $calendar['id']] = [
            'name' => clean_calendar_category_name(normalize_string($calendar['name'] ?? '')),
            'color' => normalize_hex_color($calendar['colour'] ?? ($calendar['color'] ?? ($calendar['colour_code'] ?? ($calendar['color_code'] ?? '')))),
            'members' => normalize_string($calendar['members'] ?? ''),
            'published' => normalize_string($calendar['published'] ?? ''),
            'public' => normalize_string($calendar['public'] ?? ''),
            'status' => normalize_string($calendar['status'] ?? ''),
            'visibility' => normalize_string($calendar['visibility'] ?? ''),
        ];
    }

    return $out;
}

function fetch_calendar_category_map(): array
{
    try {
        $data = elvanto_post('calendar/categories/getAll.json');
    } catch (Throwable $e) {
        return [];
    }

    $categories = normalize_collection($data['categories']['category'] ?? []);
    $out = [];
    foreach ($categories as $category) {
        if (!is_array($category) || empty($category['id'])) {
            continue;
        }
        $id = trim((string) $category['id']);
        if ($id === '') {
            continue;
        }
        $out[$id] = [
            'name' => clean_calendar_category_name(normalize_string($category['name'] ?? '')),
            'color' => normalize_hex_color($category['colour'] ?? ($category['color'] ?? ($category['colour_code'] ?? ($category['color_code'] ?? '')))),
        ];
    }
    return $out;
}

function upsert_calendar_event(array $row): void
{
    $stmt = db()->prepare(
        'INSERT INTO calendar_events (
            elvanto_id, start_date, start_time, end_date, end_time, title, category, location,
            details, status, category_color, category_key, modified_raw, modified_at,
            resources, predigtskript_url, raw_json, imported_at
         ) VALUES (
            :elvanto_id, :start_date, :start_time, :end_date, :end_time, :title, :category, :location,
            :details, :status, :category_color, :category_key, :modified_raw, :modified_at,
            :resources, :predigtskript_url, :raw_json, :imported_at
         )
         ON DUPLICATE KEY UPDATE
            end_date = VALUES(end_date),
            end_time = VALUES(end_time),
            title = VALUES(title),
            category = VALUES(category),
            location = VALUES(location),
            details = VALUES(details),
            status = VALUES(status),
            category_color = VALUES(category_color),
            category_key = VALUES(category_key),
            modified_raw = VALUES(modified_raw),
            modified_at = VALUES(modified_at),
            resources = VALUES(resources),
            predigtskript_url = VALUES(predigtskript_url),
            raw_json = VALUES(raw_json),
            imported_at = VALUES(imported_at)'
    );
    $stmt->execute([
        ':elvanto_id' => $row['elvanto_id'],
        ':start_date' => $row['start_date'],
        ':start_time' => $row['start_time'],
        ':end_date' => $row['end_date'],
        ':end_time' => $row['end_time'],
        ':title' => $row['title'],
        ':category' => $row['category'],
        ':location' => $row['location'],
        ':details' => $row['details'],
        ':status' => $row['status'],
        ':category_color' => $row['category_color'],
        ':category_key' => $row['category_key'],
        ':modified_raw' => $row['modified_raw'],
        ':modified_at' => $row['modified_at'],
        ':resources' => $row['resources'],
        ':predigtskript_url' => $row['predigtskript_url'],
        ':raw_json' => $row['raw_json'],
        ':imported_at' => date('Y-m-d H:i:s'),
    ]);
}

function parse_elvanto_datetime_local(mixed $value): ?array
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }
    $source = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw, new DateTimeZone('UTC'));
    if (!$source) {
        $ts = strtotime($raw);
        if (!$ts) {
            return null;
        }
        $source = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('UTC'));
    }
    $local = $source->setTimezone(new DateTimeZone(env('APP_TIMEZONE', 'Europe/Zurich') ?: 'Europe/Zurich'));
    return [
        'date' => $local->format('Y-m-d'),
        'time' => $local->format('H:i:s'),
        'datetime' => $local->format('Y-m-d H:i:s'),
    ];
}

function should_exclude_calendar_category(string $category, string $categoryKey): bool
{
    $blockedKeys = ['kalender'];
    $blockedNames = ['Kalender'];
    $keyNorm = mb_strtolower(trim($categoryKey));
    $catNorm = mb_strtolower(trim($category));

    foreach ($blockedKeys as $blocked) {
        if ($keyNorm === mb_strtolower($blocked)) {
            return true;
        }
    }
    foreach ($blockedNames as $blocked) {
        if ($catNorm === mb_strtolower($blocked)) {
            return true;
        }
    }
    return false;
}

function normalize_category_key(string $value): string
{
    $value = mb_strtolower(trim($value));
    $value = str_replace(['ä', 'ö', 'ü', 'ß', '&'], ['ae', 'oe', 'ue', 'ss', ' und '], $value);
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
    $value = trim($value, '_');
    return $value !== '' ? $value : 'cat_unknown';
}

function clean_calendar_category_name(string $value): string
{
    $name = trim($value);
    if ($name === '') {
        return '';
    }
    $clean = preg_replace('/^[^_]*_+/', '', $name, 1) ?? $name;
    $clean = trim($clean);
    if ($clean !== 'Mitarbeiter') {
        $clean = preg_replace('/\s+Mitarbeiter$/iu', '', $clean) ?? $clean;
    }
    return trim($clean) !== '' ? trim($clean) : $name;
}

function normalize_hex_color(string $value): string
{
    $value = trim($value);
    if ($value === '' || str_starts_with($value, 'rgb')) {
        return '';
    }
    $value = ltrim($value, '#');
    if (preg_match('/^[0-9a-fA-F]{3}$/', $value)) {
        $value = $value[0] . $value[0] . $value[1] . $value[1] . $value[2] . $value[2];
    }
    return preg_match('/^[0-9a-fA-F]{6}$/', $value) ? '#' . strtoupper($value) : '';
}

function calendar_event_category_info(array $event, array $calendarMap, array $calendarCategoryMap): array
{
    $calendarId = trim((string) ($event['calendar_id'] ?? ''));
    $calendarInfo = $calendarMap[$calendarId] ?? [];
    $categoryId = trim((string) ($event['category_id'] ?? ''));
    if ($categoryId === '' && is_array($event['category'] ?? null)) {
        $categoryId = trim((string) ($event['category']['id'] ?? ''));
    }
    $categoryInfo = $categoryId !== '' ? ($calendarCategoryMap[$categoryId] ?? []) : [];
    $rawName = normalize_string($calendarInfo['name'] ?? '');
    if ($rawName === '') {
        $rawName = normalize_string($categoryInfo['name'] ?? 'Kalender');
    }
    $name = clean_calendar_category_name($rawName);
    $key = $calendarId !== ''
        ? 'CAL-' . $calendarId
        : ($categoryId !== '' ? 'CAT-' . $categoryId : normalize_category_key($name));

    return [
        'name' => $name,
        'key' => $key,
        'calendarColor' => normalize_hex_color((string) ($calendarInfo['color'] ?? '')),
        'categoryColor' => normalize_hex_color((string) ($categoryInfo['color'] ?? '')),
        'calendarInfo' => $calendarInfo,
    ];
}

function calendar_event_color(array $event, array $categoryInfo, string $categoryKey): string
{
    $payloadColor = extract_payload_color($event);
    if ($payloadColor !== '') {
        return $payloadColor;
    }
    $categoryColor = normalize_hex_color((string) ($categoryInfo['categoryColor'] ?? ''));
    if ($categoryColor !== '') {
        return $categoryColor;
    }
    $calendarColor = normalize_hex_color((string) ($categoryInfo['calendarColor'] ?? ''));
    if ($calendarColor !== '') {
        return $calendarColor;
    }
    return fallback_calendar_color($categoryKey);
}

function service_category_color(array $service, string $categoryKey): string
{
    $payloadColor = extract_payload_color($service);
    return $payloadColor !== '' ? $payloadColor : fallback_calendar_color($categoryKey);
}

function extract_payload_color(array $payload): string
{
    $color = first_payload_color($payload, ['colour', 'color', 'colour_code', 'color_code', 'hex', 'hex_color']);
    if ($color !== '') {
        return $color;
    }

    foreach (['calendar', 'category', 'service_type'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            $color = extract_payload_color($payload[$key]);
            if ($color !== '') {
                return $color;
            }
        }
    }

    $serviceTypes = $payload['service_types']['service_type'] ?? ($payload['service_types'] ?? null);
    foreach (normalize_collection($serviceTypes) as $serviceType) {
        if (is_array($serviceType)) {
            $color = extract_payload_color($serviceType);
            if ($color !== '') {
                return $color;
            }
        }
    }

    return '';
}

function first_payload_color(array $payload, array $keys): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $payload)) {
            continue;
        }
        $value = $payload[$key];
        $color = is_array($value) ? extract_payload_color($value) : normalize_hex_color((string) $value);
        if ($color !== '') {
            return $color;
        }
    }
    return '';
}

function fallback_calendar_color(string $categoryKey): string
{
    $seed = trim($categoryKey);
    if ($seed === '') {
        return '#CBD5E1';
    }
    $palette = [
        '#2563EB',
        '#16A34A',
        '#D97706',
        '#DC2626',
        '#7C3AED',
        '#0891B2',
        '#DB2777',
        '#4F46E5',
        '#059669',
        '#EA580C',
    ];
    return $palette[(int) (abs(crc32($seed)) % count($palette))];
}

function map_event_status(array $event, array $calendarInfo): string
{
    foreach ([$event['status'] ?? null, $event['public'] ?? null, $event['published'] ?? null, $event['visibility'] ?? null] as $candidate) {
        $raw = mb_strtolower(trim((string) $candidate));
        if ($raw === '') {
            continue;
        }
        if (in_array($raw, ['1', 'true', 'yes', 'public', 'published'], true)) {
            return 'public';
        }
        if (in_array($raw, ['private', 'internal', 'members', 'member'], true)) {
            return 'private';
        }
        if (in_array($raw, ['0', 'false', 'no', 'draft'], true)) {
            return 'draft';
        }
    }
    $members = mb_strtolower(trim((string) ($calendarInfo['members'] ?? '')));
    if (in_array($members, ['true', '1', 'yes'], true)) {
        return 'private';
    }
    if (in_array($members, ['false', '0', 'no'], true)) {
        return 'public';
    }
    return 'draft';
}

function map_service_status(mixed $value): string
{
    $raw = mb_strtolower(trim((string) $value));
    if ($raw === '') {
        return 'draft';
    }
    if (in_array($raw, ['1', 'true', 'yes', 'published', 'public'], true)) {
        return 'public';
    }
    if (in_array($raw, ['private', 'internal', 'members', 'member'], true)) {
        return 'private';
    }
    return 'draft';
}

function get_service_category_name(array $service): string
{
    if (!empty($service['service_type']['name'])) {
        return normalize_string($service['service_type']['name']);
    }
    if (!empty($service['service_types'][0]['name'])) {
        return normalize_string($service['service_types'][0]['name']);
    }
    if (!empty($service['service_types']['service_type'][0]['name'])) {
        return normalize_string($service['service_types']['service_type'][0]['name']);
    }
    return 'Service';
}

function get_service_category_key(array $service, string $fallbackName): string
{
    if (!empty($service['service_type']['id'])) {
        return 'SVCTYPE-' . trim((string) $service['service_type']['id']);
    }
    if (!empty($service['service_types'][0]['id'])) {
        return 'SVCTYPE-' . trim((string) $service['service_types'][0]['id']);
    }
    if (!empty($service['service_types']['service_type'][0]['id'])) {
        return 'SVCTYPE-' . trim((string) $service['service_types']['service_type'][0]['id']);
    }
    return 'SVCTYPE-NAME-' . normalize_category_key($fallbackName);
}

function extract_service_times(array $service): array
{
    $times = $service['service_times'] ?? null;
    if (!$times) {
        return [];
    }
    if (is_array($times) && isset($times['service_time'])) {
        return normalize_collection($times['service_time']);
    }
    return normalize_collection($times);
}

function extract_event_remark_text(array $obj): string
{
    foreach (['remark', 'remarks', 'notes', 'note', 'comment', 'comments', 'description'] as $key) {
        $value = normalize_string($obj[$key] ?? '');
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function extract_resource_names(array $obj): string
{
    $names = [];
    foreach (['assets', 'asset', 'resources', 'resource'] as $key) {
        if (!isset($obj[$key])) {
            continue;
        }
        foreach (normalize_collection(is_array($obj[$key]) && isset($obj[$key]['asset']) ? $obj[$key]['asset'] : $obj[$key]) as $item) {
            if (is_array($item)) {
                $name = normalize_string($item['name'] ?? ($item['resource_name'] ?? ($item['title'] ?? ($item['label'] ?? ''))));
            } else {
                $name = normalize_string($item);
            }
            if ($name !== '') {
                $names[] = $name;
            }
        }
    }
    return implode(', ', array_values(array_unique($names)));
}
