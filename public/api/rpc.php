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
        'app_getUserAccessLight' => ['ok' => true, 'user' => array_merge($user, ['permissions' => rpc_permissions($user)]), 'permissions' => rpc_permissions($user)],
        'app_loadContactsLite' => rpc_contacts_lite($user),
        'personenUi_getMainDetails' => rpc_person_main((string) ($args[0] ?? ''), $user),
        'personenUi_getFullDetails' => rpc_person_full((string) ($args[0] ?? ''), $user),
        'personenUi_getExtraDetails' => rpc_person_extra((string) ($args[0] ?? ''), $user),
        'personenUi_extendedSearch' => rpc_extended_search((string) ($args[0] ?? ''), $user),
        'personenUi_getFamily' => rpc_is_guest_role($user) ? rpc_empty_family((string) ($args[0] ?? '')) : rpc_family((string) ($args[0] ?? '')),
        'personenUi_getGroupByName' => rpc_is_guest_role($user) ? rpc_empty_group((string) ($args[0] ?? '')) : rpc_group((string) ($args[0] ?? '')),
        'getCalendarEventsRange' => rpc_calendar((string) ($args[0] ?? ''), (string) ($args[1] ?? '')),
        'kalenderUi_getServiceOverview' => rpc_service_overview($args[0] ?? []),
        'kalenderUi_getServiceStaff' => rpc_service_staff($args[0] ?? []),
        'kalenderUi_getServiceFlow' => rpc_service_flow($args[0] ?? []),
        'kalenderUi_getServiceDetails', 'kalenderUi_refreshServiceDetails' => rpc_service_details($args[0] ?? []),
        'kalenderUi_getPersonServiceAssignments' => rpc_person_service_assignments(
            (string) ($args[0] ?? ''),
            (string) ($args[1] ?? ''),
            (string) ($args[2] ?? '')
        ),
        'app_loadSongsLite' => rpc_songs_lite(),
        'app_loadFilterDefs' => ['ok' => true, 'filters' => rpc_contact_filters(), 'dataVersion' => rpc_data_version()],
        'app_loadDashboardStats' => ['ok' => true, 'dashboard' => rpc_is_guest_role($user) ? [] : rpc_dashboard($user), 'dataVersion' => rpc_data_version()],
        'tools_getSyncStatus' => rpc_sync_status($user),
        'tools_getCacheDiagnostics' => ['ok' => true, 'cache' => rpc_cache_stats(), 'dataVersion' => rpc_data_version()],
        'tools_debugCachePayloads' => ['ok' => true, 'cache' => rpc_cache_stats(), 'latestRuns' => rpc_import_runs()],
        'tools_rebuildServerCaches' => rpc_rebuild_server_caches($user),
        'tools_loadUserSmartFilters' => rpc_load_user_smart_filters($user),
        'tools_saveUserSmartFilters' => rpc_save_user_smart_filters($args[0] ?? [], $user),
        'tools_importPersonen' => rpc_run_import('personen', $user),
        'tools_importPersonenSmart' => rpc_run_import('personen', $user),
        'tools_importFamilien' => rpc_run_import('familien', $user),
        'tools_importGruppen' => rpc_run_import('gruppen', $user),
        'tools_importKalender' => rpc_run_import('kalender', $user),
        'tools_importServiceDetails' => rpc_run_import('service_details', $user),
        'tools_importSongs' => rpc_run_import('songs', $user),
        'tools_importAlles' => rpc_run_import('alles', $user),
        'tools_adminUsersList' => rpc_admin_users_list($user),
        'tools_adminCreateUser' => rpc_admin_create_user($args[0] ?? [], $user),
        'tools_adminUpdateUser' => rpc_admin_update_user($args[0] ?? [], $user),
        'tools_adminImpersonateUser' => rpc_admin_impersonate_user((int) ($args[0] ?? 0), $user),
        'tools_adminStopImpersonation' => rpc_admin_stop_impersonation($user),
        'tools_installNightlyServerCacheRebuild' => rpc_nightly_cache_notice('install'),
        'tools_removeNightlyServerCacheRebuild' => rpc_nightly_cache_notice('remove'),
        'personenUi_getPrayerDeck' => ['ok' => true, 'cards' => rpc_prayer_deck($user)],
        'prayerDeck_getByPool' => ['ok' => true, 'cards' => rpc_prayer_deck_by_pool((string) ($args[0] ?? ''), $user)],
        'prayerPools_get' => ['ok' => true, 'pools' => rpc_prayer_pools($user)],
        'prayerPools_getMembers' => ['ok' => true, 'members' => rpc_prayer_pool_members((string) ($args[0] ?? 'Kranke'))],
        'prayerPools_create' => rpc_prayer_pool_create($args[0] ?? [], $user),
        'prayerPools_delete' => rpc_prayer_pool_delete((string) ($args[0] ?? '')),
        'prayerPools_addMembers' => rpc_prayer_pool_add_members($args[0] ?? []),
        'prayerPools_removeMembers' => rpc_prayer_pool_remove_members($args[0] ?? []),
        'prayer_startSession' => rpc_prayer_start_session($args[0] ?? [], $user),
        'prayer_heartbeat' => rpc_prayer_heartbeat($args[0] ?? []),
        'prayer_endSession' => rpc_prayer_end_session($args[0] ?? []),
        'prayer_getLeaderboard' => rpc_prayer_leaderboard($user),
        'app_getFilteredContactsPrintRows' => rpc_contacts_print_rows_response($args[0] ?? [], $args[1] ?? [], $user),
        'app_exportFilteredContactsCsv' => rpc_contacts_csv_response($args[0] ?? [], $args[1] ?? [], $user),
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
            'impersonating' => $user['impersonating'] ?? null,
        ],
        'cache' => [],
        'filters' => rpc_contact_filters(),
        'dashboard' => rpc_is_guest_role($user) ? [] : rpc_dashboard($user),
    ];
}

function rpc_permissions(array $user = []): array
{
    $role = strtolower((string) ($user['role'] ?? 'guest'));
    $isAdmin = in_array($role, ['admin', 'super_admin'], true);
    $isSuperAdmin = $role === 'super_admin';
    $isGuest = $role === 'guest' || $role === 'gast';

    return [
        'tabs' => [
            'contacts' => true,
            'calendar' => true,
            'songs' => true,
            'dashboard' => !$isGuest,
            'tools' => true,
        ],
        'exports' => [
            'contactsCsv' => $isAdmin,
            'contactsPrint' => $isAdmin,
            'calendarCsv' => $isAdmin,
            'calendarPrint' => $isAdmin,
        ],
        'detailPrint' => [
            'contact' => $isAdmin,
            'event' => $isAdmin,
        ],
        'admin' => [
            'imports' => $isAdmin,
            'users' => $isSuperAdmin,
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
        $dataVersion = preg_replace('/\D+/', '', (string) ($row['version_value'] ?? '')) ?: '1';
        $codeVersion = max((int) (@filemtime(__FILE__) ?: 0), (int) (@filemtime(__DIR__ . '/../../src/import_people.php') ?: 0));
        $version = $dataVersion . '-' . $codeVersion;
    } catch (Throwable) {
        $version = '1';
    }
    return $version;
}

function rpc_contacts_lite(array $user = []): array
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
        $contacts[] = rpc_contact_for_role(rpc_contact_row(
            $row,
            $customValues[$personId] ?? [],
            $groupValues[$personId] ?? ['groups' => [], 'leads' => [], 'assists' => []],
            $familyValues[$personId] ?? []
        ), $user);
    }

    return [
        'ok' => true,
        'ts' => date('c'),
        'dataVersion' => rpc_data_version(),
        'contacts' => $contacts,
    ];
}

function rpc_contact_for_role(array $contact, array $user): array
{
    if (!rpc_is_guest_role($user)) {
        return $contact;
    }

    foreach (['email', 'phone', 'mobile', 'birthday', 'dateAdded', 'gender', 'genderKind', 'familyId', 'address', 'age'] as $key) {
        $contact[$key] = $key === 'age' ? null : '';
    }
    foreach (['departmentsValues', 'kgGroupValues', 'kgLeadGroupValues', 'kgAssistantGroupValues', 'leaderships', 'nextStepValues', 'kidsChurchValues', 'youthYpgValues'] as $key) {
        $contact[$key] = [];
    }
    foreach (['hasKg', 'leadsKg', 'hasMitarbeit', 'new12', 'new6', 'new3', 'new14', 'lastYear12Cmp', 'lastYear6Cmp', 'lastYear3Cmp', 'lastYear14Cmp', 'birthdayToday', 'birthdayWeek', 'birthdayMonthFlag'] as $key) {
        $contact[$key] = false;
    }
    $contact['isFamilyMain'] = false;
    $contact['isSingle'] = false;
    $contact['householdTypeKey'] = '';
    $contact['ageBucket'] = '';
    $contact['birthdayDay'] = '';
    $contact['birthdayMonth'] = '';
    $contact['searchText'] = rpc_search_text(implode(' ', [
        rpc_str($contact['displayName'] ?? ''),
        rpc_str($contact['firstName'] ?? ''),
        rpc_str($contact['preferredName'] ?? ''),
        rpc_str($contact['lastName'] ?? ''),
        rpc_str($contact['postcode'] ?? ''),
        rpc_str($contact['city'] ?? ''),
        rpc_str($contact['category'] ?? ''),
    ]));
    $contact['searchMeta'] = $contact['searchText'];
    return $contact;
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
    $familyId = rpc_str($row['family_id'] ?? '') ?: rpc_str($family['familyId'] ?? '');
    $birthday = rpc_normalize_dashboard_date(
        rpc_str($row['birthday'] ?? '') ?: rpc_custom_first_value($custom, ['BIRTHDAY', 'GEBURTSDATUM'])
    );
    $age = rpc_age($birthday);
    $gender = rpc_str($row['gender'] ?? '') ?: rpc_custom_first_value($custom, ['GENDER', 'GESCHLECHT']);
    $relationshipKind = rpc_family_relationship_kind(rpc_str($family['relationship'] ?? ''));
    $departmentsValues = rpc_ministry_values($row, $custom);
    $leaderships = rpc_split_leadership_values($custom['LEITERSCHAFT'] ?? '');
    $kgGroupValues = $groups['groups'] ?? [];
    $kgLeadGroupValues = $groups['leads'] ?? [];
    $kgAssistantGroupValues = $groups['assists'] ?? [];
    $phoneSearchTokens = rpc_phone_search_tokens(
        rpc_str($row['phone'] ?? ''),
        rpc_str($row['mobile'] ?? '')
    );
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
        'familyId' => $familyId,
        'gender' => $gender,
        'genderKind' => rpc_gender_kind($gender),
        'birthday' => $birthday,
        'dateAdded' => $dateAdded,
        'age' => $age,
        'ageBucket' => rpc_age_bucket($age),
        'isChild' => $relationshipKind === 'child' || ($age !== null && $age < 16),
        'isFamilyMain' => (bool) ($family['isFamilyMain'] ?? false),
        'isSingle' => (bool) ($family['isSingle'] ?? false),
        'householdTypeKey' => rpc_str($family['householdTypeKey'] ?? ''),
        'pictureUrl' => rpc_str($row['picture_url'] ?? ''),
        'address' => rpc_str($row['home_address'] ?? ''),
        'city' => rpc_str($row['home_city'] ?? ''),
        'postcode' => rpc_str($row['home_postcode'] ?? ''),
        'sub' => $cityLine,
        'searchName' => rpc_search_text($first . ' ' . $preferred . ' ' . $last),
        'searchText' => rpc_search_text(implode(' ', array_merge([$display, $first, $preferred, $last, rpc_str($row['home_postcode'] ?? ''), rpc_str($row['home_city'] ?? ''), $category], $kgGroupValues, $departmentsValues, $phoneSearchTokens))),
        'searchMeta' => rpc_search_text(implode(' ', array_merge([$first, $preferred, $last, rpc_str($row['home_postcode'] ?? ''), rpc_str($row['home_city'] ?? ''), $category], $kgGroupValues, $departmentsValues, $phoneSearchTokens))),
        'hasKg' => count($kgGroupValues) > 0,
        'leadsKg' => $leadsKg,
        'hasMitarbeit' => count($departmentsValues) > 0,
        'departmentsValues' => $departmentsValues,
        'kgGroupValues' => $kgGroupValues,
        'kgLeadGroupValues' => $kgLeadGroupValues,
        'kgAssistantGroupValues' => $kgAssistantGroupValues,
        'leaderships' => $leaderships,
        'nextStepValues' => rpc_next_step_values($custom),
        'kidsChurchValues' => rpc_split_multi_value($custom['KIDS & PROMISELAND'] ?? ''),
        'youthYpgValues' => rpc_split_multi_value($custom['JUNGE ERWACHSENE'] ?? ''),
        'new12' => rpc_date_within_months($dateAdded, 12),
        'new6' => rpc_date_within_months($dateAdded, 6),
        'new3' => rpc_date_within_months($dateAdded, 3),
        'new14' => rpc_date_within_days($dateAdded, 14),
        'lastYear12Cmp' => rpc_date_between_relative($dateAdded, '-2 years', '-1 year'),
        'lastYear6Cmp' => rpc_date_between_relative($dateAdded, '-1 year -6 months', '-1 year'),
        'lastYear3Cmp' => rpc_date_between_relative($dateAdded, '-1 year -3 months', '-1 year'),
        'lastYear14Cmp' => rpc_date_between_relative($dateAdded, '-1 year -14 days', '-1 year'),
        'birthdayDay' => $birthdayParts['day'] ?? '',
        'birthdayMonth' => $birthdayParts['month'] ?? '',
        'birthdayToday' => rpc_birthday_today($birthdayParts),
        'birthdayWeek' => rpc_birthday_week($birthdayParts),
        'birthdayMonthFlag' => rpc_birthday_month($birthdayParts),
    ];
}

function rpc_custom_first_value(array $custom, array $keys): string
{
    $wanted = array_flip(array_map(static fn(string $key): string => strtoupper($key), $keys));
    foreach ($custom as $label => $value) {
        $labelKey = strtoupper(rpc_str($label));
        if (isset($wanted[$labelKey]) && rpc_str($value) !== '') {
            return rpc_str($value);
        }
    }
    foreach ($custom as $label => $value) {
        $labelKey = strtoupper(rpc_str($label));
        foreach ($wanted as $key => $_) {
            if (str_contains($labelKey, $key) && rpc_str($value) !== '') {
                return rpc_str($value);
            }
        }
    }
    return '';
}

function rpc_normalize_dashboard_date(string $value): string
{
    $raw = trim($value);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m)) {
        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $raw, $m)) {
        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d', $ts) : $raw;
}

function rpc_ministry_values(array $row, array $custom): array
{
    $values = rpc_split_multi_value(rpc_str($row['departments'] ?? ''));
    foreach ($custom as $label => $value) {
        $labelKey = strtoupper(rpc_str($label));
        if ($labelKey === 'LEITERSCHAFT') {
            continue;
        }
        $isMinistryField = str_contains($labelKey, 'MITARBEIT')
            || str_contains($labelKey, 'TEAM')
            || str_contains($labelKey, 'DIENST')
            || str_contains($labelKey, 'RESSORT');
        if (!$isMinistryField) {
            continue;
        }
        foreach (rpc_split_multi_value(rpc_str($value)) as $item) {
            if (!in_array($item, $values, true)) {
                $values[] = $item;
            }
        }
    }
    return $values;
}

function rpc_next_step_values(array $custom): array
{
    $values = [];
    foreach ($custom as $label => $value) {
        $labelKey = strtoupper(rpc_str($label));
        $isNextStepField = str_contains($labelKey, 'KURSE')
            || str_contains($labelKey, 'TAUFE')
            || str_contains($labelKey, 'NEXT')
            || str_contains($labelKey, 'SCHRITT');
        if (!$isNextStepField) {
            continue;
        }
        foreach (rpc_split_multi_value(rpc_str($value)) as $item) {
            if (!in_array($item, $values, true)) {
                $values[] = $item;
            }
        }
    }
    return $values;
}

function rpc_is_category(array $contact, string $category): bool
{
    return rpc_lower(rpc_str($contact['category'] ?? '')) === rpc_lower($category);
}

function rpc_count_contacts(array $contacts, callable $predicate): int
{
    $count = 0;
    foreach ($contacts as $contact) {
        if ($predicate($contact)) {
            $count++;
        }
    }
    return $count;
}

