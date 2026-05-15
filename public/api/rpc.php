<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

try {
    $user = require_user();
    $body = read_json_body();
    $fn = trim((string) ($body['fn'] ?? ''));
    $args = is_array($body['args'] ?? null) ? $body['args'] : [];

    json_response(rpc_dispatch($fn, $args, $user));
} catch (Throwable $e) {
    json_error('API Fehler.', 500, [
        'detail' => env('APP_DEBUG', '0') === '1' ? $e->getMessage() : null,
    ]);
}

function rpc_dispatch(string $fn, array $args, array $user): array
{
    return match ($fn) {
        'app_ping' => rpc_ping(),
        'app_bootstrap' => rpc_bootstrap($user),
        'app_getUserAccessLight' => ['ok' => true, 'user' => $user, 'permissions' => rpc_permissions()],
        'app_loadContactsLite' => rpc_contacts_lite(),
        'personenUi_getMainDetails' => rpc_person_main((string) ($args[0] ?? '')),
        'personenUi_getFullDetails' => rpc_person_full((string) ($args[0] ?? '')),
        'personenUi_getExtraDetails' => rpc_person_extra((string) ($args[0] ?? '')),
        'personenUi_extendedSearch' => rpc_extended_search((string) ($args[0] ?? '')),
        'personenUi_getFamily' => rpc_family((string) ($args[0] ?? '')),
        'personenUi_getGroupByName' => rpc_group((string) ($args[0] ?? '')),
        'getCalendarEventsRange' => rpc_calendar((string) ($args[0] ?? ''), (string) ($args[1] ?? '')),
        'kalenderUi_getServiceOverview' => rpc_service_overview($args[0] ?? []),
        'kalenderUi_getServiceStaff' => rpc_service_staff($args[0] ?? []),
        'kalenderUi_getServiceFlow' => rpc_service_flow($args[0] ?? []),
        'kalenderUi_getServiceDetails', 'kalenderUi_refreshServiceDetails' => rpc_service_details($args[0] ?? []),
        'kalenderUi_getPersonServiceAssignments' => ['ok' => true, 'serviceIds' => [], 'count' => 0],
        'app_loadSongsLite' => rpc_songs_lite(),
        'app_loadFilterDefs' => ['ok' => true, 'filters' => rpc_contact_filters(), 'dataVersion' => rpc_data_version()],
        'app_loadDashboardStats' => ['ok' => true, 'dashboard' => rpc_dashboard(), 'dataVersion' => rpc_data_version()],
        'tools_getSyncStatus' => ['ok' => true, 'status' => 'ok', 'cache' => [], 'latestRuns' => rpc_import_runs()],
        'tools_rebuildServerCaches' => ['ok' => true, 'dataVersion' => rpc_data_version()],
        'tools_loadUserSmartFilters' => ['ok' => true, 'filters' => []],
        'tools_saveUserSmartFilters' => ['ok' => true],
        'tools_importKalender', 'tools_importPersonen' => ['ok' => false, 'error' => 'Import bitte ueber Admin-Endpunkte starten.'],
        'personenUi_getPrayerDeck', 'prayerDeck_getByPool' => ['ok' => true, 'cards' => []],
        'prayerPools_get' => ['ok' => true, 'pools' => []],
        'prayerPools_getMembers' => ['ok' => true, 'members' => []],
        'prayerPools_create', 'prayerPools_delete', 'prayerPools_addMembers', 'prayerPools_removeMembers' => ['ok' => true],
        'prayer_startSession' => ['ok' => true, 'sessionId' => bin2hex(random_bytes(8))],
        'prayer_heartbeat', 'prayer_endSession' => ['ok' => true],
        'prayer_getLeaderboard' => ['ok' => true, 'rows' => []],
        'app_exportFilteredContactsCsv', 'app_getFilteredContactsPrintRows' => ['ok' => true, 'rows' => []],
        default => ['ok' => true],
    };
}

function rpc_ping(): array
{
    return [
        'ok' => true,
        'ts' => date('c'),
        'dataVersion' => rpc_data_version(),
    ];
}

