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
        'app_getUserAccessLight' => ['ok' => true, 'user' => $user, 'permissions' => rpc_permissions($user)],
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
            'permissions' => rpc_permissions($user),
        ],
        'cache' => [],
        'filters' => rpc_contact_filters(),
        'dashboard' => rpc_dashboard(),
    ];
}

function rpc_permissions(array $user = []): array
{
    $isAdmin = strtolower((string) ($user['role'] ?? '')) === 'admin';

    return [
        'tabs' => [
            'contacts' => true,
            'calendar' => true,
            'dashboard' => true,
            'tools' => true,
        ],
        'exports' => [
            'contactsCsv' => $isAdmin,
            'contactsPrint' => $isAdmin,
            'calendarCsv' => $isAdmin,
            'calendarPrint' => $isAdmin,
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
        "SELECT id, date_added, firstname, preferred_name, lastname, display_name, email, phone, mobile,
                category_name, family_id, gender, birthday, home_address, home_city, home_postcode, departments, picture_url
         FROM people
         WHERE status IS NULL OR status = '' OR LOWER(status) = 'active'
         ORDER BY lastname, firstname"
    );

    $customValues = rpc_people_custom_values();
    $groupValues = rpc_people_group_values();
    $familyValues = rpc_people_family_values();

    $contacts = [];
    foreach ($stmt as $row) {
        $personId = rpc_str($row['id'] ?? '');
        $contacts[] = rpc_contact_row(
            $row,
            $customValues[$personId] ?? [],
            $groupValues[$personId] ?? ['groups' => [], 'leads' => [], 'assists' => []],
            $familyValues[$personId] ?? []
        );
    }

    return [
        'ok' => true,
        'ts' => date('c'),
        'dataVersion' => rpc_data_version(),
        'contacts' => $contacts,
    ];
}

function rpc_contact_row(array $row, array $custom = [], array $groups = [], array $family = []): array
{
    $first = rpc_str($row['firstname'] ?? '');
    $preferred = rpc_str($row['preferred_name'] ?? '');
    $last = rpc_str($row['lastname'] ?? '');
    $display = rpc_str($row['display_name'] ?? '') ?: trim(($preferred ?: $first) . ' ' . $last);
    $listDisplay = trim($last . ' ' . ($preferred ?: $first)) ?: $display;
    $cityLine = trim(rpc_str($row['home_postcode'] ?? '') . ' ' . rpc_str($row['home_city'] ?? ''));
    $category = rpc_str($row['category_name'] ?? '');
    $birthday = rpc_str($row['birthday'] ?? '');
    $age = rpc_age($birthday);
    $departmentsValues = rpc_split_multi_value(rpc_str($row['departments'] ?? ''));
    $leaderships = rpc_split_multi_value($custom['LEITERSCHAFT'] ?? '');
    $kgGroupValues = $groups['groups'] ?? [];
    $kgLeadGroupValues = $groups['leads'] ?? [];
    $kgAssistantGroupValues = $groups['assists'] ?? [];
    $leadsKg = count($kgLeadGroupValues) > 0;
    $assistsKg = count($kgAssistantGroupValues) > 0;
    if ($leadsKg && !in_array('Kleingruppen-Leiter/-in', $leaderships, true)) {
        $leaderships[] = 'Kleingruppen-Leiter/-in';
    }
    if ($assistsKg && !in_array('Stv. Kleingruppen-Leiter/-in', $leaderships, true)) {
        $leaderships[] = 'Stv. Kleingruppen-Leiter/-in';
    }
    $birthdayParts = rpc_birthday_parts($birthday);
    $dateAdded = rpc_str($row['date_added'] ?? '');

    return [
        'id' => rpc_str($row['id'] ?? ''),
        'displayName' => $display,
        'listDisplayName' => $listDisplay,
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
        'ageBucket' => rpc_age_bucket($age),
        'isChild' => ($family['relationship'] ?? '') === 'Kind' || ($age !== null && $age < 16),
        'isFamilyMain' => (bool) ($family['isFamilyMain'] ?? false),
        'isSingle' => (bool) ($family['isSingle'] ?? false),
        'householdTypeKey' => rpc_str($family['householdTypeKey'] ?? ''),
        'pictureUrl' => rpc_str($row['picture_url'] ?? ''),
        'address' => rpc_str($row['home_address'] ?? ''),
        'city' => rpc_str($row['home_city'] ?? ''),
        'postcode' => rpc_str($row['home_postcode'] ?? ''),
        'sub' => implode(' - ', array_values(array_filter([$category, $cityLine]))),
        'searchName' => rpc_search_text($first . ' ' . $preferred . ' ' . $last),
        'searchText' => rpc_search_text(implode(' ', array_merge([$display, $first, $preferred, $last, $category, $cityLine], $kgGroupValues, $departmentsValues))),
        'searchMeta' => rpc_search_text(implode(' ', array_merge([$first, $preferred, $last, $category, $cityLine], $kgGroupValues, $departmentsValues))),
        'hasKg' => count($kgGroupValues) > 0,
        'leadsKg' => $leadsKg,
        'hasMitarbeit' => count($departmentsValues) > 0,
        'departmentsValues' => $departmentsValues,
        'kgGroupValues' => $kgGroupValues,
        'kgLeadGroupValues' => $kgLeadGroupValues,
        'kgAssistantGroupValues' => $kgAssistantGroupValues,
        'leaderships' => $leaderships,
        'nextStepValues' => rpc_split_multi_value($custom['KURSE / TAUFE'] ?? ''),
        'kidsChurchValues' => rpc_split_multi_value($custom['KIDS & PROMISELAND'] ?? ''),
        'youthYpgValues' => rpc_split_multi_value($custom['JUNGE ERWACHSENE'] ?? ''),
        'new12' => rpc_date_within_months($dateAdded, 12),
        'new6' => rpc_date_within_months($dateAdded, 6),
        'new3' => rpc_date_within_months($dateAdded, 3),
        'new14' => rpc_date_within_days($dateAdded, 14),
        'birthdayDay' => $birthdayParts['day'] ?? '',
        'birthdayMonth' => $birthdayParts['month'] ?? '',
        'birthdayToday' => rpc_birthday_today($birthdayParts),
        'birthdayWeek' => rpc_birthday_week($birthdayParts),
        'birthdayMonthFlag' => rpc_birthday_month($birthdayParts),
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
    $groupSections = rpc_person_group_sections($personId);
    foreach ($groupSections as $section) {
        $extra[] = $section;
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
    $rows = fetch_all_prepared_legacy(
        'SELECT fm.person_id, fm.relationship, fm.sort_order, p.firstname, p.preferred_name, p.lastname, p.display_name, p.birthday
         FROM family_members fm
         LEFT JOIN people p ON p.id = fm.person_id
         WHERE fm.family_id = ?
         ORDER BY fm.sort_order, p.birthday, p.lastname, p.firstname',
        [$familyId]
    );

    $family = ['ok' => true, 'familyId' => $familyId, 'adults' => [], 'kids' => [], 'others' => []];
    foreach ($rows as $row) {
        $name = rpc_str($row['display_name'] ?? '') ?: trim(rpc_str($row['preferred_name'] ?? $row['firstname'] ?? '') . ' ' . rpc_str($row['lastname'] ?? ''));
        $relationship = rpc_str($row['relationship'] ?? '');
        $person = [
            'personId' => rpc_str($row['person_id'] ?? ''),
            'name' => $name,
            'relationship' => $relationship,
        ];
        $age = rpc_age(rpc_str($row['birthday'] ?? ''));
        if ($relationship === 'Kind' || ($age !== null && $age < 16)) {
            $family['kids'][] = $person;
        } elseif ($relationship === '' || in_array($relationship, ['Hauptkontakt', 'EhepartnerIn', 'PartnerIn'], true) || ($age !== null && $age >= 16)) {
            $family['adults'][] = $person;
        } else {
            $family['others'][] = $person;
        }
    }

    return $family;
}

function rpc_group(string $groupName): array
{
    $group = rpc_fetch_group_by_name($groupName);
    if (!$group) {
        return [
            'groupName' => $groupName,
            'leaderPersons' => [],
            'assistantPersons' => [],
            'memberPersons' => [],
        ];
    }

    return rpc_group_payload($group);
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
    $serviceId = rpc_starts_with($id, 'SERVICE-') ? substr($id, 8) : '';
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
    return rpc_starts_with($elvantoId, 'SERVICE-') ? substr($elvantoId, 8) : rpc_str($meta['serviceId'] ?? '');
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
    $filters = [];
    $seen = [];

    foreach (rpc_people_custom_values() as $custom) {
        foreach (rpc_split_multi_value($custom['LEITERSCHAFT'] ?? '') as $value) {
            $seen[$value] = true;
        }
    }

    $hasKg = (int) db()->query('SELECT COUNT(*) AS c FROM group_members')->fetch()['c'] > 0;
    if ($hasKg) {
        $seen['Kleingruppen-Leiter/-in'] = true;
        $seen['Stv. Kleingruppen-Leiter/-in'] = true;
    }

    $order = [
        'Kleingruppen-Leiter/-in' => 100,
        'Stv. Kleingruppen-Leiter/-in' => 101,
    ];
    $keys = array_keys($seen);
    usort($keys, static function (string $a, string $b) use ($order): int {
        $orderA = $order[$a] ?? 1000;
        $orderB = $order[$b] ?? 1000;
        return $orderA <=> $orderB ?: strcasecmp($a, $b);
    });

    foreach ($keys as $key) {
        if ($key !== '') {
            $filters[] = ['key' => $key, 'label' => $key];
        }
    }

    return $filters;
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

function rpc_people_custom_values(): array
{
    static $values = null;
    if ($values !== null) {
        return $values;
    }

    $values = [];
    $rows = db()->query('SELECT person_id, field_name, field_value FROM people_custom_fields')->fetchAll();
    foreach ($rows as $row) {
        $personId = rpc_str($row['person_id'] ?? '');
        $fieldName = strtoupper(rpc_str($row['field_name'] ?? ''));
        if ($personId !== '' && $fieldName !== '') {
            $values[$personId][$fieldName] = rpc_str($row['field_value'] ?? '');
        }
    }
    return $values;
}

function rpc_people_group_values(): array
{
    static $values = null;
    if ($values !== null) {
        return $values;
    }

    $values = [];
    $rows = db()->query(
        'SELECT gm.person_id, gm.role, gm.position, g.name
         FROM group_members gm
         LEFT JOIN groups g ON g.id = gm.group_id'
    )->fetchAll();

    foreach ($rows as $row) {
        $personId = rpc_str($row['person_id'] ?? '');
        $groupName = rpc_str($row['name'] ?? '');
        if ($personId === '' || $groupName === '') {
            continue;
        }

        $values[$personId] ??= ['groups' => [], 'leads' => [], 'assists' => []];
        rpc_add_unique($values[$personId]['groups'], $groupName);

        $roleText = rpc_lower(rpc_str($row['role'] ?? '') . ' ' . rpc_str($row['position'] ?? ''));
        if (str_contains($roleText, 'stv') || str_contains($roleText, 'assistant') || str_contains($roleText, 'assistent')) {
            rpc_add_unique($values[$personId]['assists'], $groupName);
        } elseif (str_contains($roleText, 'leiter') || str_contains($roleText, 'leader')) {
            rpc_add_unique($values[$personId]['leads'], $groupName);
        }
    }

    return $values;
}

function rpc_people_family_values(): array
{
    static $values = null;
    if ($values !== null) {
        return $values;
    }

    $values = [];
    $membersByFamily = [];
    $rows = db()->query(
        'SELECT fm.family_id, fm.person_id, fm.relationship, fm.sort_order, p.birthday
         FROM family_members fm
         LEFT JOIN people p ON p.id = fm.person_id
         ORDER BY fm.family_id, fm.sort_order, p.birthday'
    )->fetchAll();

    foreach ($rows as $row) {
        $familyId = rpc_str($row['family_id'] ?? '');
        if ($familyId !== '') {
            $membersByFamily[$familyId][] = $row;
        }
    }

    foreach ($membersByFamily as $familyId => $members) {
        $adultMembers = array_values(array_filter($members, static function (array $member): bool {
            $relationship = rpc_str($member['relationship'] ?? '');
            $age = rpc_age(rpc_str($member['birthday'] ?? ''));
            return $relationship !== 'Kind' && ($age === null || $age >= 16);
        }));
        $main = $adultMembers[0] ?? ($members[0] ?? null);
        $mainPersonId = $main ? rpc_str($main['person_id'] ?? '') : '';
        $isSingleFamily = rpc_starts_with($familyId, 'Einzel_') || count($members) === 1;
        $hasKids = count($members) > count($adultMembers);
        $adultCount = count($adultMembers);
        $householdTypeKey = $isSingleFamily ? 'single' : ($hasKids ? 'family_with_kids' : ($adultCount > 1 ? 'couple' : 'family'));

        foreach ($members as $member) {
            $personId = rpc_str($member['person_id'] ?? '');
            if ($personId === '') {
                continue;
            }
            $values[$personId] = [
                'relationship' => rpc_str($member['relationship'] ?? ''),
                'isFamilyMain' => $personId === $mainPersonId && !$isSingleFamily,
                'isSingle' => $personId === $mainPersonId && $isSingleFamily,
                'householdTypeKey' => $householdTypeKey,
            ];
        }
    }

    return $values;
}

function rpc_person_group_sections(string $personId): array
{
    $rows = fetch_all_prepared_legacy(
        'SELECT DISTINCT g.*
         FROM groups g
         INNER JOIN group_members gm ON gm.group_id = g.id
         WHERE gm.person_id = ?
         ORDER BY g.name',
        [$personId]
    );
    if (!$rows) {
        return [];
    }

    return [[
        'type' => 'group-section',
        'label' => 'Kleingruppe',
        'items' => array_map(static fn(array $group): array => rpc_group_payload($group), $rows),
    ]];
}

function rpc_fetch_group_by_name(string $groupName): ?array
{
    $stmt = db()->prepare('SELECT * FROM groups WHERE LOWER(name) = LOWER(?) LIMIT 1');
    $stmt->execute([$groupName]);
    $row = $stmt->fetch();
    if (is_array($row)) {
        return $row;
    }

    $stmt = db()->prepare('SELECT * FROM groups WHERE name LIKE ? ORDER BY name LIMIT 1');
    $stmt->execute(['%' . $groupName . '%']);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function rpc_group_payload(array $group): array
{
    $groupId = rpc_str($group['id'] ?? '');
    $members = fetch_all_prepared_legacy(
        'SELECT gm.person_id, gm.display_name, gm.firstname, gm.lastname, gm.role, gm.position, p.display_name AS person_display_name
         FROM group_members gm
         LEFT JOIN people p ON p.id = gm.person_id
         WHERE gm.group_id = ?
         ORDER BY gm.role, gm.position, gm.lastname, gm.firstname',
        [$groupId]
    );

    $payload = [
        'groupId' => $groupId,
        'groupName' => rpc_str($group['name'] ?? ''),
        'description' => rpc_strip_tags(rpc_str($group['description'] ?? '')),
        'descriptionHtml' => rpc_str($group['description'] ?? ''),
        'meetingPoint' => trim(implode(', ', array_filter([
            rpc_str($group['meeting_address'] ?? ''),
            trim(rpc_str($group['meeting_postcode'] ?? '') . ' ' . rpc_str($group['meeting_city'] ?? '')),
        ]))),
        'meetingDay' => rpc_str($group['meeting_day'] ?? ''),
        'meetingFrequency' => rpc_str($group['meeting_frequency'] ?? ''),
        'meetingTime' => rpc_str($group['meeting_time'] ?? ''),
        'mapHref' => '',
        'elvantoUrl' => $groupId !== '' ? 'https://app.elvanto.com/groups/' . rawurlencode($groupId) : '',
        'leaderPersons' => [],
        'assistantPersons' => [],
        'memberPersons' => [],
    ];

    foreach ($members as $member) {
        $person = [
            'personId' => rpc_str($member['person_id'] ?? ''),
            'name' => rpc_str($member['person_display_name'] ?? '') ?: (rpc_str($member['display_name'] ?? '') ?: trim(rpc_str($member['firstname'] ?? '') . ' ' . rpc_str($member['lastname'] ?? ''))),
            'position' => trim(implode(' ', array_filter([rpc_str($member['role'] ?? ''), rpc_str($member['position'] ?? '')]))),
            'roleBadge' => '',
            'roleTone' => '',
        ];
        $roleText = rpc_lower($person['position']);
        if (str_contains($roleText, 'stv') || str_contains($roleText, 'assistant') || str_contains($roleText, 'assistent')) {
            $person['roleBadge'] = 'Stv. Leiter';
            $person['roleTone'] = 'assistant';
            $payload['assistantPersons'][] = $person;
        } elseif (str_contains($roleText, 'leiter') || str_contains($roleText, 'leader')) {
            $payload['leaderPersons'][] = $person;
        } else {
            $payload['memberPersons'][] = $person;
        }
    }

    return $payload;
}

function rpc_add_unique(array &$items, string $value): void
{
    if ($value !== '' && !in_array($value, $items, true)) {
        $items[] = $value;
    }
}

function rpc_str(mixed $value): string
{
    return trim((string) ($value ?? ''));
}

function rpc_split_multi_value(string $value): array
{
    if (trim($value) === '') {
        return [];
    }

    $parts = preg_split('/\s*(?:\||,|;|\r?\n)\s*/', $value) ?: [];
    $out = [];
    foreach ($parts as $part) {
        $item = trim($part);
        if ($item !== '' && !in_array($item, $out, true)) {
            $out[] = $item;
        }
    }
    return $out;
}

function rpc_search_text(string $value): string
{
    return rpc_lower(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
}

function rpc_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function rpc_starts_with(mixed $haystack, string $needle): bool
{
    return str_starts_with((string) $haystack, $needle);
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

function rpc_age_bucket(?int $age): string
{
    if ($age === null) {
        return '';
    }
    if ($age <= 20) {
        return '0_20';
    }
    if ($age <= 40) {
        return '21_40';
    }
    if ($age <= 60) {
        return '41_60';
    }
    return '61_100';
}

function rpc_date_within_months(string $date, int $months): bool
{
    if ($date === '') {
        return false;
    }
    try {
        $day = new DateTimeImmutable($date);
        return $day >= (new DateTimeImmutable('today'))->modify("-{$months} months");
    } catch (Throwable) {
        return false;
    }
}

function rpc_date_within_days(string $date, int $days): bool
{
    if ($date === '') {
        return false;
    }
    try {
        $day = new DateTimeImmutable($date);
        return $day >= (new DateTimeImmutable('today'))->modify("-{$days} days");
    } catch (Throwable) {
        return false;
    }
}

function rpc_birthday_parts(string $birthday): array
{
    if ($birthday === '') {
        return [];
    }
    try {
        $date = new DateTimeImmutable($birthday);
        return ['day' => (int) $date->format('j'), 'month' => (int) $date->format('n')];
    } catch (Throwable) {
        return [];
    }
}

function rpc_birthday_today(array $parts): bool
{
    return ($parts['day'] ?? null) === (int) date('j') && ($parts['month'] ?? null) === (int) date('n');
}

function rpc_birthday_month(array $parts): bool
{
    return ($parts['month'] ?? null) === (int) date('n');
}

function rpc_birthday_week(array $parts): bool
{
    if (!$parts) {
        return false;
    }
    $year = (int) date('Y');
    $birthday = DateTimeImmutable::createFromFormat('!Y-n-j', $year . '-' . $parts['month'] . '-' . $parts['day']);
    if (!$birthday) {
        return false;
    }
    $today = new DateTimeImmutable('today');
    $end = $today->modify('+7 days');
    return $birthday >= $today && $birthday <= $end;
}

function rpc_strip_tags(string $html): string
{
    return trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');
}