function rpc_people_added_between(string $startModifier, string $endModifier): int
{
    $start = (new DateTimeImmutable('today'))->modify($startModifier)->format('Y-m-d 00:00:00');
    $end = (new DateTimeImmutable('today'))->modify($endModifier)->format('Y-m-d 23:59:59');
    $stmt = db()->prepare(
        "SELECT COUNT(*) AS c
         FROM people
         WHERE date_added >= ? AND date_added <= ?
           AND (status IS NULL OR status = '' OR LOWER(status) = 'active')
           AND LOWER(COALESCE(category_name, '')) = 'gemeinde'"
    );
    $stmt->execute([$start, $end]);
    return (int) ($stmt->fetch()['c'] ?? 0);
}

function rpc_percent_delta(int $current, int $previous): int
{
    if ($previous <= 0) {
        return $current > 0 ? 100 : 0;
    }
    return (int) round((($current - $previous) / $previous) * 100);
}

function rpc_trend(int $current, int $previous): string
{
    if ($current > $previous) {
        return 'up';
    }
    if ($current < $previous) {
        return 'down';
    }
    return 'flat';
}

function rpc_gender_kind(string $gender): string
{
    $value = rpc_lower($gender);
    if (str_contains($value, 'männ')) {
        return 'male';
    }
    if ($value === 'm' || str_contains($value, 'mann') || str_contains($value, 'male') || str_contains($value, 'männ')) {
        return 'male';
    }
    if ($value === 'w' || $value === 'f' || str_contains($value, 'frau') || str_contains($value, 'female') || str_contains($value, 'weib')) {
        return 'female';
    }
    return '';
}

function rpc_age_range_count(array $contacts, int $min, int $max): int
{
    return rpc_count_contacts($contacts, static function (array $contact) use ($min, $max): bool {
        $age = $contact['age'] ?? null;
        return is_int($age) && $age >= $min && $age <= $max;
    });
}

function rpc_value_counts(array $contacts, string $field): array
{
    $counts = [];
    foreach ($contacts as $contact) {
        $values = is_array($contact[$field] ?? null) ? $contact[$field] : [];
        foreach ($values as $value) {
            $label = rpc_str($value);
            if ($label === '') {
                continue;
            }
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }
    }
    ksort($counts, SORT_NATURAL | SORT_FLAG_CASE);
    return $counts;
}

function rpc_counts_to_items(array $counts): array
{
    $items = [];
    foreach ($counts as $label => $count) {
        $items[] = [
            'label' => (string) $label,
            'count' => (int) $count,
            'filterKey' => (string) $label,
            'filterLabel' => (string) $label,
        ];
    }
    return $items;
}

function rpc_contact_location(array $contact): string
{
    $place = trim(rpc_str($contact['postcode'] ?? '') . ' ' . rpc_str($contact['city'] ?? ''));
    return implode(', ', array_values(array_filter([rpc_str($contact['address'] ?? ''), $place])));
}

function rpc_family_relationship_kind(string $relationship): string
{
    $relation = rpc_lower($relationship);
    if (str_contains($relation, 'haupt')) {
        return 'main';
    }
    if (str_contains($relation, 'ehe') || str_contains($relation, 'partner') || str_contains($relation, 'spouse')) {
        return 'adult';
    }
    if (str_contains($relation, 'kind') || str_contains($relation, 'child')) {
        return 'child';
    }
    if (str_contains($relation, 'einzel')) {
        return 'single';
    }
    return 'other';
}

function rpc_prayer_member_payload(array $contact): array
{
    return [
        'personId' => rpc_str($contact['id'] ?? ''),
        'name' => rpc_str($contact['displayName'] ?? ''),
        'role' => rpc_str($contact['role'] ?? ''),
        'picture' => rpc_str($contact['pictureUrl'] ?? ''),
        'age' => $contact['age'] ?? null,
        'isChild' => (bool) ($contact['isChild'] ?? false),
        'new12' => (bool) ($contact['new12'] ?? false),
        'new6' => (bool) ($contact['new6'] ?? false),
        'new3' => (bool) ($contact['new3'] ?? false),
    ];
}

function rpc_prayer_contact_allowed(array $contact): bool
{
    $category = rpc_lower(rpc_str($contact['category'] ?? ''));
    return $category === '' || $category === 'gemeinde' || $category === 'kontakte';
}

function rpc_prayer_member_role(string $relationship, bool $isSingle): string
{
    $kind = rpc_family_relationship_kind($relationship);
    if ($isSingle || $kind === 'single') {
        return 'Einzelperson';
    }
    if ($kind === 'main' || $kind === 'adult') {
        return 'Eltern';
    }
    if ($kind === 'child') {
        return 'Kind';
    }
    return 'Familienmitglied';
}