function rpc_bootstrap(array $user): array
{
    return [
        'ok' => true,
        'ts' => date('c'),
        'dataVersion' => rpc_data_version(),
        'user' => [
            'email' => $user['email'] ?? '',
            'role' => $user['role'] ?? 'member',
            'permissions' => rpc_permissions(),
        ],
        'cache' => [],
        'filters' => rpc_contact_filters(),
        'dashboard' => rpc_dashboard(),
    ];
}

function rpc_permissions(): array
{
    return [
        'tabs' => [
            'contacts' => true,
            'calendar' => true,
            'dashboard' => true,
            'tools' => true,
        ],
        'exports' => [
            'contactsCsv' => false,
            'contactsPrint' => false,
            'calendarCsv' => false,
            'calendarPrint' => false,
        ],
        'detailPrint' => [
            'contact' => true,
            'event' => true,
        ],
    ];
}

function rpc_data_version(): string
{
    static $version = null;
    if ($version !== null) {
        return $version;
    }

    try {
        $row = db()->query(
            "SELECT GREATEST(
                COALESCE((SELECT MAX(imported_at) FROM people), '1970-01-01 00:00:00'),
                COALESCE((SELECT MAX(imported_at) FROM calendar_events), '1970-01-01 00:00:00'),
                COALESCE((SELECT MAX(imported_at) FROM services), '1970-01-01 00:00:00')
            ) AS version_value"
        )->fetch();
        $version = preg_replace('/\D+/', '', (string) ($row['version_value'] ?? '')) ?: '1';
    } catch (Throwable) {
        $version = '1';
    }
    return $version;
}

function rpc_contacts_lite(): array
{
    $stmt = db()->query(
        "SELECT id, firstname, preferred_name, lastname, display_name, email, phone, mobile,
                category_name, family_id, gender, birthday, home_address, home_city, home_postcode, picture_url
         FROM people
         ORDER BY lastname, firstname"
    );

    $contacts = [];
    foreach ($stmt as $row) {
        $contacts[] = rpc_contact_row($row);
    }

    return [
        'ok' => true,
        'ts' => date('c'),
        'dataVersion' => rpc_data_version(),
        'contacts' => $contacts,
    ];
}

function rpc_contact_row(array $row): array
{
    $first = rpc_str($row['firstname'] ?? '');
    $preferred = rpc_str($row['preferred_name'] ?? '');
    $last = rpc_str($row['lastname'] ?? '');
    $display = rpc_str($row['display_name'] ?? '') ?: trim(($preferred ?: $first) . ' ' . $last);
    $cityLine = trim(rpc_str($row['home_postcode'] ?? '') . ' ' . rpc_str($row['home_city'] ?? ''));
    $category = rpc_str($row['category_name'] ?? '');
    $birthday = rpc_str($row['birthday'] ?? '');
    $age = rpc_age($birthday);

    return [
        'id' => rpc_str($row['id'] ?? ''),
        'displayName' => $display,
        'listDisplayName' => $display,
        'firstName' => $first,
        'preferredName' => $preferred,
        'lastName' => $last,
        'email' => rpc_str($row['email'] ?? ''),
        'phone' => rpc_str($row['phone'] ?? ''),
        'mobile' => rpc_str($row['mobile'] ?? ''),
        'category' => $category,
        'familyId' => rpc_str($row['family_id'] ?? ''),
        'gender' => rpc_str($row['gender'] ?? ''),
        'birthday' => $birthday,
        'age' => $age,
        'isChild' => $age !== null && $age < 16,
        'isFamilyMain' => true,
        'pictureUrl' => rpc_str($row['picture_url'] ?? ''),
        'address' => rpc_str($row['home_address'] ?? ''),
        'city' => rpc_str($row['home_city'] ?? ''),
        'postcode' => rpc_str($row['home_postcode'] ?? ''),
        'sub' => implode(' - ', array_values(array_filter([$category, $cityLine]))),
        'searchText' => strtolower($display . ' ' . $first . ' ' . $preferred . ' ' . $last . ' ' . $category . ' ' . $cityLine),
        'kgGroupValues' => [],
        'kgLeadGroupValues' => [],
        'kgAssistantGroupValues' => [],
    ];
}

function rpc_person_full(string $personId): array
{
    return [
        'ok' => true,
        'main' => rpc_person_main($personId),
        'extra' => rpc_person_extra($personId),
        'family' => null,
    ];
}

