<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

try {
    $user = require_user();
    $body = read_json_body();
    $fn = trim((string) ($body['fn'] ?? ''));
    $args = is_array($body['args'] ?? null) ? $body['args'] : [];

    json_response(legacy_dispatch($fn, $args, $user));
} catch (Throwable $e) {
    json_error('Legacy API Fehler.', 500, [
        'detail' => env('APP_DEBUG', '0') === '1' ? $e->getMessage() : null,
    ]);
}

function legacy_dispatch(string $fn, array $args, array $user): array
{
    return match ($fn) {
        'app_ping' => legacy_ping(),
        'app_bootstrap' => legacy_bootstrap($user),
        'app_getUserAccessLight' => ['ok' => true, 'user' => $user, 'permissions' => legacy_permissions()],
        'app_loadContactsLite' => legacy_contacts_lite(),
        'personenUi_getMainDetails' => legacy_person_main((string) ($args[0] ?? '')),
        'personenUi_getFullDetails' => legacy_person_full((string) ($args[0] ?? '')),
        'personenUi_getExtraDetails' => legacy_person_extra((string) ($args[0] ?? '')),
        'personenUi_extendedSearch' => legacy_extended_search((string) ($args[0] ?? '')),
        'personenUi_getFamily' => legacy_family((string) ($args[0] ?? '')),
        'personenUi_getGroupByName' => legacy_group((string) ($args[0] ?? '')),
        'getCalendarEventsRange' => legacy_calendar((string) ($args[0] ?? ''), (string) ($args[1] ?? '')),
        'kalenderUi_getServiceOverview' => legacy_service_overview($args[0] ?? []),
        'kalenderUi_getServiceStaff' => legacy_service_staff($args[0] ?? []),
        'kalenderUi_getServiceFlow' => legacy_service_flow($args[0] ?? []),
        'kalenderUi_getServiceDetails', 'kalenderUi_refreshServiceDetails' => legacy_service_details($args[0] ?? []),
        'kalenderUi_getPersonServiceAssignments' => ['ok' => true, 'serviceIds' => [], 'count' => 0],
        'app_loadSongsLite' => legacy_songs_lite(),
        'app_loadFilterDefs' => ['ok' => true, 'filters' => legacy_contact_filters(), 'dataVersion' => legacy_data_version()],
        'app_loadDashboardStats' => ['ok' => true, 'dashboard' => legacy_dashboard(), 'dataVersion' => legacy_data_version()],
        'tools_getSyncStatus' => ['ok' => true, 'status' => 'ok', 'cache' => [], 'latestRuns' => legacy_import_runs()],
        'tools_rebuildServerCaches' => ['ok' => true, 'dataVersion' => legacy_data_version()],
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

function legacy_ping(): array
{
    return [
        'ok' => true,
        'ts' => date('c'),
        'dataVersion' => legacy_data_version(),
    ];
}

function legacy_bootstrap(array $user): array
{
    return [
        'ok' => true,
        'ts' => date('c'),
        'dataVersion' => legacy_data_version(),
        'user' => [
            'email' => $user['email'] ?? '',
            'role' => $user['role'] ?? 'member',
            'permissions' => legacy_permissions(),
        ],
        'cache' => [],
        'filters' => legacy_contact_filters(),
        'dashboard' => legacy_dashboard(),
    ];
}

function legacy_permissions(): array
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

function legacy_data_version(): string
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

function legacy_contacts_lite(): array
{
    $stmt = db()->query(
        "SELECT id, firstname, preferred_name, lastname, display_name, email, phone, mobile,
                category_name, family_id, gender, birthday, home_address, home_city, home_postcode, picture_url
         FROM people
         ORDER BY lastname, firstname"
    );

    $contacts = [];
    foreach ($stmt as $row) {
        $contacts[] = legacy_contact_row($row);
    }

    return [
        'ok' => true,
        'ts' => date('c'),
        'dataVersion' => legacy_data_version(),
        'contacts' => $contacts,
    ];
}

function legacy_contact_row(array $row): array
{
    $first = legacy_str($row['firstname'] ?? '');
    $preferred = legacy_str($row['preferred_name'] ?? '');
    $last = legacy_str($row['lastname'] ?? '');
    $display = legacy_str($row['display_name'] ?? '') ?: trim(($preferred ?: $first) . ' ' . $last);
    $cityLine = trim(legacy_str($row['home_postcode'] ?? '') . ' ' . legacy_str($row['home_city'] ?? ''));
    $category = legacy_str($row['category_name'] ?? '');
    $birthday = legacy_str($row['birthday'] ?? '');
    $age = legacy_age($birthday);

    return [
        'id' => legacy_str($row['id'] ?? ''),
        'displayName' => $display,
        'listDisplayName' => $display,
        'firstName' => $first,
        'preferredName' => $preferred,
        'lastName' => $last,
        'email' => legacy_str($row['email'] ?? ''),
        'phone' => legacy_str($row['phone'] ?? ''),
        'mobile' => legacy_str($row['mobile'] ?? ''),
        'category' => $category,
        'familyId' => legacy_str($row['family_id'] ?? ''),
        'gender' => legacy_str($row['gender'] ?? ''),
        'birthday' => $birthday,
        'age' => $age,
        'isChild' => $age !== null && $age < 16,
        'isFamilyMain' => true,
        'pictureUrl' => legacy_str($row['picture_url'] ?? ''),
        'address' => legacy_str($row['home_address'] ?? ''),
        'city' => legacy_str($row['home_city'] ?? ''),
        'postcode' => legacy_str($row['home_postcode'] ?? ''),
        'sub' => implode(' - ', array_values(array_filter([$category, $cityLine]))),
        'searchText' => strtolower($display . ' ' . $first . ' ' . $preferred . ' ' . $last . ' ' . $category . ' ' . $cityLine),
        'kgGroupValues' => [],
        'kgLeadGroupValues' => [],
        'kgAssistantGroupValues' => [],
    ];
}

function legacy_person_full(string $personId): array
{
    return [
        'ok' => true,
        'main' => legacy_person_main($personId),
        'extra' => legacy_person_extra($personId),
        'family' => null,
    ];
}

function legacy_person_main(string $personId): array
{
    $row = legacy_fetch_person($personId);
    if (!$row) {
        return ['displayName' => '', 'details' => [], 'meta' => ['personId' => $personId], 'previewOnly' => true];
    }

    $lite = legacy_contact_row($row);
    $details = [
        legacy_detail('Kategorie', $lite['category']),
        legacy_detail('E-Mail', $lite['email'], 'email', $lite['email'] ? 'mailto:' . $lite['email'] : ''),
        legacy_detail('Telefon', $lite['phone'], 'phone', $lite['phone'] ? 'tel:' . $lite['phone'] : ''),
        legacy_detail('Mobile', $lite['mobile'], 'phone', $lite['mobile'] ? 'tel:' . $lite['mobile'] : ''),
        legacy_detail('Adresse', trim($lite['address'] . ', ' . $lite['postcode'] . ' ' . $lite['city']), 'address'),
        legacy_detail('Geburtsdatum', $lite['birthday']),
        legacy_detail('Picture', $lite['pictureUrl']),
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

function legacy_person_extra(string $personId): array
{
    $row = legacy_fetch_person($personId);
    if (!$row) {
        return [];
    }

    $custom = fetch_all_prepared_legacy(
        'SELECT field_name, field_value FROM people_custom_fields WHERE person_id = ? ORDER BY field_name',
        [$personId]
    );

    $extra = [];
    foreach ($custom as $field) {
        $value = legacy_str($field['field_value'] ?? '');
        if ($value !== '') {
            $extra[] = legacy_detail(legacy_str($field['field_name'] ?? ''), $value);
        }
    }
    return $extra;
}

function legacy_fetch_person(string $personId): ?array
{
    $stmt = db()->prepare('SELECT * FROM people WHERE id = ?');
    $stmt->execute([$personId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function legacy_detail(string $label, string $value, string $type = '', string $href = ''): array
{
    return [
        'label' => $label,
        'value' => $value,
        'type' => $type,
        'href' => $href,
    ];
}

function legacy_extended_search(string $query): array
{
    $contacts = legacy_contacts_lite()['contacts'];
    $needle = strtolower(trim($query));
    if ($needle !== '') {
        $contacts = array_values(array_filter($contacts, static fn(array $row): bool => str_contains($row['searchText'] ?? '', $needle)));
    }
    return ['ok' => true, 'contacts' => array_slice($contacts, 0, 200)];
}

function legacy_family(string $familyId): array
{
    return ['ok' => true, 'familyId' => $familyId, 'adults' => [], 'kids' => [], 'others' => []];
}

function legacy_group(string $groupName): array
{
    return ['ok' => true, 'name' => $groupName, 'leaders' => [], 'assistants' => [], 'members' => []];
}

function legacy_calendar(string $startIso, string $endIso): array
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
        $events[] = legacy_calendar_event($row);
    }
    $categories = array_values(array_unique(array_filter(array_map(static fn(array $row): string => $row['Kategorie'] ?? '', $events))));
    sort($categories);

    return ['ok' => true, 'events' => $events, 'categories' => $categories];
}

function legacy_calendar_event(array $row): array
{
    $id = legacy_str($row['elvanto_id'] ?? '');
    $serviceId = str_starts_with($id, 'SERVICE-') ? substr($id, 8) : '';
    return [
        'id' => 'evt_' . legacy_str($row['id'] ?? ''),
        'Bezeichnung' => legacy_str($row['title'] ?? ''),
        'StartDatum' => legacy_ui_date($row['start_date'] ?? ''),
        'EndDatum' => legacy_ui_date($row['end_date'] ?? ($row['start_date'] ?? '')),
        'StartZeit' => legacy_time($row['start_time'] ?? ''),
        'EndZeit' => legacy_time($row['end_time'] ?? ''),
        'Kategorie' => legacy_str($row['category'] ?? ''),
        'Ort' => legacy_str($row['location'] ?? ''),
        'Details' => legacy_str($row['details'] ?? ''),
        'Ressourcen' => legacy_str($row['resources'] ?? ''),
        'displayColor' => legacy_str($row['category_color'] ?? '') ?: '#cbd5e1',
        '_elvantoId' => $id,
        '_elvantoUrl' => $serviceId !== '' ? 'https://app.elvanto.com/services/' . $serviceId : '',
        'hasServiceFlow' => $serviceId !== '',
        '_serviceLeadInMin' => 0,
    ];
}

function legacy_service_details(mixed $meta): array
{
    return array_merge(
        ['ok' => true],
        legacy_service_overview($meta),
        legacy_service_staff($meta),
        legacy_service_flow($meta)
    );
}

function legacy_service_overview(mixed $meta): array
{
    $serviceId = legacy_service_id_from_meta($meta);
    $service = legacy_fetch_service($serviceId);
    if (!$service) {
        return ['ok' => true, 'overview' => null];
    }

    return [
        'ok' => true,
        'overview' => [
            'serviceId' => $serviceId,
            'timeId' => '',
            'title' => legacy_str($service['title'] ?? ''),
            'category' => legacy_str($service['category'] ?? ''),
            'date' => legacy_ui_date(substr((string) ($service['service_start'] ?? ''), 0, 10)),
            'startTime' => legacy_time(substr((string) ($service['service_start'] ?? ''), 11, 8)),
            'endTime' => legacy_time(substr((string) ($service['service_end'] ?? ''), 11, 8)),
            'timeLabel' => '',
            'rehearsalTimes' => '',
            'otherTimes' => '',
            'roleSummary' => '',
        ],
    ];
}

function legacy_service_staff(mixed $meta): array
{
    $serviceId = legacy_service_id_from_meta($meta);
    if ($serviceId === '') {
        return ['ok' => true, 'staffGroups' => [], 'staffCount' => 0];
    }

    $rows = fetch_all_prepared_legacy(
        'SELECT * FROM service_volunteers WHERE service_id = ? ORDER BY team, role, display_name',
        [$serviceId]
    );
    $groups = [];
    foreach ($rows as $row) {
        $label = legacy_str($row['team'] ?? '') ?: 'Team';
        $groups[$label] ??= [];
        $groups[$label][] = [
            'groupLabel' => $label,
            'groupSortLabel' => $label,
            'subLabel' => '',
            'teamName' => $label,
            'departmentName' => $label,
            'subDepartmentName' => '',
            'positionName' => legacy_str($row['role'] ?? ''),
            'personId' => legacy_str($row['person_id'] ?? ''),
            'name' => legacy_str($row['display_name'] ?? ''),
            'status' => legacy_str($row['status'] ?? ''),
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

function legacy_service_flow(mixed $meta): array
{
    $serviceId = legacy_service_id_from_meta($meta);
    if ($serviceId === '') {
        return ['ok' => true, 'flow' => [], 'flowCount' => 0];
    }

    $rows = fetch_all_prepared_legacy(
        'SELECT * FROM service_plan_items WHERE service_id = ? ORDER BY item_order',
        [$serviceId]
    );
    $flow = array_map(static function (array $row): array {
        $title = legacy_str($row['title'] ?? '');
        $description = legacy_strip_tags(legacy_str($row['description'] ?? ''));
        $song = legacy_str($row['song_title'] ?? '');
        $duration = $row['duration_min'] ?? '';
        return [
            'title' => $title,
            'description' => $description,
            'song' => $song,
            'note' => '',
            'planDescription' => $description,
            'type' => '',
            'rawType' => '',
            'time' => legacy_time(substr((string) ($row['starts_at'] ?? ''), 11, 8)),
            'durationMin' => $duration !== null ? (string) $duration : '',
            'hasComputedTime' => false,
            'isHeader' => ((int) ($duration ?: 0)) === 0 && $song === '' && $description === '',
        ];
    }, $rows);

    return ['ok' => true, 'flow' => $flow, 'flowCount' => count($flow)];
}

function legacy_service_id_from_meta(mixed $meta): string
{
    $meta = is_array($meta) ? $meta : [];
    $elvantoId = legacy_str($meta['elvantoId'] ?? '');
    return str_starts_with($elvantoId, 'SERVICE-') ? substr($elvantoId, 8) : legacy_str($meta['serviceId'] ?? '');
}

function legacy_fetch_service(string $serviceId): ?array
{
    if ($serviceId === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM services WHERE service_id = ?');
    $stmt->execute([$serviceId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function legacy_songs_lite(): array
{
    $songs = [];
    try {
        $stmt = db()->query('SELECT song_id, title, artist, category, default_key_name, bpm FROM songs ORDER BY title');
        foreach ($stmt as $row) {
            $songs[] = [
                'id' => legacy_str($row['song_id'] ?? ''),
                'title' => legacy_str($row['title'] ?? ''),
                'artist' => legacy_str($row['artist'] ?? ''),
                'category' => legacy_str($row['category'] ?? ''),
                'key' => legacy_str($row['default_key_name'] ?? ''),
                'bpm' => legacy_str($row['bpm'] ?? ''),
            ];
        }
    } catch (Throwable) {
        $songs = [];
    }

    return ['ok' => true, 'songs' => $songs, 'dataVersion' => legacy_data_version()];
}

function legacy_contact_filters(): array
{
    $rows = db()->query("SELECT DISTINCT category_name AS label FROM people WHERE category_name IS NOT NULL AND category_name <> '' ORDER BY category_name")->fetchAll();
    return array_map(static fn(array $row): array => ['label' => $row['label'], 'value' => $row['label']], $rows);
}

function legacy_dashboard(): array
{
    return [
        'peopleCount' => (int) db()->query('SELECT COUNT(*) AS c FROM people')->fetch()['c'],
        'eventsCount' => (int) db()->query('SELECT COUNT(*) AS c FROM calendar_events')->fetch()['c'],
        'serviceCount' => (int) db()->query('SELECT COUNT(*) AS c FROM services')->fetch()['c'],
    ];
}

function legacy_import_runs(): array
{
    return db()->query('SELECT import_type, status, started_at, finished_at, item_count, message FROM import_runs ORDER BY started_at DESC LIMIT 10')->fetchAll();
}

function fetch_all_prepared_legacy(string $sql, array $params): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function legacy_str(mixed $value): string
{
    return trim((string) ($value ?? ''));
}

function legacy_time(mixed $value): string
{
    $raw = legacy_str($value);
    return $raw !== '' ? substr($raw, 0, 5) : '';
}

function legacy_ui_date(mixed $value): string
{
    $raw = legacy_str($value);
    if ($raw === '' || $raw === '0000-00-00') {
        return '';
    }
    try {
        return (new DateTimeImmutable($raw))->format('d.m.Y');
    } catch (Throwable) {
        return $raw;
    }
}

function legacy_age(string $birthday): ?int
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

function legacy_strip_tags(string $html): string
{
    return trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');
}