function rpc_prayer_pool_id(string $poolName): int
{
    $name = rpc_str($poolName);
    if ($name === '') {
        return 0;
    }
    $stmt = db()->prepare('SELECT id FROM prayer_pools WHERE LOWER(pool_name) = LOWER(?) LIMIT 1');
    $stmt->execute([$name]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

function rpc_ensure_prayer_pool(string $poolName): int
{
    $name = rpc_str($poolName) ?: 'Kranke';
    $existing = rpc_prayer_pool_id($name);
    if ($existing > 0) {
        return $existing;
    }
    $stmt = db()->prepare('INSERT INTO prayer_pools (pool_name) VALUES (?)');
    $stmt->execute([$name]);
    return (int) db()->lastInsertId();
}

function rpc_person_full(string $personId, array $user = []): array
{
    $main = rpc_person_main($personId, $user);
    $familyId = rpc_str($main['meta']['familyId'] ?? '');
    return [
        'ok' => true,
        'main' => $main,
        'extra' => rpc_person_extra($personId, $user),
        'family' => (!rpc_is_guest_role($user) && $familyId !== '') ? rpc_family($familyId) : null,
    ];
}

function rpc_person_main(string $personId, array $user = []): array
{
    $row = rpc_fetch_person($personId);
    if (!$row) {
        return ['type' => 'person', 'displayName' => '', 'details' => [], 'meta' => ['personId' => $personId], 'previewOnly' => true];
    }

    [$custom, $groups, $family] = rpc_person_enrichment($personId);
    $lite = rpc_contact_row($row, $custom, $groups, $family);
    $address = rpc_address_block($lite['address'], $lite['postcode'], $lite['city']);
    $birthday = rpc_ui_date($lite['birthday']);
    $age = $lite['age'] !== null ? (string) $lite['age'] : '';

    $details = [
        rpc_detail('Kategorie', $lite['category']),
        $lite['preferredName'] !== '' ? rpc_detail('Rufname', $lite['preferredName']) : rpc_detail('Rufname', ''),
        rpc_detail('E-Mail', $lite['email'], 'email', $lite['email'] ? 'mailto:' . $lite['email'] : ''),
        rpc_detail('Telefon', $lite['phone'], 'phone', $lite['phone'] ? 'tel:' . rpc_phone_href_value($lite['phone']) : ''),
        array_merge(
            rpc_detail('Mobile', $lite['mobile'], 'phone', $lite['mobile'] ? 'tel:' . rpc_phone_href_value($lite['mobile']) : ''),
            ['waHref' => rpc_whatsapp_href($lite['mobile'])]
        ),
        array_merge(rpc_detail('Adresse', $address, 'address'), ['mapHref' => rpc_maps_href($address), 'preline' => true]),
        rpc_detail('Geburtsdatum', $birthday),
        rpc_detail('Alter', $age),
        rpc_detail('Geschlecht', $lite['gender']),
        $lite['dateAdded'] !== '' ? rpc_detail('Hinzugefügt', rpc_ui_date(substr($lite['dateAdded'], 0, 10))) : rpc_detail('Hinzugefügt', ''),
        $lite['familyId'] !== '' ? rpc_detail('Familie', 'Familie anzeigen', 'family') : rpc_detail('Familie', ''),
        rpc_detail('Picture', $lite['pictureUrl']),
    ];

    if (rpc_is_guest_role($user)) {
        $guestLabels = ['Kategorie' => true, 'Rufname' => true, 'Picture' => true];
        $details = array_values(array_filter(
            $details,
            static fn(array $item): bool => isset($guestLabels[rpc_str($item['label'] ?? '')])
        ));
        $location = trim(rpc_str($lite['postcode'] ?? '') . ' ' . rpc_str($lite['city'] ?? ''));
        if ($location !== '') {
            $details[] = rpc_detail('Ort', $location);
        }
    }

    return [
        'type' => 'person',
        'displayName' => $lite['displayName'],
        'details' => array_values(array_filter($details, static fn(array $item): bool => trim((string) ($item['value'] ?? '')) !== '')),
        'meta' => [
            'personId' => $personId,
            'familyId' => $lite['familyId'],
            'pictureUrl' => $lite['pictureUrl'],
            'elvantoUrl' => rpc_elvanto_person_url($personId),
        ],
    ];
}

function rpc_person_extra(string $personId, array $user = []): array
{
    if (!rpc_is_admin_role($user)) {
        return [];
    }

    $row = rpc_fetch_person($personId);
    if (!$row) {
        return [];
    }

    $custom = fetch_all_prepared_legacy(
        'SELECT field_name, field_value FROM people_custom_fields WHERE person_id = ? ORDER BY field_name',
        [$personId]
    );

    $extra = [];
    foreach (rpc_person_group_sections($personId) as $section) {
        $extra[] = $section;
    }

    if (rpc_str($row['departments'] ?? '') !== '') {
        $extra[] = rpc_detail('Mitarbeit', rpc_str($row['departments'] ?? ''));
    }

    if (rpc_str($row['date_added'] ?? '') !== '') {
        $extra[] = rpc_detail('Hinzugefügt', rpc_ui_date(substr(rpc_str($row['date_added'] ?? ''), 0, 10)));
    }

    $hiddenLabels = array_flip([
        'ID', 'DATE ADDED', 'DATE MODIFIED', 'STATUS', 'CATEGORY ID', 'CATEGORY', 'CATEGORY NAME',
        'AUSGETRETEN', 'EINTRITTSGRUND', 'IST IN KLEINGRUPPE', 'LEITERSCHAFT', 'IN KLEINGRUPPE VON',
        'FIRSTNAME', 'LASTNAME', 'PREFERRED NAME', 'PICTURE', 'EMAIL', 'PHONE', 'MOBILE',
        'HOME ADDRESS', 'HOME CITY', 'HOME POSTCODE', 'GENDER', 'BIRTHDAY', 'FAMILY ID',
    ]);
    $keyDetails = [
        'type' => '',
        'number' => '',
        'depot' => '',
        'returnedAt' => '',
    ];
    $pendingReturnedAt = null;
    foreach ($custom as $field) {
        $label = rpc_str($field['field_name'] ?? '');
        $labelKey = strtoupper($label);
        $value = rpc_str($field['field_value'] ?? '');
        if ($value === '' || isset($hiddenLabels[$labelKey])) {
            continue;
        }
        $normalizedLabel = rpc_person_extra_label_key($label);
        if ($normalizedLabel === 'clz schluessel') {
            $keyDetails['type'] = rpc_person_extra_clean_key_type($value);
            continue;
        }
        if ($normalizedLabel === 'clz schluessel nr') {
            $keyDetails['number'] = $value;
            continue;
        }
        if ($normalizedLabel === 'schluessel depot') {
            $keyDetails['depot'] = $value;
            continue;
        }
        if ($normalizedLabel === 'abgabedatum') {
            $pendingReturnedAt = rpc_person_extra_detail($label, $value);
            $keyDetails['returnedAt'] = rpc_person_extra_format_value($label, $value);
            continue;
        }
        $extra[] = rpc_person_extra_detail($label, $value);
    }

    if ($keyDetails['type'] !== '' || $keyDetails['number'] !== '' || $keyDetails['depot'] !== '') {
        $lines = [];
        if ($keyDetails['type'] !== '') {
            $lines[] = $keyDetails['type'];
        }
        if ($keyDetails['number'] !== '') {
            $lines[] = 'CLZ-Schlüssel-Nr.: ' . $keyDetails['number'];
        }
        if ($keyDetails['depot'] !== '') {
            $lines[] = 'Schlüssel-Depot: ' . $keyDetails['depot'];
        }
        if ($keyDetails['returnedAt'] !== '') {
            $lines[] = 'Abgabedatum: ' . $keyDetails['returnedAt'];
        }
        $extra[] = array_merge(
            rpc_detail('CLZ-Schlüssel', implode("\n", $lines)),
            ['preline' => true, 'copyValue' => implode("\n", $lines)]
        );
    } elseif ($pendingReturnedAt !== null) {
        $extra[] = $pendingReturnedAt;
    }

    usort($extra, static function (array $a, array $b): int {
        $priority = [
            'group-section' => 0,
            'Geburtsdatum' => 10,
            'Mitarbeit' => 20,
            'CLZ-Schlüssel' => 25,
            'KURSE / TAUFE' => 30,
            'KIDS & PROMISELAND' => 40,
            'JUNGE ERWACHSENE' => 50,
        ];
        $labelA = rpc_str($a['label'] ?? '');
        $labelB = rpc_str($b['label'] ?? '');
        $rankA = ($a['type'] ?? '') === 'group-section' ? ($labelA === 'Leitet Kleingruppe' ? 0 : 1) : ($priority[$labelA] ?? 100);
        $rankB = ($b['type'] ?? '') === 'group-section' ? ($labelB === 'Leitet Kleingruppe' ? 0 : 1) : ($priority[$labelB] ?? 100);
        return $rankA <=> $rankB ?: strcasecmp($labelA, $labelB);
    });
    return $extra;
}

function rpc_person_extra_detail(string $label, string $value): array
{
    return rpc_detail($label, rpc_person_extra_format_value($label, $value));
}

function rpc_person_extra_label_key(string $label): string
{
    $key = str_replace(['-', '_'], ' ', rpc_search_text($label));
    return trim(preg_replace('/\s+/', ' ', $key) ?? $key);
}

function rpc_person_extra_format_value(string $label, string $value): string
{
    if (!rpc_person_extra_is_date_label($label)) {
        return $value;
    }
    return rpc_person_extra_format_date($value);
}

function rpc_person_extra_is_date_label(string $label): bool
{
    $key = rpc_person_extra_label_key($label);
    return str_contains($key, 'datum') || $key === 'besucht am';
}

function rpc_person_extra_format_date(string $value): string
{
    $raw = rpc_str($value);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
        return rpc_ui_date(substr($raw, 0, 10));
    }
    return $raw;
}

function rpc_person_extra_clean_key_type(string $value): string
{
    $raw = rpc_str($value);
    return rpc_lower($raw) === 'schluessel' ? 'Schlüssel' : $raw;
}

function rpc_person_enrichment(string $personId): array
{
    $customValues = rpc_people_custom_values();
    $groupValues = rpc_people_group_values();
    $familyValues = rpc_people_family_values();
    return [
        $customValues[$personId] ?? [],
        $groupValues[$personId] ?? ['groups' => [], 'leads' => [], 'assists' => []],
        $familyValues[$personId] ?? [],
    ];
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

function rpc_extended_search(string $query, array $user = []): array
{
    $needle = rpc_search_text($query);
    if ($needle === '') {
        return ['ok' => true, 'contacts' => []];
    }

    $contacts = rpc_contacts_lite($user)['contacts'];
    $contacts = array_values(array_filter($contacts, static fn(array $row): bool => rpc_contact_matches_extended_search($row, $query)));
    return ['ok' => true, 'contacts' => $contacts];
}

function rpc_contact_matches_extended_search(array $contact, string $query): bool
{
    $needle = rpc_search_text($query);
    if ($needle === '') {
        return false;
    }

    $fields = [
        $contact['firstName'] ?? '',
        $contact['preferredName'] ?? '',
        $contact['lastName'] ?? '',
        $contact['email'] ?? '',
        $contact['phone'] ?? '',
        $contact['mobile'] ?? '',
        $contact['address'] ?? '',
        $contact['city'] ?? '',
        $contact['postcode'] ?? '',
        $contact['category'] ?? '',
        $contact['gender'] ?? '',
        $contact['birthday'] ?? '',
        $contact['dateAdded'] ?? '',
        $contact['searchText'] ?? '',
        $contact['searchMeta'] ?? '',
    ];
    $haystack = rpc_search_text(implode(' ', array_map('rpc_str', $fields)));
    if (str_contains($haystack, $needle)) {
        return true;
    }

    $queryDigits = preg_replace('/\D+/', '', $query) ?? '';
    if ($queryDigits === '') {
        return false;
    }

    foreach ([rpc_str($contact['phone'] ?? ''), rpc_str($contact['mobile'] ?? '')] as $phone) {
        if ($phone === '') {
            continue;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $local = rpc_normalize_phone_search_token($phone);
        if (($digits !== '' && str_contains($digits, $queryDigits)) || ($local !== '' && str_contains($local, $queryDigits))) {
            return true;
        }
    }

    return false;
}

function rpc_contacts_print_rows_response(mixed $ids, mixed $columns, array $user): array
{
    if (!rpc_user_can_export_contacts($user)) {
        return ['ok' => false, 'error' => 'Export fuer diese Rolle nicht freigeschaltet.'];
    }

    $selected = rpc_contact_export_columns($columns);
    $rows = rpc_contact_export_rows(rpc_normalize_ids($ids), $selected);
    return [
        'ok' => true,
        'headers' => array_map(static fn(string $key): string => rpc_contact_export_label($key), $selected),
        'rows' => $rows,
        'count' => count($rows),
    ];
}

function rpc_contacts_csv_response(mixed $ids, mixed $columns, array $user): array
{
    if (!rpc_user_can_export_contacts($user)) {
        return ['ok' => false, 'error' => 'Export fuer diese Rolle nicht freigeschaltet.'];
    }

    $selected = rpc_contact_export_columns($columns);
    $headers = array_map(static fn(string $key): string => rpc_contact_export_label($key), $selected);
    $rows = rpc_contact_export_rows(rpc_normalize_ids($ids), $selected);
    $csv = rpc_build_csv($headers, $rows);
    $stamp = (new DateTimeImmutable())->format('Ymd_Hi');

    return [
        'ok' => true,
        'filename' => 'kontakte_export_' . $stamp . '.csv',
        'mimeType' => 'text/csv;charset=utf-8',
        'base64' => base64_encode($csv),
        'count' => count($rows),
    ];
}

function rpc_user_can_export_contacts(array $user): bool
{
    return rpc_is_admin_role($user);
}

function rpc_user_role(array $user): string
{
    return strtolower(rpc_str($user['role'] ?? 'guest')) ?: 'guest';
}

function rpc_is_guest_role(array $user): bool
{
    return in_array(rpc_user_role($user), ['guest', 'gast'], true);
}

function rpc_is_member_role(array $user): bool
{
    return rpc_user_role($user) === 'member';
}

function rpc_is_admin_role(array $user): bool
{
    return in_array(rpc_user_role($user), ['admin', 'super_admin'], true);
}

function rpc_is_super_admin_role(array $user): bool
{
    return rpc_user_role($user) === 'super_admin';
}

function rpc_is_real_admin(array $user): bool
{
    if (rpc_is_admin_role($user)) {
        return true;
    }
    $original = $user['impersonating']['originalUser'] ?? null;
    return is_array($original) && rpc_is_admin_role($original);
}

function rpc_is_real_super_admin(array $user): bool
{
    if (rpc_is_super_admin_role($user)) {
        return true;
    }
    $original = $user['impersonating']['originalUser'] ?? null;
    return is_array($original) && rpc_is_super_admin_role($original);
}

function rpc_require_real_admin(array $user): void
{
    if (!rpc_is_real_admin($user)) {
        throw new RuntimeException('Nur Admins duerfen diese Aktion ausfuehren.');
    }
}

function rpc_require_real_super_admin(array $user): void
{
    if (!rpc_is_real_super_admin($user)) {
        throw new RuntimeException('Nur Super-Admins duerfen diese Aktion ausfuehren.');
    }
}

function rpc_admin_users_list(array $user): array
{
    rpc_require_real_super_admin($user);
    $rows = fetch_all_prepared_legacy(
        'SELECT id, email, display_name, role, is_active, last_login_at
         FROM users
         ORDER BY last_login_at DESC, email ASC',
        []
    );

    return [
        'ok' => true,
        'roles' => ['super_admin', 'admin', 'member', 'guest'],
        'users' => array_map(static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'email' => rpc_str($row['email'] ?? ''),
                'displayName' => rpc_str($row['display_name'] ?? ''),
                'role' => rpc_str($row['role'] ?? 'guest') ?: 'guest',
                'isActive' => ((int) ($row['is_active'] ?? 0)) === 1,
                'lastLoginAt' => rpc_str($row['last_login_at'] ?? ''),
                'createdAt' => '',
                'updatedAt' => '',
            ];
        }, $rows),
    ];
}

function rpc_admin_update_user(mixed $payload, array $user): array
{
    rpc_require_real_super_admin($user);
    $data = is_array($payload) ? $payload : [];
    $id = (int) ($data['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('User fehlt.');
    }
    $role = strtolower(rpc_str($data['role'] ?? 'guest'));
    if (!in_array($role, ['super_admin', 'admin', 'member', 'guest'], true)) {
        throw new RuntimeException('Ungueltige Rolle.');
    }
    $isActive = array_key_exists('isActive', $data) ? (((bool) $data['isActive']) ? 1 : 0) : 1;
    $stmt = db()->prepare('UPDATE users SET role = ?, is_active = ? WHERE id = ?');
    $stmt->execute([$role, $isActive, $id]);
    return rpc_admin_users_list($user);
}

function rpc_admin_create_user(mixed $payload, array $user): array
{
    rpc_require_real_super_admin($user);
    $data = is_array($payload) ? $payload : [];
    $email = strtolower(rpc_str($data['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Bitte eine gueltige E-Mail-Adresse eingeben.');
    }
    $displayName = rpc_str($data['displayName'] ?? '');
    $role = strtolower(rpc_str($data['role'] ?? 'guest'));
    if (!in_array($role, ['super_admin', 'admin', 'member', 'guest'], true)) {
        throw new RuntimeException('Ungueltige Rolle.');
    }
    $isActive = array_key_exists('isActive', $data) ? (((bool) $data['isActive']) ? 1 : 0) : 1;

    $stmt = db()->prepare(
        'INSERT INTO users (email, display_name, role, is_active)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            display_name = IF(VALUES(display_name) = "", display_name, VALUES(display_name)),
            role = VALUES(role),
            is_active = VALUES(is_active)'
    );
    $stmt->execute([$email, $displayName, $role, $isActive]);

    return rpc_admin_users_list($user);
}

function rpc_admin_impersonate_user(int $targetUserId, array $user): array
{
    rpc_require_real_super_admin($user);
    if ($targetUserId <= 0) {
        throw new RuntimeException('User fehlt.');
    }
    $stmt = db()->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1');
    $stmt->execute([$targetUserId]);
    if (!is_array($stmt->fetch())) {
        throw new RuntimeException('User ist nicht aktiv.');
    }

    start_app_session();
    if (empty($_SESSION['impersonator_user_id'])) {
        $_SESSION['impersonator_user_id'] = (int) ($user['id'] ?? 0);
    }
    $_SESSION['user_id'] = $targetUserId;
    $nextUser = current_user() ?? [];
    return ['ok' => true, 'user' => array_merge($nextUser, ['permissions' => rpc_permissions($nextUser)])];
}

function rpc_admin_stop_impersonation(array $user): array
{
    start_app_session();
    $originalId = (int) ($_SESSION['impersonator_user_id'] ?? 0);
    if ($originalId <= 0) {
        return ['ok' => true, 'user' => array_merge($user, ['permissions' => rpc_permissions($user)])];
    }
    $_SESSION['user_id'] = $originalId;
    unset($_SESSION['impersonator_user_id']);
    $nextUser = current_user() ?? [];
    return ['ok' => true, 'user' => array_merge($nextUser, ['permissions' => rpc_permissions($nextUser)])];
}

function rpc_normalize_ids(mixed $ids): array
{
    if (!is_array($ids)) {
        return [];
    }

    $out = [];
    foreach ($ids as $id) {
        $value = rpc_str($id);
        if ($value !== '' && !in_array($value, $out, true)) {
            $out[] = $value;
        }
    }
    return array_slice($out, 0, 5000);
}

function rpc_contact_export_columns(mixed $columns): array
{
    $allowed = array_keys(rpc_contact_export_column_defs());
    $fallback = ['Name', 'ALTER', 'BIRTHDAY', 'JAHRGANG', 'HOME ADDRESS', 'PLZ / Ort', 'PHONE', 'MOBILE', 'EMAIL'];
    if (!is_array($columns) || !$columns) {
        return $fallback;
    }

    $selected = [];
    foreach ($columns as $column) {
        $key = rpc_str($column);
        if (in_array($key, $allowed, true) && !in_array($key, $selected, true)) {
            $selected[] = $key;
        }
    }
    return $selected ?: $fallback;
}

function rpc_contact_export_column_defs(): array
{
    return [
        'Name' => 'Name',
        'Preferred Name' => 'Preferred Name',
        'FIRSTNAME' => 'Vorname',
        'LASTNAME' => 'Nachname',
        'KATEGORIE' => 'Kategorie',
        'HOME ADDRESS' => 'Adresse',
        'PLZ / Ort' => 'PLZ / Ort',
        'HOME POSTCODE' => 'PLZ',
        'HOME CITY' => 'Ort',
        'PHONE' => 'Telefon',
        'MOBILE' => 'Mobile',
        'EMAIL' => 'E-Mail',
        'GENDER' => 'Geschlecht',
        'BIRTHDAY' => 'Geburtsdatum',
        'ALTER' => 'Alter',
        'JAHRGANG' => 'Jahrgang',
        'KLEINGRUPPE' => 'Kleingruppe',
        'IST IN KLEINGRUPPE' => 'Ist in Kleingruppe',
        'LEITET KLEINGRUPPE' => 'Leitet Kleingruppe',
        'BERUFE/AUSBILDUNGEN/SKILLS' => 'Beruf/Skills',
        'DEPARTMENTS' => 'Mitarbeit',
        'DATE ADDED' => 'Erfasst am',
    ];
}

function rpc_contact_export_label(string $key): string
{
    $defs = rpc_contact_export_column_defs();
    return $defs[$key] ?? $key;
}

function rpc_contact_export_rows(array $ids, array $columns): array
{
    if (!$ids || !$columns) {
        return [];
    }

    $people = rpc_people_by_ids($ids);
    $customValues = rpc_people_custom_values();
    $groupValues = rpc_people_group_values();
    $familyValues = rpc_people_family_values();
    $rows = [];

    foreach ($ids as $id) {
        if (!isset($people[$id])) {
            continue;
        }

        $person = $people[$id];
        $custom = $customValues[$id] ?? [];
        $groups = $groupValues[$id] ?? ['groups' => [], 'leads' => [], 'assists' => []];
        $family = $familyValues[$id] ?? [];
        $lite = rpc_contact_row($person, $custom, $groups, $family);
        $rows[] = array_map(
            static fn(string $column): string => rpc_contact_export_value($column, $person, $lite, $custom, $groups),
            $columns
        );
    }

    return $rows;
}

function rpc_people_by_ids(array $ids): array
{
    $out = [];
    foreach (array_chunk($ids, 300) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = db()->prepare("SELECT * FROM people WHERE id IN ({$placeholders})");
        $stmt->execute($chunk);
        foreach ($stmt as $row) {
            $id = rpc_str($row['id'] ?? '');
            if ($id !== '') {
                $out[$id] = $row;
            }
        }
    }
    return $out;
}

function rpc_contact_export_value(string $column, array $person, array $lite, array $custom, array $groups): string
{
    return match ($column) {
        'Name' => rpc_str($lite['displayName'] ?? ''),
        'Preferred Name' => rpc_str($lite['preferredName'] ?? ''),
        'FIRSTNAME' => rpc_str($lite['firstName'] ?? ''),
        'LASTNAME' => rpc_str($lite['lastName'] ?? ''),
        'KATEGORIE' => rpc_str($lite['category'] ?? ''),
        'HOME ADDRESS' => rpc_str($lite['address'] ?? ''),
        'PLZ / Ort' => trim(rpc_str($lite['postcode'] ?? '') . ' ' . rpc_str($lite['city'] ?? '')),
        'HOME POSTCODE' => rpc_str($lite['postcode'] ?? ''),
        'HOME CITY' => rpc_str($lite['city'] ?? ''),
        'PHONE' => rpc_str($lite['phone'] ?? ''),
        'MOBILE' => rpc_str($lite['mobile'] ?? ''),
        'EMAIL' => rpc_str($lite['email'] ?? ''),
        'GENDER' => rpc_str($lite['gender'] ?? ''),
        'BIRTHDAY' => rpc_ui_date($lite['birthday'] ?? ''),
        'ALTER' => ($lite['age'] ?? null) !== null ? (string) $lite['age'] : '',
        'JAHRGANG' => rpc_birth_year($lite['birthday'] ?? ''),
        'KLEINGRUPPE', 'IST IN KLEINGRUPPE' => implode(', ', $groups['groups'] ?? []),
        'LEITET KLEINGRUPPE' => implode(', ', $groups['leads'] ?? []),
        'BERUFE/AUSBILDUNGEN/SKILLS' => rpc_custom_value($custom, ['BERUFE/AUSBILDUNGEN/SKILLS', 'BERUFE / AUSBILDUNGEN / SKILLS', 'BERUF/SKILLS']),
        'DEPARTMENTS' => rpc_str($person['departments'] ?? ''),
        'DATE ADDED' => rpc_ui_date(substr(rpc_str($person['date_added'] ?? ''), 0, 10)),
        default => '',
    };
}

function rpc_custom_value(array $custom, array $keys): string
{
    foreach ($keys as $key) {
        $upper = strtoupper($key);
        if (rpc_str($custom[$upper] ?? '') !== '') {
            return rpc_str($custom[$upper]);
        }
    }
    return '';
}

function rpc_birth_year(mixed $birthday): string
{
    $raw = rpc_str($birthday);
    if ($raw === '' || $raw === '0000-00-00') {
        return '';
    }
    return substr($raw, 0, 4);
}

function rpc_build_csv(array $headers, array $rows): string
{
    $handle = fopen('php://temp', 'r+');
    if (!$handle) {
        return '';
    }

    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, $headers, ';', '"', "\\");
    foreach ($rows as $row) {
        fputcsv($handle, array_map(static fn(mixed $value): string => rpc_str($value), $row), ';', '"', "\\");
    }
    rewind($handle);
    $csv = stream_get_contents($handle) ?: '';
    fclose($handle);
    return $csv;
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

function rpc_empty_family(string $familyId): array
{
    return ['ok' => true, 'familyId' => $familyId, 'adults' => [], 'kids' => [], 'others' => []];
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

function rpc_empty_group(string $groupName): array
{
    return [
        'groupName' => $groupName,
        'leaderPersons' => [],
        'assistantPersons' => [],
        'memberPersons' => [],
    ];
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

function rpc_person_service_assignments(string $personId, string $startIso, string $endIso): array
{
    $personId = trim($personId);
    if ($personId === '') {
        return ['ok' => true, 'serviceIds' => [], 'assignments' => [], 'count' => 0];
    }

    $start = $startIso !== '' ? $startIso : (new DateTimeImmutable('today'))->format('Y-m-d');
    $end = $endIso !== '' ? $endIso : (new DateTimeImmutable($start))->modify('+180 days')->format('Y-m-d');

    $rows = fetch_all_prepared_legacy(
        'SELECT DISTINCT
            s.service_id, s.title, s.category, s.location, s.service_start, s.service_end,
            sv.team, sv.role, sv.status
         FROM service_volunteers sv
         INNER JOIN services s ON s.service_id = sv.service_id
         WHERE sv.person_id = ?
           AND DATE(s.service_start) <= ?
           AND DATE(COALESCE(s.service_end, s.service_start)) >= ?
         ORDER BY s.service_start, s.title, sv.team, sv.role',
        [$personId, $end, $start]
    );

    $serviceIds = [];
    $assignments = [];
    foreach ($rows as $row) {
        $serviceId = rpc_str($row['service_id'] ?? '');
        if ($serviceId === '') {
            continue;
        }

        rpc_add_unique($serviceIds, $serviceId);
        $assignments[] = [
            'serviceId' => $serviceId,
            'title' => rpc_str($row['title'] ?? ''),
            'category' => rpc_str($row['category'] ?? ''),
            'location' => rpc_str($row['location'] ?? ''),
            'date' => rpc_ui_date(substr(rpc_str($row['service_start'] ?? ''), 0, 10)),
            'startTime' => rpc_time(substr(rpc_str($row['service_start'] ?? ''), 11, 8)),
            'endTime' => rpc_time(substr(rpc_str($row['service_end'] ?? ''), 11, 8)),
            'team' => rpc_str($row['team'] ?? ''),
            'role' => rpc_str($row['role'] ?? ''),
            'status' => rpc_str($row['status'] ?? ''),
        ];
    }

    return [
        'ok' => true,
        'serviceIds' => $serviceIds,
        'assignments' => $assignments,
        'count' => count($assignments),
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
    $context = rpc_service_context($meta);
    $serviceId = $context['serviceId'];
    $service = rpc_fetch_service($serviceId);
    if (!$service) {
        return ['ok' => true, 'overview' => null];
    }
    $times = rpc_service_times($serviceId);
    $selectedTime = is_array($context['time'] ?? null) ? $context['time'] : null;
    $raw = rpc_decode_json_array($service['raw_json'] ?? null);
    $rehearsal = rpc_service_named_times($raw, ['rehearsal_times', 'rehearsals']);
    $other = rpc_service_named_times($raw, ['other_times']);
    $roleSummary = rpc_service_role_summary($serviceId, $context['timeId']);
    $overviewStart = rpc_str($selectedTime['starts_at'] ?? ($service['service_start'] ?? ''));
    $overviewEnd = rpc_str($selectedTime['ends_at'] ?? ($service['service_end'] ?? ''));

    return [
        'ok' => true,
        'overview' => [
            'serviceId' => $serviceId,
            'timeId' => $context['timeId'],
            'title' => rpc_str($service['title'] ?? ''),
            'category' => rpc_str($service['category'] ?? ''),
            'date' => rpc_ui_date(substr($overviewStart, 0, 10)),
            'startTime' => rpc_time(substr($overviewStart, 11, 8)),
            'endTime' => rpc_time(substr($overviewEnd, 11, 8)),
            'timeLabel' => $selectedTime ? rpc_service_time_label([$selectedTime]) : rpc_service_time_label($times),
            'rehearsalTimes' => implode(' · ', $rehearsal),
            'otherTimes' => implode(' · ', $other),
            'roleSummary' => $roleSummary,
        ],
    ];
}

function rpc_service_staff(mixed $meta): array
{
    $context = rpc_service_context($meta);
    $serviceId = $context['serviceId'];
    if ($serviceId === '') {
        return ['ok' => true, 'staffGroups' => [], 'staffCount' => 0];
    }
    $timeId = $context['timeId'];

    $rows = fetch_all_prepared_legacy(
        'SELECT sv.*
         FROM service_volunteers sv
         WHERE sv.service_id = ?
        ORDER BY sv.team, sv.role, sv.display_name',
        [$serviceId]
    );
    $rows = rpc_filter_service_rows_by_time($rows, $timeId);
    $rows = rpc_unique_service_rows($rows, ['team', 'role', 'person_id', 'display_name', 'status']);
    $groups = [];
    foreach ($rows as $row) {
        $label = rpc_str($row['team'] ?? '') ?: 'Team';
        $status = rpc_str($row['status'] ?? '');
        $statusTone = rpc_service_status_tone($status);
        $role = rpc_str($row['role'] ?? '');
        $groups[$label] ??= [];
        $groups[$label][] = [
            'groupLabel' => $label,
            'groupSortLabel' => $label,
            'subLabel' => rpc_service_sub_label($label),
            'teamName' => $label,
            'departmentName' => rpc_service_department_label($label),
            'subDepartmentName' => rpc_service_sub_label($label),
            'positionName' => $role,
            'personId' => rpc_str($row['person_id'] ?? ''),
            'name' => rpc_str($row['display_name'] ?? ''),
            'status' => $status,
            'statusTone' => $statusTone,
            'statusSortRank' => rpc_service_status_rank($statusTone),
            'positionSort' => rpc_service_position_sort($role),
            'email' => '',
            'phone' => '',
            'note' => '',
        ];
    }

    $staffGroups = [];
    foreach ($groups as $label => $items) {
        usort($items, static function (array $a, array $b): int {
            return ((int) ($a['positionSort'] ?? 9999)) <=> ((int) ($b['positionSort'] ?? 9999))
                ?: ((int) ($a['statusSortRank'] ?? 9)) <=> ((int) ($b['statusSortRank'] ?? 9))
                ?: strcasecmp(rpc_str($a['name'] ?? ''), rpc_str($b['name'] ?? ''));
        });
        $staffGroups[] = ['label' => $label, 'items' => $items];
    }
    return ['ok' => true, 'staffGroups' => $staffGroups, 'staffCount' => count($rows)];
}

function rpc_service_flow(mixed $meta): array
{
    $context = rpc_service_context($meta);
    $serviceId = $context['serviceId'];
    if ($serviceId === '') {
        return ['ok' => true, 'flow' => [], 'flowCount' => 0];
    }
    $timeId = $context['timeId'];

    $rows = fetch_all_prepared_legacy(
        'SELECT * FROM service_plan_items WHERE service_id = ? ORDER BY item_order',
        [$serviceId]
    );
    $rows = rpc_filter_service_rows_by_time($rows, $timeId);
    $rows = rpc_unique_service_rows($rows, ['title', 'duration_min', 'song_title', 'description', 'item_order']);
    $service = rpc_fetch_service($serviceId);
    $cursor = rpc_service_start_datetime_from_context($service, $context);
    $flow = array_map(static function (array $row) use (&$cursor): array {
        $title = rpc_str($row['title'] ?? '');
        $raw = rpc_decode_json_array($row['raw_json'] ?? null);
        $description = rpc_strip_tags(rpc_str($row['description'] ?? ''));
        $song = rpc_str($row['song_title'] ?? '');
        $songPayload = is_array($raw['song'] ?? null) ? $raw['song'] : [];
        $songId = rpc_str($songPayload['id'] ?? ($songPayload['song_id'] ?? ($raw['song_id'] ?? ($raw['songId'] ?? ''))));
        $duration = rpc_service_duration_minutes($row['duration_min'] ?? null);
        $explicitTime = rpc_time(substr((string) ($row['starts_at'] ?? ''), 11, 8));
        $time = $explicitTime;
        $hasComputedTime = false;
        if ($time === '' && $cursor instanceof DateTimeImmutable && $duration !== null && $duration > 0) {
            $time = $cursor->format('H:i');
            $hasComputedTime = true;
        }
        if ($cursor instanceof DateTimeImmutable && $duration !== null && $duration > 0) {
            $cursor = $cursor->modify('+' . $duration . ' minutes');
        }
        return [
            'title' => $title,
            'description' => $description,
            'song' => $song,
            'songId' => $songId,
            'note' => rpc_strip_tags(rpc_song_scalar($raw['note'] ?? ($raw['notes'] ?? ''))),
            'planDescription' => $description,
            'type' => rpc_song_scalar($raw['when'] ?? ($row['item_type'] ?? '')),
            'rawType' => rpc_str($row['item_type'] ?? ''),
            'time' => $time,
            'durationMin' => $duration !== null ? (string) $duration : '',
            'hasComputedTime' => $hasComputedTime,
            'isHeader' => ((int) ($raw['heading'] ?? 0)) === 1 || (($duration ?? 0) === 0 && $song === '' && $description === ''),
        ];
    }, $rows);

    return ['ok' => true, 'flow' => $flow, 'flowCount' => count($flow)];
}

function rpc_service_times(string $serviceId): array
{
    if ($serviceId === '') {
        return [];
    }
    return fetch_all_prepared_legacy(
        'SELECT * FROM service_times WHERE service_id = ? ORDER BY starts_at, label',
        [$serviceId]
    );
}

function rpc_service_time_label(array $times): string
{
    $labels = [];
    foreach ($times as $time) {
        if (!is_array($time)) {
            continue;
        }
        $label = rpc_str($time['label'] ?? '');
        $start = rpc_time(substr(rpc_str($time['starts_at'] ?? ''), 11, 8));
        $end = rpc_time(substr(rpc_str($time['ends_at'] ?? ''), 11, 8));
        $range = trim($start . ($end !== '' ? '-' . $end : ''));
        $text = trim(($label !== '' ? $label . ': ' : '') . $range);
        if ($text !== '') {
            $labels[] = $text;
        }
    }
    return implode(' · ', array_values(array_unique($labels)));
}

function rpc_service_named_times(array $service, array $keys): array
{
    $out = [];
    foreach ($keys as $key) {
        $times = rpc_service_extract_collection($service[$key] ?? null, ['time', 'rehearsal_time', 'other_time']);
        foreach ($times as $time) {
            if (!is_array($time)) {
                continue;
            }
            $label = rpc_str($time['name'] ?? ($time['label'] ?? ''));
            $start = rpc_time(substr(rpc_str($time['starts'] ?? ($time['start'] ?? '')), 11, 8));
            $end = rpc_time(substr(rpc_str($time['ends'] ?? ($time['end'] ?? '')), 11, 8));
            $range = trim($start . ($end !== '' ? '-' . $end : ''));
            $text = trim(($label !== '' ? $label . ': ' : '') . $range);
            if ($text !== '') {
                $out[] = $text;
            }
        }
    }
    return array_values(array_unique($out));
}

function rpc_service_extract_collection(mixed $value, array $innerKeys = []): array
{
    if ($value === null || $value === '') {
        return [];
    }
    if (!is_array($value)) {
        return [$value];
    }
    foreach ($innerKeys as $key) {
        if (array_key_exists($key, $value)) {
            return rpc_service_extract_collection($value[$key], []);
        }
    }
    return array_is_list($value) ? $value : [$value];
}

function rpc_service_role_summary(string $serviceId, string $timeId = ''): string
{
    if ($serviceId === '') {
        return '';
    }
    $rows = fetch_all_prepared_legacy(
        'SELECT team, raw_json FROM service_volunteers WHERE service_id = ? ORDER BY team',
        [$serviceId]
    );
    $rows = rpc_filter_service_rows_by_time($rows, $timeId);
    $counts = [];
    foreach ($rows as $row) {
        $team = rpc_str($row['team'] ?? '') ?: 'Team';
        $counts[$team] = ($counts[$team] ?? 0) + 1;
    }
    ksort($counts, SORT_NATURAL | SORT_FLAG_CASE);
    $parts = [];
    foreach ($counts as $team => $count) {
        if ($count > 0) {
            $parts[] = $team . ' (' . $count . ')';
        }
    }
    return implode(' · ', $parts);
}

function rpc_service_status_tone(string $status): string
{
    $value = strtolower($status);
    if ($value === '' || str_contains($value, 'open') || str_contains($value, 'unbesetzt')) {
        return 'open';
    }
    if (str_contains($value, 'confirm') || str_contains($value, 'best') || str_contains($value, 'zugesagt')) {
        return 'confirmed';
    }
    return 'other';
}

function rpc_service_status_rank(string $tone): int
{
    return ['open' => 0, 'other' => 1, 'confirmed' => 2][$tone] ?? 1;
}

function rpc_service_position_sort(string $role): int
{
    $role = strtolower($role);
    foreach ([
        'leiter' => 10,
        'leader' => 20,
        'pastor' => 30,
        'moderation' => 40,
        'worship' => 50,
        'vocal' => 60,
        'piano' => 70,
        'guitar' => 80,
        'bass' => 90,
        'drum' => 100,
        'audio' => 110,
        'video' => 120,
        'kamera' => 130,
        'licht' => 140,
    ] as $needle => $rank) {
        if (str_contains($role, $needle)) {
            return $rank;
        }
    }
    return 999;
}

function rpc_service_department_label(string $team): string
{
    $parts = array_map('trim', explode('/', $team, 2));
    return $parts[0] ?? $team;
}

function rpc_service_sub_label(string $team): string
{
    $parts = array_map('trim', explode('/', $team, 2));
    return $parts[1] ?? '';
}

function rpc_service_duration_minutes(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    $n = (int) $value;
    return $n > 180 ? (int) round($n / 60) : $n;
}

function rpc_service_start_datetime(?array $service): ?DateTimeImmutable
{
    $raw = rpc_str($service['service_start'] ?? '');
    if ($raw === '') {
        return null;
    }
    try {
        return new DateTimeImmutable($raw);
    } catch (Throwable) {
        return null;
    }
}

function rpc_service_start_datetime_from_context(?array $service, array $context): ?DateTimeImmutable
{
    $time = is_array($context['time'] ?? null) ? $context['time'] : null;
    $raw = rpc_str($time['starts_at'] ?? '');
    if ($raw !== '') {
        try {
            return new DateTimeImmutable($raw);
        } catch (Throwable) {
            // Fall back to the service start below.
        }
    }
    return rpc_service_start_datetime($service);
}

function rpc_service_id_from_meta(mixed $meta): string
{
    $meta = is_array($meta) ? $meta : [];
    $elvantoId = rpc_str($meta['elvantoId'] ?? '');
    return rpc_starts_with($elvantoId, 'SERVICE-') ? substr($elvantoId, 8) : rpc_str($meta['serviceId'] ?? '');
}

function rpc_service_context(mixed $meta): array
{
    $meta = is_array($meta) ? $meta : [];
    $serviceId = rpc_service_id_from_meta($meta);
    $times = rpc_service_times($serviceId);
    $timeId = rpc_str($meta['timeId'] ?? ($meta['serviceTimeId'] ?? ''));
    $selected = null;

    if ($timeId !== '') {
        foreach ($times as $time) {
            if (rpc_str($time['elvanto_time_id'] ?? '') === $timeId) {
                $selected = $time;
                break;
            }
        }
    }

    if (!$selected) {
        $eventStart = rpc_service_meta_start_datetime($meta);
        if ($eventStart instanceof DateTimeImmutable) {
            foreach ($times as $time) {
                try {
                    $timeStart = new DateTimeImmutable(rpc_str($time['starts_at'] ?? ''));
                } catch (Throwable) {
                    continue;
                }
                if ($timeStart->format('Y-m-d H:i') === $eventStart->format('Y-m-d H:i')) {
                    $selected = $time;
                    $timeId = rpc_str($time['elvanto_time_id'] ?? '');
                    break;
                }
            }
        }
    }

    if (!$selected && count($times) === 1) {
        $selected = $times[0];
        $timeId = rpc_str($selected['elvanto_time_id'] ?? '');
    }

    return [
        'serviceId' => $serviceId,
        'timeId' => $timeId,
        'time' => $selected,
    ];
}

function rpc_service_meta_start_datetime(array $meta): ?DateTimeImmutable
{
    $date = rpc_str($meta['StartDatum'] ?? ($meta['startDate'] ?? ''));
    $time = rpc_str($meta['StartZeit'] ?? ($meta['startTime'] ?? ''));
    if ($date === '' || $time === '') {
        return null;
    }
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $date, $m)) {
        $date = $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    try {
        return new DateTimeImmutable($date . ' ' . $time);
    } catch (Throwable) {
        return null;
    }
}

function rpc_filter_service_rows_by_time(array $rows, string $timeId): array
{
    if ($timeId === '') {
        return $rows;
    }
    $filtered = [];
    foreach ($rows as $row) {
        $raw = rpc_decode_json_array($row['raw_json'] ?? null);
        if (rpc_str($raw['_time_id'] ?? '') === $timeId) {
            $filtered[] = $row;
        }
    }
    return $filtered ?: $rows;
}

function rpc_unique_service_rows(array $rows, array $fields): array
{
    $out = [];
    $seen = [];
    foreach ($rows as $row) {
        $keyParts = [];
        foreach ($fields as $field) {
            $keyParts[] = rpc_lower(rpc_str($row[$field] ?? ''));
        }
        $raw = rpc_decode_json_array($row['raw_json'] ?? null);
        $keyParts[] = rpc_str($raw['_time_id'] ?? '');
        $key = implode('|', $keyParts);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $row;
    }
    return $out;
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
        $stmt = db()->query('SELECT song_id, title, artist, category, default_key_name, bpm, raw_json FROM songs ORDER BY title');
        foreach ($stmt as $row) {
            $songId = rpc_str($row['song_id'] ?? '');
            $title = rpc_str($row['title'] ?? '');
            $artist = rpc_str($row['artist'] ?? '');
            $category = rpc_str($row['category'] ?? '');
            $keyName = rpc_str($row['default_key_name'] ?? '');
            $bpm = rpc_str($row['bpm'] ?? '');
            $raw = rpc_decode_json_array($row['raw_json'] ?? null);
            if ($category === '') {
                $category = rpc_song_category_payload($raw);
            }
            $arrangements = rpc_song_arrangements_payload($raw, $title, $keyName, $bpm);
            $songAssets = rpc_song_assets_payload($raw);
            $pdfLinks = rpc_song_links_payload($raw, ['pdf']);
            $fileLinks = rpc_song_links_payload($raw, ['file', 'link']);
            $youtubeUrls = rpc_song_youtube_urls($raw);
            $keyNames = rpc_song_all_key_names($raw, $arrangements, $keyName);
            $songs[] = [
                'id' => $songId,
                'songId' => $songId,
                'title' => $title,
                'songTitle' => $title,
                'arrangementName' => rpc_str($arrangements[0]['arrangementName'] ?? $title),
                'artist' => $artist,
                'album' => rpc_song_first_field($raw, ['album', 'Album']),
                'category' => $category,
                'key' => $keyName,
                'keyName' => $keyNames[0] ?? $keyName,
                'keyNames' => $keyNames,
                'bpm' => $bpm,
                'ccliNumber' => rpc_song_first_field($raw, ['ccli_number', 'ccli', 'CCLI_Number']),
                'songStatus' => rpc_song_first_field($raw, ['status', 'song_status', 'Song_Status']),
                'youtubeUrl' => $youtubeUrls[0] ?? '',
                'youtubeUrls' => $youtubeUrls,
                'youtubeEmbeds' => rpc_song_youtube_embeds($raw),
                'pdfLinks' => $pdfLinks,
                'fileLinks' => $fileLinks,
                'assets' => $songAssets,
                'arrangements' => $arrangements,
                'arrangementsCount' => count($arrangements),
                'keysCount' => count($keyNames),
                'pdfLinksCount' => count($pdfLinks),
                'fileLinksCount' => count($fileLinks),
            ];
        }
    } catch (Throwable $e) {
        if (defined('APP_DEBUG') && APP_DEBUG) {
            return ['ok' => false, 'error' => $e->getMessage(), 'songs' => [], 'dataVersion' => rpc_data_version()];
        }
        $songs = [];
    }

    return ['ok' => true, 'songs' => $songs, 'dataVersion' => rpc_data_version()];
}

function rpc_decode_json_array(mixed $json): array
{
    if (!is_string($json) || trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function rpc_song_arrangements_payload(array $song, string $fallbackTitle, string $fallbackKey, string $fallbackBpm): array
{
    $rawArrangements = [];
    foreach ([['arrangements', 'arrangement'], ['arrangements'], ['song_arrangements', 'arrangement'], ['song_arrangements']] as $path) {
        $rawArrangements = rpc_song_path_list($song, $path);
        if ($rawArrangements) {
            break;
        }
    }

    if (!$rawArrangements) {
        $flatArrangementName = rpc_song_first_field($song, ['Arrangement_Name', 'arrangement_name']);
        $flatArrangementId = rpc_song_first_field($song, ['Arrangement_ID', 'arrangement_id']);
        $flatKey = rpc_song_first_field($song, ['Key_Name', 'Arrangement_Key_Name', 'key_name', 'key']);
        $flatBpm = rpc_song_first_field($song, ['Arrangement_BPM', 'bpm', 'tempo']);
        $rawArrangements = [[
            'id' => $flatArrangementId,
            'name' => $flatArrangementName !== '' ? $flatArrangementName : $fallbackTitle,
            'key_name' => $flatKey !== '' ? $flatKey : $fallbackKey,
            'bpm' => $flatBpm !== '' ? $flatBpm : $fallbackBpm,
            '__flat_song' => $song,
        ]];
    }

    $arrangements = [];
    foreach ($rawArrangements as $arrangement) {
        if (!is_array($arrangement)) {
            continue;
        }
        $flatSong = is_array($arrangement['__flat_song'] ?? null) ? $arrangement['__flat_song'] : [];
        unset($arrangement['__flat_song']);
        $source = $flatSong ?: $arrangement;
        $name = rpc_song_first_field($source, ['name', 'title', 'arrangement_name', 'Arrangement_Name']);
        if ($name === '') {
            $name = $fallbackTitle;
        }
        $keyNames = rpc_song_key_names($source, $fallbackKey);
        $assets = rpc_song_assets_payload($source);
        $pdfLinks = rpc_song_links_payload($source, ['pdf']);
        $fileLinks = rpc_song_links_payload($source, ['file', 'link']);
        $arrangements[] = [
            'arrangementId' => rpc_song_first_field($source, ['id', 'arrangement_id', 'Arrangement_ID']),
            'arrangementName' => $name !== '' ? $name : $fallbackTitle,
            'keyName' => $keyNames[0] ?? $fallbackKey,
            'keyNames' => $keyNames,
            'bpm' => rpc_song_first_field($source, ['bpm', 'tempo', 'Arrangement_BPM']) ?: $fallbackBpm,
            'minutes' => rpc_song_first_field($source, ['minutes', 'Arrangement_Minutes']),
            'seconds' => rpc_song_first_field($source, ['seconds', 'Arrangement_Seconds']),
            'keyMale' => rpc_song_first_field($source, ['key_male', 'Arrangement_Key_Male']),
            'keyFemale' => rpc_song_first_field($source, ['key_female', 'Arrangement_Key_Female']),
            'chordChartKey' => rpc_song_first_field($source, ['chord_chart_key', 'Chord_Chart_Key', 'key', 'Key']),
            'chordChart' => rpc_str($source['chord_chart'] ?? ($source['Chord_Chart'] ?? '')),
            'lyrics' => rpc_str($source['lyrics'] ?? ($source['Lyrics'] ?? '')),
            'copyright' => rpc_str($source['copyright'] ?? ($source['Copyright'] ?? '')),
            'sequence' => rpc_song_sequence($source),
            'pdfLinks' => $pdfLinks,
            'fileLinks' => $fileLinks,
            'assets' => $assets,
            'keyResources' => rpc_song_key_resources($source, $keyNames),
            'youtubeUrls' => rpc_song_youtube_urls($source),
            'youtubeEmbeds' => rpc_song_youtube_embeds($source),
        ];
    }

    return $arrangements;
}

function rpc_song_path_list(array $data, array $path): array
{
    $value = $data;
    foreach ($path as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return [];
        }
        $value = $value[$segment];
    }
    if ($value === null || $value === '') {
        return [];
    }
    if (!is_array($value)) {
        return [$value];
    }
    return array_is_list($value) ? $value : [$value];
}

function rpc_song_scalar(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_array($value)) {
        foreach (['name', 'title', 'label', 'value', 'key_starting', 'key', 'key_name'] as $key) {
            if (isset($value[$key]) && !is_array($value[$key])) {
                return rpc_song_scalar($value[$key]);
            }
        }
        return '';
    }
    return trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function rpc_song_category_payload(array $song): string
{
    $direct = rpc_song_first_field($song, ['category', 'category_name']);
    if ($direct !== '') {
        return $direct;
    }
    $names = [];
    foreach (rpc_song_path_list($song, ['categories', 'category']) as $category) {
        $name = rpc_song_scalar($category);
        if ($name !== '') {
            $names[] = $name;
        }
    }
    return implode(', ', array_values(array_unique($names)));
}

function rpc_song_first_field(array $data, array $fields): string
{
    foreach ($fields as $field) {
        $value = rpc_song_scalar($data[$field] ?? '');
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function rpc_song_split_list(mixed $value): array
{
    if (is_array($value)) {
        $out = [];
        foreach ($value as $item) {
            $scalar = rpc_song_scalar($item);
            if ($scalar !== '') {
                $out[] = $scalar;
            }
        }
        return array_values(array_unique($out));
    }
    return array_values(array_unique(array_filter(array_map(
        static fn(string $item): string => trim($item),
        preg_split('/[,;\n\r|]+/', rpc_song_scalar($value)) ?: []
    ))));
}

function rpc_song_key_names(array $arrangement, string $fallbackKey): array
{
    $keys = [];
    foreach (['key_name', 'key', 'default_key_name', 'Key_Name', 'Arrangement_Key_Name'] as $field) {
        $value = rpc_song_scalar($arrangement[$field] ?? '');
        if ($value !== '') {
            $keys[] = $value;
        }
    }
    foreach ([['keys', 'key'], ['keys']] as $path) {
        foreach (rpc_song_path_list($arrangement, $path) as $key) {
            $value = rpc_song_scalar($key);
            if ($value !== '') {
                $keys[] = $value;
            }
        }
    }
    if (!$keys && $fallbackKey !== '') {
        $keys[] = $fallbackKey;
    }
    return array_values(array_unique($keys));
}

function rpc_song_all_key_names(array $song, array $arrangements, string $fallbackKey): array
{
    $keys = rpc_song_key_names($song, $fallbackKey);
    foreach ($arrangements as $arrangement) {
        foreach (rpc_song_split_list($arrangement['keyNames'] ?? []) as $key) {
            $keys[] = $key;
        }
    }
    return array_values(array_unique(array_filter($keys)));
}

function rpc_song_sequence(array $arrangement): array
{
    $sequence = $arrangement['sequence'] ?? ($arrangement['Arrangement_Sequence'] ?? []);
    if (is_string($sequence)) {
        return array_values(array_filter(array_map('trim', preg_split('/[,;\n\r]+/', $sequence) ?: [])));
    }
    if (!is_array($sequence)) {
        return [];
    }
    $steps = array_is_list($sequence) ? $sequence : ($sequence['item'] ?? ($sequence['step'] ?? []));
    if (!is_array($steps)) {
        return [];
    }
    $out = [];
    foreach ((array_is_list($steps) ? $steps : [$steps]) as $step) {
        $value = rpc_song_scalar($step);
        if ($value !== '') {
            $out[] = $value;
        }
    }
    return $out;
}

function rpc_song_youtube_url(array $song): string
{
    return rpc_song_youtube_urls($song)[0] ?? '';
}

function rpc_song_youtube_urls(array $song): array
{
    $urls = [];
    foreach (['youtube_url', 'youtubeUrl', 'youtube', 'youtube_embed', 'youtubeEmbed', 'YouTube_URL', 'YouTube_Embed', 'Song_YouTube_URLs', 'Arrangement_YouTube_URLs'] as $field) {
        $parts = rpc_song_split_list($song[$field] ?? '');
        foreach ($parts as $value) {
            if ($value !== '' && preg_match('/(youtube(?:-nocookie)?\.com|youtu\.be)/i', $value)) {
                $urls[] = $value;
            }
        }
        $value = !$parts ? rpc_song_scalar($song[$field] ?? '') : '';
        if ($value !== '' && preg_match('/(youtube(?:-nocookie)?\.com|youtu\.be)/i', $value)) {
            $urls[] = $value;
        }
    }
    foreach (rpc_song_collect_urls($song) as $url) {
        if (preg_match('/(youtube(?:-nocookie)?\.com|youtu\.be)/i', $url)) {
            $urls[] = $url;
        }
    }
    return array_values(array_unique($urls));
}

function rpc_song_youtube_embeds(array $song): array
{
    $embeds = [];
    foreach (['youtube_embed', 'youtubeEmbed', 'YouTube_Embed', 'Song_YouTube_Embeds', 'Arrangement_YouTube_Embeds'] as $field) {
        foreach (rpc_song_split_list($song[$field] ?? '') as $value) {
            if (preg_match('/(youtube(?:-nocookie)?\.com|youtu\.be)/i', $value)) {
                $embeds[] = $value;
            }
        }
    }
    return array_values(array_unique($embeds));
}

function rpc_song_links_payload(array $song, array $kinds): array
{
    $urls = [];
    foreach (rpc_song_url_fields($song) as $url) {
        $urls[] = $url;
    }
    foreach (rpc_song_collect_urls($song) as $url) {
        $urls[] = $url;
    }
    $filtered = [];
    foreach (array_values(array_unique($urls)) as $url) {
        $isPdf = (bool) preg_match('/\.pdf(\?|$)/i', $url);
        if (in_array('pdf', $kinds, true) && $isPdf) {
            $filtered[] = $url;
            continue;
        }
        if (!$isPdf && (in_array('file', $kinds, true) || in_array('link', $kinds, true))) {
            $filtered[] = $url;
        }
    }
    return array_values(array_unique($filtered));
}

function rpc_song_assets_payload(array $song): array
{
    $assets = [];
    foreach (['Song', 'Arrangement', 'Key'] as $prefix) {
        $assets = array_merge($assets, rpc_song_assets_from_prefixed_fields($song, $prefix));
    }
    $assets = array_merge($assets, rpc_song_assets_from_native_files($song));
    $knownUrls = [];
    foreach ($assets as $asset) {
        if (is_array($asset)) {
            $url = rpc_song_scalar($asset['url'] ?? '');
            if ($url !== '') {
                $knownUrls[$url] = true;
            }
        }
    }
    foreach (array_values(array_unique(array_merge(rpc_song_url_fields($song), rpc_song_collect_urls($song)))) as $url) {
        if (isset($knownUrls[$url])) {
            continue;
        }
        if (preg_match('/(youtube(?:-nocookie)?\.com|youtu\.be)/i', $url)) {
            continue;
        }
        $assets[] = [
            'url' => $url,
            'name' => basename(parse_url($url, PHP_URL_PATH) ?: $url),
            'type' => preg_match('/\.pdf(\?|$)/i', $url) ? 'PDF' : '',
            'mode' => '',
            'embed' => '',
        ];
    }
    return rpc_song_unique_assets($assets);
}

function rpc_song_assets_from_native_files(array $song): array
{
    $files = [];
    foreach ([['files', 'file'], ['files']] as $path) {
        foreach (rpc_song_path_list($song, $path) as $file) {
            if (is_array($file)) {
                $files[] = $file;
            }
        }
    }

    $assets = [];
    foreach ($files as $file) {
        $content = rpc_song_scalar($file['content'] ?? ($file['url'] ?? ($file['link'] ?? '')));
        $title = rpc_song_first_field($file, ['title', 'name', 'label']);
        $type = rpc_song_first_field($file, ['type', 'kind']);
        $html = rpc_song_scalar($file['html'] ?? '') === '1';
        $url = preg_match('/^https?:\/\//i', $content) ? $content : '';
        $embed = ($html && $content !== '') ? $content : '';
        if ($url === '' && $embed === '') {
            $collected = rpc_song_collect_urls($content);
            $url = $collected[0] ?? '';
        }
        if ($url === '' && $embed === '') {
            continue;
        }
        $fallbackName = $url !== '' ? basename(parse_url($url, PHP_URL_PATH) ?: $url) : $type;
        $assets[] = [
            'name' => $title !== '' ? $title : $fallbackName,
            'type' => $type,
            'mode' => '',
            'url' => $url,
            'embed' => $embed,
        ];
    }

    return $assets;
}

function rpc_song_url_fields(array $song): array
{
    $urls = [];
    foreach ([
        'url', 'file_url', 'download_url', 'embed', 'link',
        'Song_PDF_Links', 'Song_File_Links', 'Song_File_URLs', 'Song_File_Embeds',
        'Arrangement_PDF_Links', 'Arrangement_File_Links', 'Arrangement_File_URLs', 'Arrangement_File_Embeds',
        'Key_PDF_Links', 'Key_Elvanto_PDF_URL', 'Key_File_Links', 'Key_File_URLs', 'Key_File_Embeds',
    ] as $field) {
        foreach (rpc_song_split_list($song[$field] ?? '') as $value) {
            if (preg_match('/^https?:\/\//i', $value)) {
                $urls[] = $value;
            }
        }
    }
    return array_values(array_unique($urls));
}

function rpc_song_assets_from_prefixed_fields(array $song, string $prefix): array
{
    $names = rpc_song_split_list($song[$prefix . '_File_Names'] ?? '');
    $types = rpc_song_split_list($song[$prefix . '_File_Types'] ?? '');
    $modes = rpc_song_split_list($song[$prefix . '_File_Modes'] ?? '');
    $urls = rpc_song_split_list($song[$prefix . '_File_URLs'] ?? '');
    $embeds = rpc_song_split_list($song[$prefix . '_File_Embeds'] ?? '');
    $links = rpc_song_split_list($song[$prefix . '_File_Links'] ?? '');
    $pdfs = rpc_song_split_list($song[$prefix . '_PDF_Links'] ?? '');
    $count = max(count($names), count($types), count($modes), count($urls), count($embeds), count($links), count($pdfs));
    $assets = [];
    for ($i = 0; $i < $count; $i++) {
        $url = $urls[$i] ?? ($links[$i] ?? ($pdfs[$i] ?? ''));
        $embed = $embeds[$i] ?? '';
        if ($url === '' && $embed === '') {
            continue;
        }
        $assets[] = [
            'name' => $names[$i] ?? basename(parse_url($url ?: $embed, PHP_URL_PATH) ?: ($url ?: $embed)),
            'type' => $types[$i] ?? (preg_match('/\.pdf(\?|$)/i', $url) ? 'PDF' : ''),
            'mode' => $modes[$i] ?? '',
            'url' => $url,
            'embed' => $embed,
        ];
    }
    return $assets;
}

function rpc_song_unique_assets(array $assets): array
{
    $out = [];
    $seen = [];
    foreach ($assets as $asset) {
        if (!is_array($asset)) {
            continue;
        }
        $url = rpc_song_scalar($asset['url'] ?? '');
        $embed = rpc_song_scalar($asset['embed'] ?? '');
        if ($url === '' && $embed === '') {
            continue;
        }
        $item = [
            'name' => rpc_song_scalar($asset['name'] ?? ''),
            'type' => rpc_song_scalar($asset['type'] ?? ''),
            'mode' => rpc_song_scalar($asset['mode'] ?? ''),
            'url' => $url,
            'embed' => $embed,
        ];
        $key = strtolower(implode('|', $item));
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $item;
    }
    return $out;
}

function rpc_song_key_resources(array $source, array $keyNames): array
{
    $resources = [];
    $labels = $keyNames ?: rpc_song_split_list($source['Key_Name'] ?? '');
    foreach ($labels as $label) {
        $key = rpc_song_scalar($label);
        if ($key === '') {
            continue;
        }
        $keyResources = [
            'pdfLinks' => rpc_song_links_payload($source, ['pdf']),
            'fileLinks' => rpc_song_links_payload($source, ['file', 'link']),
            'assets' => rpc_song_assets_from_prefixed_fields($source, 'Key'),
            'elvantoPdfUrl' => rpc_song_first_field($source, ['Key_Elvanto_PDF_URL']),
        ];
        $resources[$key] = $keyResources;
    }
    foreach ([['keys', 'key'], ['keys']] as $path) {
        foreach (rpc_song_path_list($source, $path) as $keyPayload) {
            if (!is_array($keyPayload)) {
                continue;
            }
            $label = rpc_song_first_field($keyPayload, ['name', 'title', 'key_name', 'Key_Name', 'key', 'key_starting']);
            if ($label === '') {
                continue;
            }
            $resources[$label] ??= ['pdfLinks' => [], 'fileLinks' => [], 'assets' => [], 'elvantoPdfUrl' => ''];
            $resources[$label]['pdfLinks'] = array_values(array_unique(array_merge($resources[$label]['pdfLinks'], rpc_song_links_payload($keyPayload, ['pdf']))));
            $resources[$label]['fileLinks'] = array_values(array_unique(array_merge($resources[$label]['fileLinks'], rpc_song_links_payload($keyPayload, ['file', 'link']))));
            $resources[$label]['assets'] = rpc_song_unique_assets(array_merge($resources[$label]['assets'], rpc_song_assets_payload($keyPayload)));
            if ($resources[$label]['elvantoPdfUrl'] === '') {
                $resources[$label]['elvantoPdfUrl'] = rpc_song_first_field($keyPayload, ['pdf_url', 'elvanto_pdf_url', 'Key_Elvanto_PDF_URL']);
            }
        }
    }
    return $resources;
}

function rpc_song_collect_urls(mixed $value): array
{
    $urls = [];
    if (is_string($value)) {
        if (preg_match_all('/https?:\/\/[^\s<>"\']+/i', $value, $matches)) {
            $urls = array_merge($urls, $matches[0]);
        }
        return $urls;
    }
    if (!is_array($value)) {
        return [];
    }
    foreach ($value as $child) {
        $urls = array_merge($urls, rpc_song_collect_urls($child));
    }
    return array_values(array_unique($urls));
}

function rpc_contact_filters(): array
{
    $filters = [];
    $seen = [];

    foreach (rpc_people_custom_values() as $custom) {
        foreach (rpc_split_leadership_values($custom['LEITERSCHAFT'] ?? '') as $value) {
            $seen[$value] = true;
        }
    }

    $hasKg = false;
    foreach (rpc_people_group_values() as $groupInfo) {
        if (($groupInfo['leads'] ?? []) || ($groupInfo['assists'] ?? [])) {
            $hasKg = true;
            break;
        }
    }
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
            $filters[] = ['key' => $key, 'label' => $key, 'type' => 'leadership'];
        }
    }

    return $filters;
}

function rpc_dashboard(array $user = []): array
{
    $contacts = rpc_contacts_lite($user)['contacts'] ?? [];
    $gemeinde = array_values(array_filter($contacts, static fn(array $c): bool => rpc_is_category($c, 'gemeinde')));
    $kontakte = array_values(array_filter($contacts, static fn(array $c): bool => rpc_is_category($c, 'kontakte')));
    $adults = array_values(array_filter($gemeinde, static fn(array $c): bool => !($c['isChild'] ?? false)));

    $new14 = rpc_count_contacts($gemeinde, static fn(array $c): bool => (bool) ($c['new14'] ?? false));
    $new3 = rpc_count_contacts($gemeinde, static fn(array $c): bool => (bool) ($c['new3'] ?? false));
    $new6 = rpc_count_contacts($gemeinde, static fn(array $c): bool => (bool) ($c['new6'] ?? false));
    $new12 = rpc_count_contacts($gemeinde, static fn(array $c): bool => (bool) ($c['new12'] ?? false));

    $last14 = rpc_people_added_between('-1 year -14 days', '-1 year');
    $last3 = rpc_people_added_between('-1 year -3 months', '-1 year');
    $last6 = rpc_people_added_between('-1 year -6 months', '-1 year');
    $last12 = rpc_people_added_between('-2 years', '-1 year');

    $householdMap = [];
    $activeHouseholdIds = [];
    foreach ($contacts as $contact) {
        $familyId = rpc_str($contact['familyId'] ?? '');
        $personId = rpc_str($contact['id'] ?? '');
        $key = $familyId !== '' ? $familyId : ($personId !== '' ? 'einzel_' . $personId : '');
        if ($key === '') {
            continue;
        }
        $householdMap[$key] ??= ['adults' => 0, 'kids' => 0, 'count' => 0];
        if ($contact['isChild'] ?? false) {
            $householdMap[$key]['kids']++;
        } else {
            $householdMap[$key]['adults']++;
        }
        $householdMap[$key]['count']++;

        if (rpc_is_category($contact, 'gemeinde') && (($contact['isFamilyMain'] ?? false) || ($contact['isSingle'] ?? false))) {
            $activeHouseholdIds[$key] = true;
        }
    }

    $householdDetail = [];
    $familyHouseholds = 0;
    $singleHouseholds = 0;
    foreach ($householdMap as $key => $item) {
        if (!isset($activeHouseholdIds[$key])) {
            continue;
        }
        $adultsCount = (int) $item['adults'];
        $kidsCount = (int) $item['kids'];
        if ((int) $item['count'] === 1 && $adultsCount === 1 && $kidsCount === 0) {
            $singleHouseholds++;
        } else {
            $familyHouseholds++;
        }
        $typeKey = "{$adultsCount}_{$kidsCount}";
        $householdDetail[$typeKey] ??= ['key' => $typeKey, 'adults' => $adultsCount, 'kids' => $kidsCount, 'count' => 0];
        $householdDetail[$typeKey]['count']++;
    }

    $nextStepCounts = rpc_value_counts($adults, 'nextStepValues');
    $kidsCounts = rpc_value_counts($gemeinde, 'kidsChurchValues');
    $ypgCounts = rpc_value_counts($gemeinde, 'youthYpgValues');
    $ministryBuckets = [
        ['label' => 'Keine Teams', 'count' => 0, 'filterKey' => 'ministry_teams_count:0', 'filterLabel' => 'Keine Teams'],
        ['label' => '1 Team', 'count' => 0, 'filterKey' => 'ministry_teams_count:1', 'filterLabel' => '1 Team'],
        ['label' => '2 Teams', 'count' => 0, 'filterKey' => 'ministry_teams_count:2', 'filterLabel' => '2 Teams'],
        ['label' => '3+ Teams', 'count' => 0, 'filterKey' => 'ministry_teams_count:3plus', 'filterLabel' => '3+ Teams'],
    ];
    foreach ($adults as $contact) {
        $count = count(is_array($contact['departmentsValues'] ?? null) ? $contact['departmentsValues'] : []);
        $idx = $count >= 3 ? 3 : $count;
        $ministryBuckets[$idx]['count']++;
    }

    return [
        'peopleCount' => count($contacts),
        'eventsCount' => (int) db()->query('SELECT COUNT(*) AS c FROM calendar_events')->fetch()['c'],
        'serviceCount' => (int) db()->query('SELECT COUNT(*) AS c FROM services')->fetch()['c'],
        'active' => count($gemeinde),
        'activeGemeinde' => count($gemeinde),
        'activeContacts' => count($kontakte),
        'new14' => $new14,
        'new3' => $new3,
        'new6' => $new6,
        'new12' => $new12,
        'new14LastYear' => $last14,
        'new3LastYear' => $last3,
        'new6LastYear' => $last6,
        'new12LastYear' => $last12,
        'new14VsLastYearPct' => rpc_percent_delta($new14, $last14),
        'new3VsLastYearPct' => rpc_percent_delta($new3, $last3),
        'new6VsLastYearPct' => rpc_percent_delta($new6, $last6),
        'new12VsLastYearPct' => rpc_percent_delta($new12, $last12),
        'trend14VsLastYear' => rpc_trend($new14, $last14),
        'trend3VsLastYear' => rpc_trend($new3, $last3),
        'trend6VsLastYear' => rpc_trend($new6, $last6),
        'trend12VsLastYear' => rpc_trend($new12, $last12),
        'newcomer' => [
            'current14' => $new14,
            'compare14LastYear' => $last14,
            'current3' => $new3,
            'compare3LastYear' => $last3,
            'current6' => $new6,
            'compare6LastYear' => $last6,
            'current12' => $new12,
            'compare12LastYear' => $last12,
        ],
        'birthday' => [
            'today' => rpc_count_contacts($gemeinde, static fn(array $c): bool => (bool) ($c['birthdayToday'] ?? false)),
            'week' => rpc_count_contacts($gemeinde, static fn(array $c): bool => (bool) ($c['birthdayWeek'] ?? false)),
            'month' => rpc_count_contacts($gemeinde, static fn(array $c): bool => (bool) ($c['birthdayMonthFlag'] ?? false)),
        ],
        'male' => rpc_count_contacts($gemeinde, static fn(array $c): bool => rpc_gender_kind(rpc_str($c['gender'] ?? '')) === 'male'),
        'female' => rpc_count_contacts($gemeinde, static fn(array $c): bool => rpc_gender_kind(rpc_str($c['gender'] ?? '')) === 'female'),
        'families' => $familyHouseholds,
        'singles' => $singleHouseholds,
        'households' => $familyHouseholds + $singleHouseholds,
        'householdDetail' => array_values($householdDetail),
        'age0_20' => rpc_age_range_count($gemeinde, 0, 20),
        'age21_40' => rpc_age_range_count($gemeinde, 21, 40),
        'age41_60' => rpc_age_range_count($gemeinde, 41, 60),
        'age61_100' => rpc_age_range_count($gemeinde, 61, 100),
        'age0_5' => rpc_age_range_count($gemeinde, 0, 5),
        'age6_10' => rpc_age_range_count($gemeinde, 6, 10),
        'age11_15' => rpc_age_range_count($gemeinde, 11, 15),
        'age16_20' => rpc_age_range_count($gemeinde, 16, 20),
        'age21_25' => rpc_age_range_count($gemeinde, 21, 25),
        'age26_30' => rpc_age_range_count($gemeinde, 26, 30),
        'age31_35' => rpc_age_range_count($gemeinde, 31, 35),
        'age36_40' => rpc_age_range_count($gemeinde, 36, 40),
        'age41_45' => rpc_age_range_count($gemeinde, 41, 45),
        'age46_50' => rpc_age_range_count($gemeinde, 46, 50),
        'age51_55' => rpc_age_range_count($gemeinde, 51, 55),
        'age56_60' => rpc_age_range_count($gemeinde, 56, 60),
        'age61_65' => rpc_age_range_count($gemeinde, 61, 65),
        'age66_70' => rpc_age_range_count($gemeinde, 66, 70),
        'age71_75' => rpc_age_range_count($gemeinde, 71, 75),
        'age76_100' => rpc_age_range_count($gemeinde, 76, 100),
        'nextStep' => [
            'items' => array_map(
                static fn(array $item): array => $item + ['notVisitedCount' => max(0, count($adults) - (int) $item['count'])],
                rpc_counts_to_items($nextStepCounts)
            ),
            'kgMembersTotal' => rpc_count_contacts($adults, static fn(array $c): bool => (bool) ($c['hasKg'] ?? false)),
            'kgMembersMissingTotal' => rpc_count_contacts($adults, static fn(array $c): bool => !($c['hasKg'] ?? false)),
            'kgLeadersTotal' => rpc_count_contacts($adults, static fn(array $c): bool => (bool) ($c['leadsKg'] ?? false)),
            'kgLeadersMissingTotal' => rpc_count_contacts($adults, static fn(array $c): bool => !($c['leadsKg'] ?? false)),
            'ministryTeamBuckets' => $ministryBuckets,
        ],
        'childrenYouth' => [
            'kidsChurch' => [
                'total' => rpc_count_contacts($gemeinde, static fn(array $c): bool => count(is_array($c['kidsChurchValues'] ?? null) ? $c['kidsChurchValues'] : []) > 0),
                'items' => rpc_counts_to_items($kidsCounts),
            ],
            'youthYpg' => [
                'total' => rpc_count_contacts($gemeinde, static fn(array $c): bool => count(is_array($c['youthYpgValues'] ?? null) ? $c['youthYpgValues'] : []) > 0),
                'items' => rpc_counts_to_items($ypgCounts),
            ],
        ],
    ];
}

function rpc_load_user_smart_filters(array $user): array
{
    $userId = (int) ($user['id'] ?? 0);
    if ($userId <= 0) {
        return ['ok' => true, 'email' => rpc_str($user['email'] ?? ''), 'found' => false, 'activeSmartRules' => [], 'smartFilterHistory' => []];
    }

    $stmt = db()->prepare('SELECT payload_json FROM user_smart_filters WHERE user_id = ? AND filter_key = ? LIMIT 1');
    $stmt->execute([$userId, 'default']);
    $payload = $stmt->fetchColumn();
    if (!is_string($payload) || trim($payload) === '') {
        return ['ok' => true, 'email' => rpc_str($user['email'] ?? ''), 'found' => false, 'activeSmartRules' => [], 'smartFilterHistory' => []];
    }

    $decoded = json_decode($payload, true);
    if (!is_array($decoded)) {
        return ['ok' => true, 'email' => rpc_str($user['email'] ?? ''), 'found' => false, 'activeSmartRules' => [], 'smartFilterHistory' => []];
    }

    return [
        'ok' => true,
        'email' => rpc_str($user['email'] ?? ''),
        'found' => true,
        'activeSmartRules' => is_array($decoded['activeSmartRules'] ?? null) ? $decoded['activeSmartRules'] : [],
        'smartFilterHistory' => is_array($decoded['smartFilterHistory'] ?? null) ? array_slice($decoded['smartFilterHistory'], 0, 10) : [],
    ];
}

function rpc_save_user_smart_filters(mixed $payload, array $user): array
{
    $userId = (int) ($user['id'] ?? 0);
    if ($userId <= 0 || !is_array($payload)) {
        return ['ok' => false, 'error' => 'Smart-Filter konnten nicht gespeichert werden.'];
    }

    $safePayload = [
        'activeSmartRules' => is_array($payload['activeSmartRules'] ?? null) ? $payload['activeSmartRules'] : [],
        'smartFilterHistory' => is_array($payload['smartFilterHistory'] ?? null) ? array_slice($payload['smartFilterHistory'], 0, 10) : [],
    ];
    $json = json_encode($safePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return ['ok' => false, 'error' => 'Smart-Filter konnten nicht serialisiert werden.'];
    }

    $stmt = db()->prepare(
        'INSERT INTO user_smart_filters (user_id, filter_key, payload_json)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE payload_json = VALUES(payload_json), updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$userId, 'default', $json]);
    return ['ok' => true];
}

function rpc_prayer_deck(array $user = []): array
{
    $contacts = array_values(array_filter(
        rpc_contacts_lite($user)['contacts'] ?? [],
        static fn(array $c): bool => rpc_prayer_contact_allowed($c)
    ));
    $groups = [];
    $relationshipByPersonId = [];
    $relationRows = db()->query('SELECT person_id, relationship FROM family_members')->fetchAll();
    foreach ($relationRows as $row) {
        $personId = rpc_str($row['person_id'] ?? '');
        if ($personId !== '') {
            $relationshipByPersonId[$personId] = rpc_str($row['relationship'] ?? '');
        }
    }

    foreach ($contacts as $contact) {
        $personId = rpc_str($contact['id'] ?? '');
        if ($personId === '') {
            continue;
        }
        $familyId = rpc_str($contact['familyId'] ?? '');
        $groupId = $familyId !== '' ? $familyId : 'einzel_' . $personId;
        $groups[$groupId] ??= [];
        $groups[$groupId][] = $contact;
    }

    $cards = [];
    foreach ($groups as $familyId => $members) {
        $familyKey = rpc_str($familyId);
        usort($members, static function (array $a, array $b): int {
            return strcasecmp(rpc_str($a['displayName'] ?? ''), rpc_str($b['displayName'] ?? ''));
        });
        $isSingle = rpc_starts_with(rpc_lower($familyKey), 'einzel_') || count($members) <= 1;
        $fallbackName = rpc_str($members[0]['displayName'] ?? 'Einzelperson');
        $lastNames = [];
        foreach ($members as $member) {
            $last = rpc_str($member['lastName'] ?? '');
            if ($last !== '' && !in_array($last, $lastNames, true)) {
                $lastNames[] = $last;
            }
        }
        $primaryLast = $lastNames[0] ?? '';
        $familyTitle = $lastNames ? 'Familie ' . implode(' ', $lastNames) : 'Familie';
        $title = $isSingle ? $fallbackName : $familyTitle;
        $payloadMembers = [];
        foreach ($members as $member) {
            $personId = rpc_str($member['id'] ?? '');
            $relationship = $relationshipByPersonId[$personId] ?? '';
            $role = rpc_prayer_member_role($relationship, $isSingle);
            $member['role'] = $role;
            $member['isChild'] = $role === 'Kind';
            $payload = rpc_prayer_member_payload($member);
            if (rpc_str($payload['name'] ?? '') !== '') {
                $payloadMembers[] = $payload;
            }
        }
        if (!$payloadMembers) {
            continue;
        }
        $cards[] = [
            'id' => $familyKey,
            'type' => $isSingle ? 'single' : 'family',
            'title' => $title,
            'sortLastName' => $primaryLast ?: rpc_str($members[0]['lastName'] ?? ''),
            'sortFirstName' => rpc_str($members[0]['firstName'] ?? ($members[0]['preferredName'] ?? '')),
            'sortKey' => rpc_str($primaryLast ?: $fallbackName ?: $title),
            'location' => rpc_contact_location($members[0] ?? []),
            'members' => $payloadMembers,
        ];
    }

    usort($cards, static function (array $a, array $b): int {
        $lastCmp = strcasecmp(rpc_str($a['sortLastName'] ?? ''), rpc_str($b['sortLastName'] ?? ''));
        if ($lastCmp !== 0) {
            return $lastCmp;
        }
        $firstCmp = strcasecmp(rpc_str($a['sortFirstName'] ?? ''), rpc_str($b['sortFirstName'] ?? ''));
        if ($firstCmp !== 0) {
            return $firstCmp;
        }
        return strcasecmp(rpc_str($a['title'] ?? ''), rpc_str($b['title'] ?? ''));
    });
    return $cards;
}

function rpc_prayer_deck_by_pool(string $poolName, array $user = []): array
{
    $personIds = array_flip(array_map(
        static fn(array $m): string => rpc_str($m['personId'] ?? ''),
        rpc_prayer_pool_members($poolName)
    ));
    if (!$personIds) {
        return [];
    }

    return array_values(array_filter(rpc_prayer_deck($user), static function (array $card) use ($personIds): bool {
        foreach (($card['members'] ?? []) as $member) {
            if (isset($personIds[rpc_str($member['personId'] ?? '')])) {
                return true;
            }
        }
        return false;
    }));
}

function rpc_prayer_pools(array $user = []): array
{
    rpc_ensure_prayer_pool('Kranke');
    $rows = db()->query(
        'SELECT pp.pool_name, COUNT(ppm.person_id) AS members_count
         FROM prayer_pools pp
         LEFT JOIN prayer_pool_members ppm ON ppm.pool_id = pp.id
         GROUP BY pp.id, pp.pool_name
         ORDER BY pp.pool_name'
    )->fetchAll();

    return array_map(static function (array $row): array {
        $poolName = rpc_str($row['pool_name'] ?? '');
        return [
            'name' => $poolName,
            'membersCount' => (int) ($row['members_count'] ?? 0),
            'cardsCount' => count(rpc_prayer_deck_by_pool($poolName, $user)),
        ];
    }, $rows);
}

function rpc_prayer_pool_members(string $poolName): array
{
    $poolId = rpc_ensure_prayer_pool($poolName ?: 'Kranke');
    $rows = fetch_all_prepared_legacy(
        'SELECT p.id, p.display_name, p.firstname, p.preferred_name, p.lastname, p.picture_url
         FROM prayer_pool_members ppm
         INNER JOIN people p ON p.id = ppm.person_id
         WHERE ppm.pool_id = ?
         ORDER BY p.lastname, p.firstname',
        [$poolId]
    );

    return array_map(static function (array $row): array {
        $name = rpc_str($row['display_name'] ?? '') ?: trim((rpc_str($row['preferred_name'] ?? '') ?: rpc_str($row['firstname'] ?? '')) . ' ' . rpc_str($row['lastname'] ?? ''));
        return [
            'personId' => rpc_str($row['id'] ?? ''),
            'name' => $name,
            'picture' => rpc_str($row['picture_url'] ?? ''),
        ];
    }, $rows);
}

function rpc_prayer_pool_create(mixed $payload, array $user): array
{
    $poolName = rpc_str(is_array($payload) ? ($payload['poolName'] ?? '') : $payload);
    if ($poolName === '') {
        return ['ok' => false, 'reason' => 'empty_name'];
    }
    $existing = rpc_prayer_pool_id($poolName);
    if ($existing > 0) {
        return ['ok' => false, 'reason' => 'pool_exists', 'poolName' => $poolName];
    }

    $stmt = db()->prepare('INSERT INTO prayer_pools (pool_name, created_by_email) VALUES (?, ?)');
    $stmt->execute([$poolName, rpc_str($user['email'] ?? '')]);
    return ['ok' => true, 'poolName' => $poolName];
}

function rpc_prayer_pool_delete(string $poolName): array
{
    if (rpc_lower($poolName) === 'kranke') {
        return ['ok' => false, 'reason' => 'protected_default_pool'];
    }
    $poolId = rpc_prayer_pool_id($poolName);
    if ($poolId <= 0) {
        return ['ok' => true];
    }
    $stmt = db()->prepare('DELETE FROM prayer_pools WHERE id = ?');
    $stmt->execute([$poolId]);
    return ['ok' => true];
}

function rpc_prayer_pool_add_members(mixed $payload): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'reason' => 'invalid_payload'];
    }
    $poolId = rpc_ensure_prayer_pool(rpc_str($payload['poolName'] ?? 'Kranke') ?: 'Kranke');
    $personIds = is_array($payload['personIds'] ?? null) ? $payload['personIds'] : [];
    $stmt = db()->prepare('INSERT IGNORE INTO prayer_pool_members (pool_id, person_id) VALUES (?, ?)');
    $added = 0;
    foreach ($personIds as $personId) {
        $id = rpc_str($personId);
        if ($id === '') {
            continue;
        }
        $stmt->execute([$poolId, $id]);
        $added += $stmt->rowCount();
    }
    return ['ok' => true, 'added' => $added];
}

function rpc_prayer_pool_remove_members(mixed $payload): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'reason' => 'invalid_payload'];
    }
    $poolId = rpc_prayer_pool_id(rpc_str($payload['poolName'] ?? 'Kranke') ?: 'Kranke');
    if ($poolId <= 0) {
        return ['ok' => true, 'removed' => 0];
    }
    $personIds = is_array($payload['personIds'] ?? null) ? $payload['personIds'] : [];
    $stmt = db()->prepare('DELETE FROM prayer_pool_members WHERE pool_id = ? AND person_id = ?');
    $removed = 0;
    foreach ($personIds as $personId) {
        $id = rpc_str($personId);
        if ($id === '') {
            continue;
        }
        $stmt->execute([$poolId, $id]);
        $removed += $stmt->rowCount();
    }
    return ['ok' => true, 'removed' => $removed];
}