function rpc_person_main(string $personId): array
{
    $row = rpc_fetch_person($personId);
    if (!$row) {
        return ['displayName' => '', 'details' => [], 'meta' => ['personId' => $personId], 'previewOnly' => true];
    }

    $lite = rpc_contact_row($row);
    $details = [
        rpc_detail('Kategorie', $lite['category']),
        rpc_detail('E-Mail', $lite['email'], 'email', $lite['email'] ? 'mailto:' . $lite['email'] : ''),
        rpc_detail('Telefon', $lite['phone'], 'phone', $lite['phone'] ? 'tel:' . $lite['phone'] : ''),
        rpc_detail('Mobile', $lite['mobile'], 'phone', $lite['mobile'] ? 'tel:' . $lite['mobile'] : ''),
        rpc_detail('Adresse', trim($lite['address'] . ', ' . $lite['postcode'] . ' ' . $lite['city']), 'address'),
        rpc_detail('Geburtsdatum', $lite['birthday']),
        rpc_detail('Picture', $lite['pictureUrl']),
    ];

    return [
        'displayName' => $lite['displayName'],
        'details' => array_values(array_filter($details, static fn(array $item): bool => trim((string) ($item['value'] ?? '')) !== '')),
        'meta' => [
            'personId' => $personId,
            'familyId' => $lite['familyId'],
            'pictureUrl' => $lite['pictureUrl'],
        ],
    ];
}

function rpc_person_extra(string $personId): array
{
    $row = rpc_fetch_person($personId);
    if (!$row) {
        return [];
    }

    $custom = fetch_all_prepared_legacy(
        'SELECT field_name, field_value FROM people_custom_fields WHERE person_id = ? ORDER BY field_name',
        [$personId]
    );

    $extra = [];
    foreach ($custom as $field) {
        $value = rpc_str($field['field_value'] ?? '');
        if ($value !== '') {
            $extra[] = rpc_detail(rpc_str($field['field_name'] ?? ''), $value);
        }
    }
    return $extra;
}

