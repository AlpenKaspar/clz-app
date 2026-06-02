<?php

declare(strict_types=1);

require_once __DIR__ . '/import_calendar.php';

function import_service_details(?int $limit = null, bool $force = false, int $footprintLimit = 3): array
{
    $startedAt = date('Y-m-d H:i:s');
    $runId = import_run_start('service_details');
    $lockAcquired = false;

    try {
        $lockAcquired = acquire_service_details_import_lock();
        if (!$lockAcquired) {
            throw new RuntimeException('Service-Detailimport laeuft bereits. Bitte den laufenden Import abwarten.');
        }

        $footprint = null;
        if (!$force) {
            $footprint = build_service_details_import_footprint($footprintLimit);
            $previousFootprint = app_setting('IMPORT_SERVICE_DETAILS_FOOTPRINT', '');
            if ($footprint['hash'] !== '' && hash_equals($previousFootprint, $footprint['hash'])) {
                set_app_setting('IMPORT_SERVICE_DETAILS_LAST_CHECK', date('c'));
                set_app_setting('IMPORT_SERVICE_DETAILS_FOOTPRINT_META', json_encode($footprint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                release_service_details_import_lock();
                $lockAcquired = false;
                import_run_finish($runId, 'ok', 0, 'Skipped service details import; footprint unchanged.');

                return [
                    'ok' => true,
                    'type' => 'service_details',
                    'skipped' => true,
                    'reason' => 'footprint_unchanged',
                    'services' => 0,
                    'times' => 0,
                    'volunteers' => 0,
                    'planItems' => 0,
                    'footprint' => service_details_footprint_public_meta($footprint),
                    'startedAt' => $startedAt,
                    'finishedAt' => date('Y-m-d H:i:s'),
                ];
            }
        }

        $serviceIds = $force
            ? load_service_detail_import_service_ids($limit)
            : array_map(
                static fn(array $entry): array => ['service_id' => (string) ($entry['id'] ?? '')],
                $footprint['entries'] ?? []
            );

        $services = 0;
        $times = 0;
        $volunteers = 0;
        $planItems = 0;

        foreach ($serviceIds as $row) {
            $serviceId = trim((string) ($row['service_id'] ?? ''));
            if ($serviceId === '') {
                continue;
            }

            $data = elvanto_post('services/getInfo.json', [
                'id' => $serviceId,
                'fields' => ['service_times', 'rehearsal_times', 'other_times', 'plans', 'volunteers', 'songs', 'notes', 'files', 'picture'],
            ]);
            $service = extract_service_detail_payload($data);
            if (!$service) {
                continue;
            }

            $counts = save_service_detail_bundle($serviceId, $service);
            $services++;
            $times += $counts['times'];
            $volunteers += $counts['volunteers'];
            $planItems += $counts['planItems'];
        }

        set_app_setting('IMPORT_SERVICE_DETAILS_LAST', date('c'));
        set_app_setting('IMPORT_SERVICE_DETAILS_LAST_CHECK', date('c'));
        if ($footprint !== null) {
            set_app_setting('IMPORT_SERVICE_DETAILS_FOOTPRINT', $footprint['hash']);
            set_app_setting('IMPORT_SERVICE_DETAILS_FOOTPRINT_META', json_encode($footprint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        release_service_details_import_lock();
        $lockAcquired = false;

        $forceText = $force ? ' Forced import.' : '';
        $footprintText = $footprint !== null ? ' Footprint checked.' : '';
        import_run_finish($runId, 'ok', $services, "Imported {$services} services, {$times} times, {$volunteers} volunteers, {$planItems} plan items.{$forceText}{$footprintText}");

        return [
            'ok' => true,
            'type' => 'service_details',
            'skipped' => false,
            'forced' => $force,
            'services' => $services,
            'times' => $times,
            'volunteers' => $volunteers,
            'planItems' => $planItems,
            'footprint' => $footprint !== null ? service_details_footprint_public_meta($footprint) : null,
            'startedAt' => $startedAt,
            'finishedAt' => date('Y-m-d H:i:s'),
        ];
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        if ($lockAcquired) {
            release_service_details_import_lock();
        }
        import_run_finish($runId, 'error', 0, $e->getMessage());
        throw $e;
    }
}

function load_service_detail_import_service_ids(?int $limit = null): array
{
    $rows = db()->query(
        "SELECT REPLACE(elvanto_id, 'SERVICE-', '') AS service_id
         FROM calendar_events
         WHERE elvanto_id LIKE 'SERVICE-%'
         GROUP BY elvanto_id
         ORDER BY MIN(start_date), MIN(start_time)"
    )->fetchAll();

    return $limit !== null && $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
}

function build_service_details_import_footprint(int $limit = 3): array
{
    $serviceRows = fetch_upcoming_service_detail_footprint_services($limit);
    $entries = [];

    foreach ($serviceRows as $row) {
        $serviceId = trim((string) ($row['service_id'] ?? ''));
        if ($serviceId === '') {
            continue;
        }
        $data = elvanto_post('services/getInfo.json', [
            'id' => $serviceId,
            'fields' => ['service_times', 'rehearsal_times', 'other_times', 'plans', 'volunteers', 'songs', 'notes', 'files', 'picture'],
        ]);
        $service = extract_service_detail_payload($data);
        if (!$service) {
            continue;
        }
        $entries[] = service_detail_footprint_entry($serviceId, $service, $row);
    }

    $payload = [
        'version' => 1,
        'limit' => $limit,
        'generatedAt' => date('c'),
        'entries' => $entries,
    ];
    $hashPayload = $payload;
    unset($hashPayload['generatedAt']);
    $json = json_encode($hashPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return [
        'hash' => is_string($json) ? hash('sha256', $json) : '',
        'count' => count($entries),
        'limit' => $limit,
        'entries' => $entries,
        'generatedAt' => $payload['generatedAt'],
    ];
}

function fetch_upcoming_service_detail_footprint_services(int $limit): array
{
    $stmt = db()->prepare(
        "SELECT REPLACE(elvanto_id, 'SERVICE-', '') AS service_id,
                MIN(start_date) AS start_date,
                MIN(start_time) AS start_time,
                MIN(title) AS title
         FROM calendar_events
         WHERE elvanto_id LIKE 'SERVICE-%'
           AND start_date >= ?
         GROUP BY elvanto_id
         ORDER BY MIN(start_date), MIN(start_time), MIN(title)
         LIMIT " . max(1, $limit)
    );
    $stmt->execute([(new DateTimeImmutable('today', new DateTimeZone(env('APP_TIMEZONE', 'Europe/Zurich') ?: 'Europe/Zurich')))->format('Y-m-d')]);
    return $stmt->fetchAll();
}

function service_detail_footprint_entry(string $serviceId, array $service, array $calendarRow): array
{
    return [
        'id' => $serviceId,
        'calendarDate' => normalize_string($calendarRow['start_date'] ?? ''),
        'calendarTime' => normalize_string($calendarRow['start_time'] ?? ''),
        'title' => normalize_string($service['name'] ?? ($calendarRow['title'] ?? '')),
        'status' => normalize_string($service['status'] ?? ''),
        'modified' => normalize_string($service['date_modified'] ?? ($service['modified_date'] ?? ($service['updated'] ?? ''))),
        'serviceTimes' => service_detail_footprint_times($service, ['service_times']),
        'rehearsalTimes' => service_detail_footprint_times($service, ['rehearsal_times', 'rehearsals']),
        'otherTimes' => service_detail_footprint_times($service, ['other_times']),
        'plans' => service_detail_footprint_plans($service),
        'volunteers' => service_detail_footprint_volunteers($service),
        'files' => service_detail_footprint_files($service),
        'notes' => normalize_calendar_footprint_text(extract_event_remark_text($service)),
    ];
}

function service_detail_footprint_times(array $service, array $keys): array
{
    $out = [];
    foreach ($keys as $key) {
        foreach (service_get_path_array($service, [$key, rtrim($key, 's')]) ?: normalize_collection($service[$key] ?? []) as $time) {
            if (!is_array($time)) {
                continue;
            }
            $out[] = [
                'id' => normalize_string($time['id'] ?? ''),
                'name' => normalize_string($time['name'] ?? ($time['label'] ?? '')),
                'starts' => normalize_string($time['starts'] ?? ($time['start'] ?? '')),
                'ends' => normalize_string($time['ends'] ?? ($time['end'] ?? '')),
            ];
        }
    }
    return $out;
}

function service_detail_footprint_plans(array $service): array
{
    $out = [];
    $order = 0;
    foreach (extract_service_plan_items($service) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $order++;
        $out[] = [
            'order' => $order,
            'title' => normalize_string($item['title'] ?? ($item['name'] ?? ($item['heading'] ?? ''))),
            'type' => normalize_string($item['type'] ?? ($item['item_type'] ?? '')),
            'starts' => normalize_string($item['starts'] ?? ($item['start_time'] ?? ($item['time'] ?? ''))),
            'duration' => normalize_string($item['duration'] ?? ($item['duration_min'] ?? '')),
            'description' => normalize_calendar_footprint_text(normalize_string($item['description'] ?? ($item['details'] ?? ($item['note'] ?? '')))),
            'song' => normalize_string($item['song']['title'] ?? ($item['song_title'] ?? '')),
        ];
    }
    return $out;
}

function service_detail_footprint_volunteers(array $service): array
{
    $out = [];
    foreach (extract_service_volunteers($service) as $volunteer) {
        if (!is_array($volunteer)) {
            continue;
        }
        $person = is_array($volunteer['person'] ?? null) ? $volunteer['person'] : [];
        $out[] = [
            'personId' => normalize_string($volunteer['person_id'] ?? ($person['id'] ?? ($volunteer['id'] ?? ''))),
            'name' => normalize_string($volunteer['name'] ?? ($volunteer['display_name'] ?? ($person['name'] ?? ''))),
            'department' => normalize_string($volunteer['_department_name'] ?? ''),
            'subDepartment' => normalize_string($volunteer['_sub_department_name'] ?? ''),
            'position' => normalize_string($volunteer['_position_name'] ?? ($volunteer['position_name'] ?? ($volunteer['role'] ?? ''))),
            'status' => normalize_string($volunteer['status'] ?? ''),
        ];
    }
    usort($out, static fn(array $a, array $b): int => implode('|', $a) <=> implode('|', $b));
    return $out;
}

function service_detail_footprint_files(array $service): array
{
    $files = service_get_path_array($service, ['files', 'file']) ?: normalize_collection($service['files'] ?? []);
    $out = [];
    foreach ($files as $file) {
        if (!is_array($file)) {
            continue;
        }
        $out[] = [
            'id' => normalize_string($file['id'] ?? ''),
            'name' => normalize_string($file['name'] ?? ($file['title'] ?? '')),
            'url' => normalize_string($file['url'] ?? ($file['link'] ?? '')),
        ];
    }
    return $out;
}

function service_details_footprint_public_meta(array $footprint): array
{
    return [
        'hash' => $footprint['hash'] ?? '',
        'count' => $footprint['count'] ?? 0,
        'limit' => $footprint['limit'] ?? 0,
        'services' => array_map(
            static fn(array $entry): array => [
                'id' => (string) ($entry['id'] ?? ''),
                'date' => (string) ($entry['calendarDate'] ?? ''),
                'time' => (string) ($entry['calendarTime'] ?? ''),
                'title' => (string) ($entry['title'] ?? ''),
            ],
            $footprint['entries'] ?? []
        ),
    ];
}

function save_service_detail_bundle(string $serviceId, array $service): array
{
    $pdo = db();
    $pdo->beginTransaction();

    db_delete_service_detail_bundle($serviceId);
    upsert_service_detail($serviceId, $service);

    $times = 0;
    foreach (extract_service_times($service) as $time) {
        if (is_array($time)) {
            upsert_service_time($serviceId, $time);
            $times++;
        }
    }

    $volunteers = 0;
    foreach (extract_service_volunteers($service) as $volunteer) {
        if (is_array($volunteer)) {
            upsert_service_volunteer($serviceId, $volunteer);
            $volunteers++;
        }
    }

    $planItems = 0;
    $order = 0;
    foreach (extract_service_plan_items($service) as $item) {
        if (is_array($item)) {
            $order++;
            upsert_service_plan_item($serviceId, $item, $order);
            $planItems++;
        }
    }

    $pdo->commit();

    return [
        'times' => $times,
        'volunteers' => $volunteers,
        'planItems' => $planItems,
    ];
}

function db_delete_service_detail_bundle(string $serviceId): void
{
    foreach (['service_plan_items', 'service_volunteers', 'service_times', 'services'] as $table) {
        $stmt = db()->prepare("DELETE FROM {$table} WHERE service_id = ?");
        $stmt->execute([$serviceId]);
    }
}

function acquire_service_details_import_lock(): bool
{
    $stmt = db()->query("SELECT GET_LOCK('clz_service_details_import', 0) AS lock_status");
    return (int) ($stmt->fetch()['lock_status'] ?? 0) === 1;
}

function release_service_details_import_lock(): void
{
    db()->query("SELECT RELEASE_LOCK('clz_service_details_import')");
}

function extract_service_detail_payload(array $data): array
{
    foreach ([['service'], ['services', 'service'], ['data', 'service']] as $path) {
        $items = service_get_path_array($data, $path);
        if (is_array($items[0] ?? null)) {
            return $items[0];
        }
    }
    if (isset($data['id']) || isset($data['name'])) {
        return $data;
    }
    return [];
}

function service_get_path_array(array $data, array $path): array
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

function upsert_service_detail(string $serviceId, array $service): void
{
    $times = extract_service_times($service);
    $first = is_array($times[0] ?? null) ? parse_elvanto_datetime_local($times[0]['starts'] ?? '') : null;
    $last = is_array($times[0] ?? null) ? parse_elvanto_datetime_local($times[0]['ends'] ?? '') : null;

    $stmt = db()->prepare(
        'INSERT INTO services (service_id, title, category, location, status, service_start, service_end, details, resources, raw_json, imported_at)
         VALUES (:service_id, :title, :category, :location, :status, :service_start, :service_end, :details, :resources, :raw_json, :imported_at)
         ON DUPLICATE KEY UPDATE title = VALUES(title), category = VALUES(category), location = VALUES(location),
            status = VALUES(status), service_start = VALUES(service_start), service_end = VALUES(service_end),
            details = VALUES(details), resources = VALUES(resources), raw_json = VALUES(raw_json), imported_at = VALUES(imported_at)'
    );
    $stmt->execute([
        ':service_id' => $serviceId,
        ':title' => normalize_string($service['name'] ?? ''),
        ':category' => get_service_category_name($service),
        ':location' => normalize_string($service['location']['name'] ?? ''),
        ':status' => map_service_status($service['status'] ?? null),
        ':service_start' => $first['datetime'] ?? null,
        ':service_end' => $last['datetime'] ?? null,
        ':details' => extract_event_remark_text($service),
        ':resources' => extract_resource_names($service),
        ':raw_json' => json_encode($service, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':imported_at' => date('Y-m-d H:i:s'),
    ]);
}

function upsert_service_time(string $serviceId, array $time): void
{
    $start = parse_elvanto_datetime_local($time['starts'] ?? '');
    if (!$start) {
        return;
    }
    $end = parse_elvanto_datetime_local($time['ends'] ?? '');
    $stmt = db()->prepare(
        'INSERT INTO service_times (service_id, elvanto_time_id, starts_at, ends_at, label)
         VALUES (:service_id, :elvanto_time_id, :starts_at, :ends_at, :label)
         ON DUPLICATE KEY UPDATE ends_at = VALUES(ends_at), label = VALUES(label)'
    );
    $stmt->execute([
        ':service_id' => $serviceId,
        ':elvanto_time_id' => normalize_string($time['id'] ?? ''),
        ':starts_at' => $start['datetime'],
        ':ends_at' => $end['datetime'] ?? null,
        ':label' => normalize_string($time['name'] ?? ($time['label'] ?? '')),
    ]);
}

function upsert_service_volunteer(string $serviceId, array $volunteer): void
{
    $person = is_array($volunteer['person'] ?? null) ? $volunteer['person'] : [];
    $personId = normalize_string($volunteer['person_id'] ?? ($person['id'] ?? ($volunteer['id'] ?? '')));
    $displayName = normalize_string($volunteer['name'] ?? ($volunteer['display_name'] ?? ($person['name'] ?? '')));
    if ($displayName === '') {
        $firstName = normalize_string($person['preferred_name'] ?? '');
        if ($firstName === '') {
            $firstName = normalize_string($volunteer['firstname'] ?? ($person['firstname'] ?? ''));
        }
        $displayName = trim($firstName . ' ' . normalize_string($volunteer['lastname'] ?? ($person['lastname'] ?? '')));
    }
    if ($personId === '' && $displayName === '') {
        return;
    }

    $role = normalize_string($volunteer['_position_name'] ?? ($volunteer['role'] ?? ($volunteer['role_name'] ?? ($volunteer['position'] ?? ($volunteer['position_name'] ?? '')))));
    $department = normalize_string($volunteer['_department_name'] ?? '');
    $subDepartment = normalize_string($volunteer['_sub_department_name'] ?? '');
    $team = trim($department . ($department !== '' && $subDepartment !== '' ? ' / ' : '') . $subDepartment);
    if ($team === '') {
        $team = normalize_string($volunteer['team'] ?? ($volunteer['team_name'] ?? ($volunteer['department'] ?? '')));
    }

    $stmt = db()->prepare(
        'INSERT INTO service_volunteers (service_id, person_id, display_name, role, status, team, raw_json, imported_at)
         VALUES (:service_id, :person_id, :display_name, :role, :status, :team, :raw_json, :imported_at)'
    );
    $stmt->execute([
        ':service_id' => $serviceId,
        ':person_id' => $personId !== '' ? $personId : null,
        ':display_name' => $displayName,
        ':role' => $role,
        ':status' => normalize_string($volunteer['status'] ?? ''),
        ':team' => $team,
        ':raw_json' => json_encode($volunteer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':imported_at' => date('Y-m-d H:i:s'),
    ]);
}

function upsert_service_plan_item(string $serviceId, array $item, int $order): void
{
    $startsAt = parse_elvanto_datetime_local($item['starts'] ?? ($item['start_time'] ?? ($item['time'] ?? '')));
    $stmt = db()->prepare(
        'INSERT INTO service_plan_items (service_id, item_order, title, item_type, starts_at, duration_min, description, song_title, raw_json, imported_at)
         VALUES (:service_id, :item_order, :title, :item_type, :starts_at, :duration_min, :description, :song_title, :raw_json, :imported_at)'
    );
    $stmt->execute([
        ':service_id' => $serviceId,
        ':item_order' => $order,
        ':title' => normalize_string($item['title'] ?? ($item['name'] ?? ($item['heading'] ?? ''))),
        ':item_type' => normalize_string($item['type'] ?? ($item['item_type'] ?? '')),
        ':starts_at' => $startsAt['datetime'] ?? null,
        ':duration_min' => parse_duration_minutes($item['duration'] ?? ($item['duration_min'] ?? null)),
        ':description' => normalize_string($item['description'] ?? ($item['details'] ?? ($item['note'] ?? ''))),
        ':song_title' => normalize_string($item['song']['title'] ?? ($item['song_title'] ?? '')),
        ':raw_json' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':imported_at' => date('Y-m-d H:i:s'),
    ]);
}

function extract_service_volunteers(array $service): array
{
    $nested = extract_service_plan_volunteers($service);
    if ($nested) {
        return $nested;
    }

    foreach ([
        ['volunteers', 'volunteer'],
        ['people', 'person'],
        ['people'],
    ] as $path) {
        $items = service_get_path_array($service, $path);
        if ($items) {
            return $items;
        }
    }
    return [];
}

function extract_service_plan_volunteers(array $service): array
{
    $plans = [];
    if (is_array($service['volunteers'] ?? null) && array_key_exists('plan', $service['volunteers'])) {
        $plans = normalize_collection($service['volunteers']['plan']);
    }
    if (!$plans) {
        return [];
    }

    $volunteers = [];
    foreach ($plans as $plan) {
        if (!is_array($plan)) {
            continue;
        }
        $positions = [];
        if (is_array($plan['positions'] ?? null) && array_key_exists('position', $plan['positions'])) {
            $positions = normalize_collection($plan['positions']['position']);
        } elseif (is_array($plan['positions'] ?? null)) {
            $positions = normalize_collection($plan['positions']);
        }

        foreach ($positions as $position) {
            if (!is_array($position)) {
                continue;
            }
            $positionVolunteers = [];
            if (is_array($position['volunteers'] ?? null) && array_key_exists('volunteer', $position['volunteers'])) {
                $positionVolunteers = normalize_collection($position['volunteers']['volunteer']);
            }

            foreach ($positionVolunteers as $volunteer) {
                if (!is_array($volunteer)) {
                    continue;
                }
                $volunteer['_time_id'] = normalize_string($plan['time_id'] ?? '');
                $volunteer['_department_name'] = normalize_string($position['department_name'] ?? '');
                $volunteer['_sub_department_name'] = normalize_string($position['sub_department_name'] ?? '');
                $volunteer['_position_name'] = normalize_string($position['position_name'] ?? '');
                $volunteers[] = $volunteer;
            }
        }
    }

    return $volunteers;
}

function extract_service_plan_items(array $service): array
{
    $plans = $service['plans'] ?? null;
    if (!$plans) {
        return [];
    }

    $planItems = [];
    $planList = [];
    if (is_array($plans) && isset($plans['plan'])) {
        $planList = normalize_collection($plans['plan']);
    } else {
        $planList = normalize_collection($plans);
    }

    foreach ($planList as $plan) {
        if (!is_array($plan)) {
            continue;
        }
        foreach ([
            ['items', 'item'],
            ['items'],
            ['plan_items', 'plan_item'],
            ['plan_items'],
        ] as $path) {
            $items = service_get_path_array($plan, $path);
            if ($items) {
                $timeId = normalize_string($plan['time_id'] ?? '');
                foreach ($items as $item) {
                    if (is_array($item)) {
                        $item['_time_id'] = $timeId;
                        $item['_plan_service_length'] = $plan['service_length'] ?? null;
                        $item['_plan_total_length'] = $plan['total_length'] ?? null;
                    }
                    $planItems[] = $item;
                }
                break;
            }
        }
    }

    return $planItems;
}

function parse_duration_minutes(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_numeric($value)) {
        $n = (int) $value;
        return $n > 180 ? (int) round($n / 60) : $n;
    }
    $raw = trim((string) $value);
    if (preg_match('/^(\d+):(\d{2})(?::(\d{2}))?$/', $raw, $m)) {
        if (isset($m[3]) && $m[3] !== '') {
            return ((int) $m[1]) * 60 + (int) $m[2] + ((int) $m[3] >= 30 ? 1 : 0);
        }
        return (int) $m[1] + ((int) $m[2] >= 30 ? 1 : 0);
    }
    if (preg_match('/\d+/', $raw, $m)) {
        return (int) $m[0];
    }
    return null;
}