function rpc_prayer_start_session(mixed $payload, array $user): array
{
    $data = is_array($payload) ? $payload : [];
    $sessionId = rpc_str($data['sessionId'] ?? '') ?: ('ps_' . bin2hex(random_bytes(12)));
    $cardId = rpc_str($data['cardId'] ?? '');
    $personCount = max(1, (int) ($data['personCount'] ?? 1));
    $stmt = db()->prepare(
        'INSERT INTO prayer_sessions (session_id, user_email, active_card_id, person_count, started_at, last_seen_at, is_active, meta_json)
         VALUES (?, ?, ?, ?, NOW(), NOW(), 1, ?)
         ON DUPLICATE KEY UPDATE active_card_id = VALUES(active_card_id), person_count = VALUES(person_count), last_seen_at = NOW(), is_active = 1, ended_at = NULL'
    );
    $stmt->execute([$sessionId, rpc_str($user['email'] ?? ''), $cardId, $personCount, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    return ['ok' => true, 'sessionId' => $sessionId];
}

function rpc_prayer_heartbeat(mixed $payload): array
{
    $sessionId = rpc_str(is_array($payload) ? ($payload['sessionId'] ?? '') : '');
    if ($sessionId !== '') {
        $stmt = db()->prepare('UPDATE prayer_sessions SET last_seen_at = NOW() WHERE session_id = ? AND is_active = 1');
        $stmt->execute([$sessionId]);
    }
    return ['ok' => true];
}

function rpc_prayer_end_session(mixed $payload): array
{
    $sessionId = rpc_str(is_array($payload) ? ($payload['sessionId'] ?? '') : '');
    if ($sessionId === '') {
        return ['ok' => true];
    }
    $stmt = db()->prepare('SELECT user_email, person_count, is_active FROM prayer_sessions WHERE session_id = ? LIMIT 1');
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return ['ok' => true];
    }

    if ((int) ($row['is_active'] ?? 0) === 1) {
        $points = max(1, (int) ($row['person_count'] ?? 1));
        $email = rpc_str($row['user_email'] ?? '');
        if ($email !== '') {
            $insert = db()->prepare('INSERT INTO prayer_points (user_email, points, awarded_at, session_id) VALUES (?, ?, NOW(), ?)');
            $insert->execute([$email, $points, $sessionId]);
        }
    }
    $update = db()->prepare('UPDATE prayer_sessions SET ended_at = NOW(), last_seen_at = NOW(), is_active = 0 WHERE session_id = ?');
    $update->execute([$sessionId]);
    return ['ok' => true];
}

function rpc_prayer_leaderboard(array $user): array
{
    $rows = db()->query(
        "SELECT pp.user_email, COALESCE(u.display_name, pp.user_email) AS name, SUM(pp.points) AS points_month, COUNT(*) AS sessions
         FROM prayer_points pp
         LEFT JOIN users u ON u.email = pp.user_email
         WHERE pp.awarded_at >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01')
         GROUP BY pp.user_email, u.display_name
         ORDER BY points_month DESC, sessions DESC, name ASC"
    )->fetchAll();

    $ranked = [];
    $rank = 1;
    foreach ($rows as $row) {
        $ranked[] = [
            'rank' => $rank++,
            'email' => rpc_str($row['user_email'] ?? ''),
            'name' => rpc_str($row['name'] ?? ''),
            'pointsMonth' => (int) ($row['points_month'] ?? 0),
            'sessions' => (int) ($row['sessions'] ?? 0),
        ];
    }
    $userEmail = rpc_str($user['email'] ?? '');
    $me = ['rank' => 0, 'pointsMonth' => 0, 'sessions' => 0];
    foreach ($ranked as $row) {
        if (rpc_lower(rpc_str($row['email'] ?? '')) === rpc_lower($userEmail)) {
            $me = $row;
            break;
        }
    }
    return ['ok' => true, 'top' => array_slice($ranked, 0, 3), 'me' => $me, 'rows' => $ranked];
}

function rpc_run_import(string $type, array $user): array
{
    if (!rpc_is_admin_role($user)) {
        return ['ok' => false, 'error' => 'Import fuer diese Rolle nicht freigeschaltet.'];
    }

    $results = [];
    $startedAt = date('Y-m-d H:i:s');

    try {
        foreach (rpc_import_steps($type) as $step) {
            $results[$step] = rpc_run_import_step($step);
        }
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'type' => $type,
            'startedAt' => $startedAt,
            'finishedAt' => date('Y-m-d H:i:s'),
            'results' => $results,
            'error' => $e->getMessage(),
        ];
    }

    return [
        'ok' => true,
        'type' => $type,
        'startedAt' => $startedAt,
        'finishedAt' => date('Y-m-d H:i:s'),
        'results' => $results,
        'dataVersion' => rpc_data_version(),
    ];
}