function rpc_fetch_person(string $personId): ?array
{
    $stmt = db()->prepare('SELECT * FROM people WHERE id = ?');
    $stmt->execute([$personId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function rpc_detail(string $label, string $value, string $type = '', string $href = ''): array
{
    return [
        'label' => $label,
        'value' => $value,
        'type' => $type,
        'href' => $href,
    ];
}

function rpc_extended_search(string $query): array
{
    $contacts = rpc_contacts_lite()['contacts'];
    $needle = strtolower(trim($query));
    if ($needle !== '') {
        $contacts = array_values(array_filter($contacts, static fn(array $row): bool => str_contains($row['searchText'] ?? '', $needle)));
    }
    return ['ok' => true, 'contacts' => array_slice($contacts, 0, 200)];
}

function rpc_family(string $familyId): array
{
    return ['ok' => true, 'familyId' => $familyId, 'adults' => [], 'kids' => [], 'others' => []];
}

function rpc_group(string $groupName): array
{
    return ['ok' => true, 'name' => $groupName, 'leaders' => [], 'assistants' => [], 'members' => []];
}

function rpc_calendar(string $startIso, string $endIso): array
{
    $start = $startIso !== '' ? $startIso : (new DateTimeImmutable('today'))->format('Y-m-d');
    $end = $endIso !== '' ? $endIso : (new DateTimeImmutable($start))->modify('+60 days')->format('Y-m-d');
    $stmt = db()->prepare(
        'SELECT * FROM calendar_events
         WHERE start_date <= :end_date AND COALESCE(end_date, start_date) >= :start_date
         ORDER BY start_date, start_time, title'
    );
    $stmt->execute([':start_date' => $start, ':end_date' => $end]);

    $events = [];
    foreach ($stmt as $row) {
        $events[] = rpc_calendar_event($row);
    }
    $categories = array_values(array_unique(array_filter(array_map(static fn(array $row): string => $row['Kategorie'] ?? '', $events))));
    sort($categories);

    return ['ok' => true, 'events' => $events, 'categories' => $categories];
}

function rpc_calendar_event(array $row): array
{
    $id = rpc_str($row['elvanto_id'] ?? '');
    $serviceId = str_starts_with($id, 'SERVICE-') ? substr($id, 8) : '';
    return [
        'id' => 'evt_' . rpc_str($row['id'] ?? ''),
        'Bezeichnung' => rpc_str($row['title'] ?? ''),
        'StartDatum' => rpc_ui_date($row['start_date'] ?? ''),
        'EndDatum' => rpc_ui_date($row['end_date'] ?? ($row['start_date'] ?? '')),
        'StartZeit' => rpc_time($row['start_time'] ?? ''),
        'EndZeit' => rpc_time($row['end_time'] ?? ''),
        'Kategorie' => rpc_str($row['category'] ?? ''),
        'Ort' => rpc_str($row['location'] ?? ''),
        'Details' => rpc_str($row['details'] ?? ''),
        'Ressourcen' => rpc_str($row['resources'] ?? ''),
        'displayColor' => rpc_str($row['category_color'] ?? '') ?: '#cbd5e1',
        '_elvantoId' => $id,
        '_elvantoUrl' => $serviceId !== '' ? 'https://app.elvanto.com/services/' . $serviceId : '',
        'hasServiceFlow' => $serviceId !== '',
        '_serviceLeadInMin' => 0,
    ];
}

function rpc_service_details(mixed $meta): array
{
    return array_merge(
        ['ok' => true],
        rpc_service_overview($meta),
        rpc_service_staff($meta),
        rpc_service_flow($meta)
    );
}

function rpc_service_overview(mixed $meta): array
{
    $serviceId = rpc_service_id_from_meta($meta);
    $service = rpc_fetch_service($serviceId);
    if (!$service) {
        return ['ok' => true, 'overview' => null];
    }

    return [
        'ok' => true,
        'overview' => [
            'serviceId' => $serviceId,
            'timeId' => '',
            'title' => rpc_str($service['title'] ?? ''),
            'category' => rpc_str($service['category'] ?? ''),
            'date' => rpc_ui_date(substr((string) ($service['service_start'] ?? ''), 0, 10)),
            'startTime' => rpc_time(substr((string) ($service['service_start'] ?? ''), 11, 8)),
            'endTime' => rpc_time(substr((string) ($service['service_end'] ?? ''), 11, 8)),
            'timeLabel' => '',
            'rehearsalTimes' => '',
            'otherTimes' => '',
            'roleSummary' => '',
        ],
    ];
}

function rpc_service_staff(mixed $meta): array
{
    $serviceId = rpc_service_id_from_meta($meta);
    if ($serviceId === '') {
        return ['ok' => true, 'staffGroups' => [], 'staffCount' => 0];
    }

    $rows = fetch_all_prepared_legacy(
        'SELECT * FROM service_volunteers WHERE service_id = ? ORDER BY team, role, display_name',
        [$serviceId]
    );
    $groups = [];
    foreach ($rows as $row) {
        $label = rpc_str($row['team'] ?? '') ?: 'Team';
        $groups[$label] ??= [];
        $groups[$label][] = [
            'groupLabel' => $label,
            'groupSortLabel' => $label,
            'subLabel' => '',
            'teamName' => $label,
            'departmentName' => $label,
            'subDepartmentName' => '',
            'positionName' => rpc_str($row['role'] ?? ''),
            'personId' => rpc_str($row['person_id'] ?? ''),
            'name' => rpc_str($row['display_name'] ?? ''),
            'status' => rpc_str($row['status'] ?? ''),
            'statusTone' => 'ok',
            'statusSortRank' => 1,
            'positionSort' => 9999,
            'email' => '',
            'phone' => '',
            'note' => '',
        ];
    }

    $staffGroups = [];
    foreach ($groups as $label => $items) {
        $staffGroups[] = ['label' => $label, 'items' => $items];
    }
    return ['ok' => true, 'staffGroups' => $staffGroups, 'staffCount' => count($rows)];
}

function rpc_service_flow(mixed $meta): array
{
    $serviceId = rpc_service_id_from_meta($meta);
    if ($serviceId === '') {
        return ['ok' => true, 'flow' => [], 'flowCount' => 0];
    }

    $rows = fetch_all_prepared_legacy(
        'SELECT * FROM service_plan_items WHERE service_id = ? ORDER BY item_order',
        [$serviceId]
    );
    $flow = array_map(static function (array $row): array {
        $title = rpc_str($row['title'] ?? '');
        $description = rpc_strip_tags(rpc_str($row['description'] ?? ''));
        $song = rpc_str($row['song_title'] ?? '');
        $duration = $row['duration_min'] ?? '';
        return [
            'title' => $title,
            'description' => $description,
            'song' => $song,
            'note' => '',
            'planDescription' => $description,
            'type' => '',
            'rawType' => '',
            'time' => rpc_time(substr((string) ($row['starts_at'] ?? ''), 11, 8)),
            'durationMin' => $duration !== null ? (string) $duration : '',
            'hasComputedTime' => false,
            'isHeader' => ((int) ($duration ?: 0)) === 0 && $song === '' && $description === '',
        ];
    }, $rows);

    return ['ok' => true, 'flow' => $flow, 'flowCount' => count($flow)];
}

function rpc_service_id_from_meta(mixed $meta): string
{
    $meta = is_array($meta) ? $meta : [];
    $elvantoId = rpc_str($meta['elvantoId'] ?? '');
    return str_starts_with($elvantoId, 'SERVICE-') ? substr($elvantoId, 8) : rpc_str($meta['serviceId'] ?? '');
}

function rpc_fetch_service(string $serviceId): ?array
{
    if ($serviceId === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM services WHERE service_id = ?');
    $stmt->execute([$serviceId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function rpc_songs_lite(): array
{
    $songs = [];
    try {
        $stmt = db()->query('SELECT song_id, title, artist, category, default_key_name, bpm FROM songs ORDER BY title');
        foreach ($stmt as $row) {
            $songs[] = [
                'id' => rpc_str($row['song_id'] ?? ''),
                'title' => rpc_str($row['title'] ?? ''),
                'artist' => rpc_str($row['artist'] ?? ''),
                'category' => rpc_str($row['category'] ?? ''),
                'key' => rpc_str($row['default_key_name'] ?? ''),
                'bpm' => rpc_str($row['bpm'] ?? ''),
            ];
        }
    } catch (Throwable) {
        $songs = [];
    }

    return ['ok' => true, 'songs' => $songs, 'dataVersion' => rpc_data_version()];
}

function rpc_contact_filters(): array
{
    $rows = db()->query("SELECT DISTINCT category_name AS label FROM people WHERE category_name IS NOT NULL AND category_name <> '' ORDER BY category_name")->fetchAll();
    return array_map(static fn(array $row): array => ['label' => $row['label'], 'value' => $row['label']], $rows);
}

function rpc_dashboard(): array
{
    return [
        'peopleCount' => (int) db()->query('SELECT COUNT(*) AS c FROM people')->fetch()['c'],
        'eventsCount' => (int) db()->query('SELECT COUNT(*) AS c FROM calendar_events')->fetch()['c'],
        'serviceCount' => (int) db()->query('SELECT COUNT(*) AS c FROM services')->fetch()['c'],
    ];
}

function rpc_import_runs(): array
{
    return db()->query('SELECT import_type, status, started_at, finished_at, item_count, message FROM import_runs ORDER BY started_at DESC LIMIT 10')->fetchAll();
}

function fetch_all_prepared_legacy(string $sql, array $params): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function rpc_str(mixed $value): string
{
    return trim((string) ($value ?? ''));
}

function rpc_time(mixed $value): string
{
    $raw = rpc_str($value);
    return $raw !== '' ? substr($raw, 0, 5) : '';
}

function rpc_ui_date(mixed $value): string
{
    $raw = rpc_str($value);
    if ($raw === '' || $raw === '0000-00-00') {
        return '';
    }
    try {
        return (new DateTimeImmutable($raw))->format('d.m.Y');
    } catch (Throwable) {
        return $raw;
    }
}

function rpc_age(string $birthday): ?int
{
    if ($birthday === '') {
        return null;
    }
    try {
        return (int) (new DateTimeImmutable($birthday))->diff(new DateTimeImmutable('today'))->y;
    } catch (Throwable) {
        return null;
    }
}

function rpc_strip_tags(string $html): string
{
    return trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');
}
