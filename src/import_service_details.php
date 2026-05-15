<?php

declare(strict_types=1);

require_once __DIR__ . '/import_calendar.php';

function import_service_details(?int $limit = null): array
{
    $startedAt = date('Y-m-d H:i:s');
    $runId = import_run_start('service_details');
    $lockAcquired = false;

    try {
        $lockAcquired = acquire_service_details_import_lock();
        if (!$lockAcquired) {
            throw new RuntimeException('Service-Detailimport laeuft bereits. Bitte den laufenden Import abwarten.');
        }

        $serviceIds = db()->query(
            "SELECT DISTINCT REPLACE(elvanto_id, 'SERVICE-', '') AS service_id
             FROM calendar_events
             WHERE elvanto_id LIKE 'SERVICE-%'
             ORDER BY start_date"
        )->fetchAll();
        if ($limit !== null && $limit > 0) {
            $serviceIds = array_slice($serviceIds, 0, $limit);
        }

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
                'fields' => ['service_times', 'rehearsal_times', 'other_times', 'plans', 'volunteers', 'songs', 'notes', 'files'],
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
        release_service_details_import_lock();
        $lockAcquired = false;

        import_run_finish($runId, 'ok', $services, "Imported {$services} services, {$times} times, {$volunteers} volunteers, {$planItems} plan items.");

        return [
            'ok' => true,
            'type' => 'service_details',
            'services' => $services,
            'times' => $times,
            'volunteers' => $volunteers,
            'planItems' => $planItems,
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
    $personId = normalize_string($volunteer['person_id'] ?? ($volunteer['person']['id'] ?? ($volunteer['id'] ?? '')));
    $displayName = normalize_string($volunteer['name'] ?? ($volunteer['display_name'] ?? ($volunteer['person']['name'] ?? '')));
    if ($displayName === '') {
        $displayName = trim(normalize_string($volunteer['firstname'] ?? ($volunteer['person']['firstname'] ?? '')) . ' ' . normalize_string($volunteer['lastname'] ?? ($volunteer['person']['lastname'] ?? '')));
    }
    $role = normalize_string($volunteer['role'] ?? ($volunteer['role_name'] ?? ($volunteer['position'] ?? ($volunteer['position_name'] ?? ''))));
    $team = normalize_string($volunteer['team'] ?? ($volunteer['team_name'] ?? ($volunteer['department'] ?? '')));

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
    foreach ([
        ['volunteers', 'volunteer'],
        ['volunteers'],
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
                $planItems = array_merge($planItems, $items);
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
    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $raw, $m)) {
        return ((int) $m[1]) * 60 + (int) $m[2];
    }
    if (preg_match('/\d+/', $raw, $m)) {
        return (int) $m[0];
    }
    return null;
}