function rpc_import_steps(string $type): array
{
    return match ($type) {
        'personen' => ['people', 'families', 'groups'],
        'familien' => ['families'],
        'gruppen' => ['groups'],
        'kalender' => ['calendar'],
        'service_details' => ['service_details'],
        'songs' => ['songs'],
        'alles' => ['people', 'families', 'groups', 'calendar', 'service_details', 'songs'],
        default => throw new InvalidArgumentException('Unbekannter Import: ' . $type),
    };
}

function rpc_run_import_step(string $step): array
{
    return match ($step) {
        'people' => rpc_import_people_step(),
        'families' => rpc_import_families_step(),
        'groups' => rpc_import_groups_step(),
        'calendar' => rpc_import_calendar_step(),
        'service_details' => rpc_import_service_details_step(),
        'songs' => rpc_import_songs_step(),
        default => throw new InvalidArgumentException('Unbekannter Importschritt: ' . $step),
    };
}

function rpc_import_people_step(): array
{
    require_once __DIR__ . '/../../src/import_people.php';
    return import_people();
}

function rpc_import_families_step(): array
{
    require_once __DIR__ . '/../../src/import_families.php';
    return import_families();
}

function rpc_import_groups_step(): array
{
    require_once __DIR__ . '/../../src/import_groups.php';
    return import_groups();
}

function rpc_import_calendar_step(): array
{
    require_once __DIR__ . '/../../src/import_calendar.php';
    return import_calendar_basic();
}

function rpc_import_service_details_step(): array
{
    require_once __DIR__ . '/../../src/import_service_details.php';
    return import_service_details();
}

function rpc_import_songs_step(): array
{
    require_once __DIR__ . '/../../src/import_songs.php';
    return import_songs();
}

function rpc_import_runs(): array
{
    return db()->query('SELECT import_type, status, started_at, finished_at, item_count, message FROM import_runs ORDER BY started_at DESC LIMIT 10')->fetchAll();
}

function rpc_sync_status(array $user): array
{
    return [
        'ok' => true,
        'status' => 'ok',
        'personen' => rpc_import_status_group(['people', 'families', 'groups']),
        'kalender' => rpc_import_status_group(['calendar']),
        'serviceDetails' => rpc_import_status_group(['service_details']),
        'songs' => rpc_import_status_group(['songs']),
        'songDiagnostics' => rpc_song_diagnostics(),
        'counts' => rpc_data_counts(),
        'cache' => rpc_cache_stats(),
        'latestRuns' => rpc_import_runs(),
        'user' => array_merge($user, ['permissions' => rpc_permissions($user)]),
        'dataVersion' => rpc_data_version(),
    ];
}

function rpc_rebuild_server_caches(array $user): array
{
    if (!rpc_is_admin_role($user)) {
        return ['ok' => false, 'error' => 'Cache-Rebuild fuer diese Rolle nicht freigeschaltet.'];
    }
    set_app_setting('DATA_VERSION', (string) time());
    return ['ok' => true, 'cache' => rpc_cache_stats(), 'dataVersion' => rpc_data_version()];
}

function rpc_nightly_cache_notice(string $mode): array
{
    return [
        'ok' => true,
        'mode' => $mode,
        'message' => 'Auf Metanet/Plesk werden automatische Jobs ueber Geplante Aufgaben eingerichtet. Google-Apps-Script-Trigger werden nicht mehr verwendet.',
        'cron' => 'cd /home/httpd/vhosts/ypg.ch/app.clzspiez.ch && /opt/plesk/php/8.2/bin/php scripts/import_calendar.php',
        'dataVersion' => rpc_data_version(),
    ];
}

function rpc_import_status_group(array $types): array
{
    $placeholders = implode(',', array_fill(0, count($types), '?'));
    $stmt = db()->prepare(
        "SELECT import_type, status, started_at, finished_at, item_count, message
         FROM import_runs
         WHERE import_type IN ({$placeholders})
         ORDER BY started_at DESC"
    );
    $stmt->execute($types);
    $rows = $stmt->fetchAll();
    $latest = [];
    foreach ($rows as $row) {
        $type = rpc_str($row['import_type'] ?? '');
        if ($type !== '' && !isset($latest[$type])) {
            $latest[$type] = $row;
        }
    }

    $last = $rows[0] ?? null;
    $okCount = 0;
    foreach ($latest as $row) {
        if (rpc_str($row['status'] ?? '') === 'ok') {
            $okCount++;
        }
    }

    return [
        'status' => $last ? rpc_str($last['status'] ?? '') : 'missing',
        'iso' => $last ? rpc_str($last['finished_at'] ?? ($last['started_at'] ?? '')) : '',
        'count' => $last ? (int) ($last['item_count'] ?? 0) : 0,
        'message' => $last ? rpc_str($last['message'] ?? '') : '',
        'stepsOk' => $okCount,
        'stepsTotal' => count($types),
        'steps' => $latest,
    ];
}

function rpc_data_counts(): array
{
    $tables = [
        'people' => 'people',
        'families' => 'families',
        'groups' => 'groups',
        'calendarEvents' => 'calendar_events',
        'services' => 'services',
        'serviceTimes' => 'service_times',
        'serviceVolunteers' => 'service_volunteers',
        'servicePlanItems' => 'service_plan_items',
        'songs' => 'songs',
    ];
    $counts = [];
    foreach ($tables as $key => $table) {
        try {
            $counts[$key] = (int) db()->query("SELECT COUNT(*) AS c FROM {$table}")->fetch()['c'];
        } catch (Throwable) {
            $counts[$key] = 0;
        }
    }
    return $counts;
}

function rpc_song_diagnostics(): array
{
    try {
        $rows = db()->query('SELECT song_id, title, default_key_name, bpm, raw_json FROM songs ORDER BY title LIMIT 250')->fetchAll();
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'error' => $e->getMessage(),
            'total' => 0,
            'withKey' => 0,
            'withAnyUrl' => 0,
            'withPdf' => 0,
            'withAudio' => 0,
            'withYoutube' => 0,
            'samples' => [],
        ];
    }

    $diag = [
        'ok' => true,
        'total' => (int) (db()->query('SELECT COUNT(*) AS c FROM songs')->fetch()['c'] ?? 0),
        'checked' => count($rows),
        'withKey' => 0,
        'withAnyUrl' => 0,
        'withPdf' => 0,
        'withAudio' => 0,
        'withYoutube' => 0,
        'samples' => [],
    ];

    foreach ($rows as $row) {
        $raw = rpc_decode_json_array($row['raw_json'] ?? null);
        $urls = array_values(array_unique(array_merge(rpc_song_url_fields($raw), rpc_song_collect_urls($raw))));
        $keys = rpc_song_key_names($raw, rpc_str($row['default_key_name'] ?? ''));
        $hasKey = (bool) $keys;
        $hasPdf = false;
        $hasAudio = false;
        $hasYoutube = false;
        foreach ($urls as $url) {
            $lower = strtolower($url);
            $hasPdf = $hasPdf || (bool) preg_match('/\.pdf(\?|$)/i', $url);
            $hasAudio = $hasAudio || (bool) preg_match('/\.(mp3|wav|m4a|aac|ogg|opus|flac|aif|aiff)(\?|$)/i', $url);
            $hasYoutube = $hasYoutube || str_contains($lower, 'youtube.com') || str_contains($lower, 'youtube-nocookie.com') || str_contains($lower, 'youtu.be');
        }
        if ($hasKey) {
            $diag['withKey']++;
        }
        if ($urls) {
            $diag['withAnyUrl']++;
        }
        if ($hasPdf) {
            $diag['withPdf']++;
        }
        if ($hasAudio) {
            $diag['withAudio']++;
        }
        if ($hasYoutube) {
            $diag['withYoutube']++;
        }
        if (count($diag['samples']) < 5) {
            $diag['samples'][] = [
                'id' => rpc_str($row['song_id'] ?? ''),
                'title' => rpc_str($row['title'] ?? ''),
                'key' => $keys[0] ?? '',
                'urlCount' => count($urls),
                'rawKeys' => array_slice(array_keys($raw), 0, 20),
            ];
        }
    }

    return $diag;
}

function rpc_cache_stats(): array
{
    $counts = rpc_data_counts();
    $runs = rpc_import_runs();
    $lastRun = $runs[0] ?? [];
    $dataVersion = rpc_data_version();
    return [
        'hits' => 0,
        'misses' => 0,
        'writes' => 0,
        'hitRate' => null,
        'trackedKeys' => 0,
        'dataVersion' => $dataVersion,
        'lastWriteIso' => rpc_str($lastRun['finished_at'] ?? ($lastRun['started_at'] ?? '')),
        'lastResetIso' => rpc_str($lastRun['finished_at'] ?? ''),
        'counts' => $counts,
        'summary' => sprintf(
            '%d Kontakte, %d Kalender, %d Services, %d Songs',
            $counts['people'] ?? 0,
            $counts['calendarEvents'] ?? 0,
            $counts['services'] ?? 0,
            $counts['songs'] ?? 0
        ),
    ];
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
        'SELECT gm.person_id, gm.role, gm.position, g.name, g.category_name, g.status
         FROM group_members gm
         LEFT JOIN groups g ON g.id = gm.group_id'
    )->fetchAll();

    foreach ($rows as $row) {
        $personId = rpc_str($row['person_id'] ?? '');
        $groupName = rpc_str($row['name'] ?? '');
        if ($personId === '' || $groupName === '' || !rpc_is_kleingruppe_group($row)) {
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
            return rpc_family_relationship_kind($relationship) !== 'child' && ($age === null || $age >= 16);
        }));
        $main = null;
        foreach ($members as $member) {
            if (rpc_family_relationship_kind(rpc_str($member['relationship'] ?? '')) === 'main') {
                $main = $member;
                break;
            }
        }
        $main ??= $adultMembers[0] ?? ($members[0] ?? null);
        $mainPersonId = $main ? rpc_str($main['person_id'] ?? '') : '';
        $isSingleFamily = rpc_starts_with(rpc_lower(rpc_str($familyId)), 'einzel_') || count($members) === 1;
        $kidsCount = 0;
        foreach ($members as $member) {
            if (rpc_family_relationship_kind(rpc_str($member['relationship'] ?? '')) === 'child') {
                $kidsCount++;
            }
        }
        $adultCount = count($adultMembers);
        $householdTypeKey = "{$adultCount}_{$kidsCount}";

        foreach ($members as $member) {
            $personId = rpc_str($member['person_id'] ?? '');
            if ($personId === '') {
                continue;
            }
            $values[$personId] = [
                'familyId' => rpc_str($familyId),
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
        'SELECT g.*, gm.role, gm.position
         FROM groups g
         INNER JOIN group_members gm ON gm.group_id = g.id
         WHERE gm.person_id = ?
         ORDER BY g.name',
        [$personId]
    );
    if (!$rows) {
        return [];
    }

    $leads = [];
    $members = [];
    foreach ($rows as $row) {
        if (!rpc_is_kleingruppe_group($row)) {
            continue;
        }

        $group = rpc_group_payload($row);
        $roleKind = rpc_group_member_role_kind($row);
        if ($roleKind === 'leader') {
            $leads[] = $group;
        } else {
            if ($roleKind === 'assistant') {
                $group['roleBadge'] = 'Stv. Leiter';
                $group['roleTone'] = 'assistant';
            }
            $members[] = $group;
        }
    }

    $sections = [];
    if ($leads) {
        $sections[] = ['type' => 'group-section', 'label' => 'Leitet Kleingruppe', 'items' => $leads];
    }
    if ($members) {
        $sections[] = ['type' => 'group-section', 'label' => 'Ist in Kleingruppe', 'items' => $members];
    }
    return $sections;
}

function rpc_fetch_group_by_name(string $groupName): ?array
{
    $stmt = db()->prepare('SELECT * FROM groups WHERE LOWER(name) = LOWER(?) LIMIT 1');
    $stmt->execute([$groupName]);
    $row = $stmt->fetch();
    if (is_array($row) && rpc_is_kleingruppe_group($row)) {
        return $row;
    }

    $stmt = db()->prepare('SELECT * FROM groups WHERE name LIKE ? ORDER BY name LIMIT 1');
    $stmt->execute(['%' . $groupName . '%']);
    $row = $stmt->fetch();
    return is_array($row) && rpc_is_kleingruppe_group($row) ? $row : null;
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
        'roleBadge' => rpc_str($group['roleBadge'] ?? ''),
        'roleTone' => rpc_str($group['roleTone'] ?? ''),
    ];
    $payload['mapHref'] = rpc_maps_href($payload['meetingPoint']);

    foreach ($members as $member) {
        $person = [
            'personId' => rpc_str($member['person_id'] ?? ''),
            'name' => rpc_str($member['person_display_name'] ?? '') ?: (rpc_str($member['display_name'] ?? '') ?: trim(rpc_str($member['firstname'] ?? '') . ' ' . rpc_str($member['lastname'] ?? ''))),
            'position' => trim(implode(' ', array_filter([rpc_str($member['role'] ?? ''), rpc_str($member['position'] ?? '')]))),
            'roleBadge' => '',
            'roleTone' => '',
        ];
        $roleKind = rpc_group_member_role_kind($member);
        if ($roleKind === 'assistant') {
            $person['roleBadge'] = 'Stv. Leiter';
            $person['roleTone'] = 'assistant';
            $payload['assistantPersons'][] = $person;
        } elseif ($roleKind === 'leader') {
            $payload['leaderPersons'][] = $person;
        } else {
            $payload['memberPersons'][] = $person;
        }
    }

    return $payload;
}

function rpc_is_kleingruppe_group(array $group): bool
{
    $status = rpc_lower(rpc_str($group['status'] ?? ''));
    if ($status !== '' && in_array($status, ['archived', 'inactive', 'inaktiv'], true)) {
        return false;
    }

    $haystack = rpc_lower(trim(rpc_str($group['category_name'] ?? '') . ' ' . rpc_str($group['name'] ?? '')));
    $haystack = strtr($haystack, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue']);
    return str_contains($haystack, 'kleingruppe') || str_contains($haystack, 'kleingruppen');
}

function rpc_group_member_role_kind(array $member): string
{
    $roleText = rpc_lower(rpc_str($member['role'] ?? '') . ' ' . rpc_str($member['position'] ?? ''));
    if (str_contains($roleText, 'stv') || str_contains($roleText, 'assistant') || str_contains($roleText, 'assistent')) {
        return 'assistant';
    }
    if (str_contains($roleText, 'leiter') || str_contains($roleText, 'leader')) {
        return 'leader';
    }
    return 'member';
}

function rpc_address_block(string $address, string $postcode, string $city): string
{
    $cityLine = trim($postcode . ' ' . $city);
    return implode("\n", array_values(array_filter([$address, $cityLine])));
}

function rpc_maps_href(string $address): string
{
    $address = trim($address);
    return $address !== '' ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($address) : '';
}

function rpc_phone_href_value(string $phone): string
{
    return preg_replace('/[^\d+]/', '', $phone) ?? '';
}

function rpc_normalize_tel(string $phone): string
{
    return preg_replace('/[\s()\-]+/', '', trim($phone)) ?? '';
}

function rpc_normalize_phone_search_token(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if ($digits === '') {
        return '';
    }
    if (rpc_starts_with($digits, '41') && strlen($digits) >= 11) {
        return '0' . substr($digits, 2);
    }
    return $digits;
}

function rpc_phone_search_tokens(string $phone, string $mobile): array
{
    $seen = [];
    $out = [];

    foreach ([$phone, $mobile] as $value) {
        $raw = trim($value);
        if ($raw === '') {
            continue;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        foreach ([$raw, rpc_normalize_tel($raw), $digits, rpc_normalize_phone_search_token($raw)] as $token) {
            $normalized = rpc_search_text($token);
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $out[] = $normalized;
        }
    }

    return $out;
}

function rpc_whatsapp_href(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if ($digits === '') {
        return '';
    }
    if (rpc_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    } elseif (rpc_starts_with($digits, '0')) {
        $digits = '41' . substr($digits, 1);
    }
    return 'https://wa.me/' . $digits;
}

function rpc_elvanto_person_url(string $personId): string
{
    return $personId !== '' ? 'https://app.elvanto.com/people/person/' . rawurlencode($personId) : '';
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
        $normalized = strtolower(trim($item, " \t\n\r\0\x0B-–—"));
        if (in_array($normalized, ['keine', 'kein', 'none', 'null', 'n/a'], true)) {
            continue;
        }
        if ($item !== '' && !in_array($item, $out, true)) {
            $out[] = $item;
        }
    }
    return $out;
}

function rpc_split_leadership_values(string $value): array
{
    $out = [];
    foreach (rpc_split_multi_value($value) as $part) {
        $normalized = rpc_normalize_leadership_token($part);
        if ($normalized !== '' && !in_array($normalized, $out, true)) {
            $out[] = $normalized;
        }
    }
    return $out;
}

function rpc_normalize_leadership_token(string $value): string
{
    $raw = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($raw === '') {
        return '';
    }

    $compact = rpc_lower($raw);
    $compact = strtr($compact, [
        'ä' => 'ae',
        'ö' => 'oe',
        'ü' => 'ue',
        'ß' => 'ss',
        '–' => '-',
        '—' => '-',
    ]);
    $compact = preg_replace('/[^a-z0-9]+/', ' ', $compact) ?? '';
    $compact = trim(preg_replace('/\s+/', ' ', $compact) ?? '');
    if ($compact === '') {
        return '';
    }

    if (
        str_contains($compact, 'in leiter') ||
        str_contains($compact, 'leiter kleingruppen') ||
        str_contains($compact, 'leiter ressort') ||
        (str_contains($compact, 'gruppen ressort') && !str_contains($compact, 'leiter'))
    ) {
        return '';
    }
    if (preg_match('/^kleingruppen leiter( in)?$/', $compact)) {
        return '';
    }

    $map = [
        'aelteste' => 'Strategiesche-Leitung',
        'strategische leitung' => 'Strategiesche-Leitung',
        'strategiesche leitung' => 'Strategiesche-Leitung',
        'operative leitung' => 'Operative-Leitung',
        'opl' => 'Operative-Leitung',
        'gemeindeleitung opl' => 'Operative-Leitung',
        'ressort gruppen leiter in' => 'Ressort-Gruppen-Leiter/-in',
        'ressort gruppen leiterin' => 'Ressort-Gruppen-Leiter/-in',
        'ressort gruppen leiter' => 'Ressort-Gruppen-Leiter/-in',
        'leiterin gruppen ressort' => 'Ressort-Gruppen-Leiter/-in',
        'ressort leiter in' => 'Ressort-Leiter/-in',
        'ressort leiterin' => 'Ressort-Leiter/-in',
        'ressort leiter' => 'Ressort-Leiter/-in',
        'ressort leiter in stv' => 'Ressort-Leiter/-in Stv.',
        'ressort leiterin stv' => 'Ressort-Leiter/-in Stv.',
        'ressort leiter stv' => 'Ressort-Leiter/-in Stv.',
    ];
    if (isset($map[$compact])) {
        return $map[$compact];
    }

    if (preg_match('/^ressort gruppen leiter( in)?$/', $compact)) {
        return 'Ressort-Gruppen-Leiter/-in';
    }
    if (preg_match('/^ressort leiter( in)? stv$/', $compact)) {
        return 'Ressort-Leiter/-in Stv.';
    }
    if (preg_match('/^ressort leiter( in)?$/', $compact)) {
        return 'Ressort-Leiter/-in';
    }
    if (preg_match('/^strategi?sche leitung$/', $compact)) {
        return 'Strategiesche-Leitung';
    }
    if (preg_match('/^operative leitung$/', $compact)) {
        return 'Operative-Leitung';
    }

    return '';
}

function rpc_search_text(string $value): string
{
    $value = rpc_lower(trim($value));
    $value = strtr($value, [
        'ä' => 'ae',
        'ö' => 'oe',
        'ü' => 'ue',
        'ß' => 'ss',
        'Ã¤' => 'ae',
        'Ã¶' => 'oe',
        'Ã¼' => 'ue',
        'ÃŸ' => 'ss',
    ]);
    $value = preg_replace('/[^A-Za-z0-9_\s()+-]+/u', ' ', $value) ?? '';
    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
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

function rpc_date_between_relative(string $date, string $startModifier, string $endModifier): bool
{
    if ($date === '') {
        return false;
    }
    try {
        $day = new DateTimeImmutable($date);
        $start = (new DateTimeImmutable('today'))->modify($startModifier)->setTime(0, 0, 0);
        $end = (new DateTimeImmutable('today'))->modify($endModifier)->setTime(23, 59, 59);
        return $day >= $start && $day <= $end;
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
