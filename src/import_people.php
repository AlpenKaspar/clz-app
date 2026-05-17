<?php

declare(strict_types=1);

const PEOPLE_EXITED_FIELD_ID = 'custom_060e499a-2df4-4086-b6f8-c91316408393';
const PEOPLE_EXITED_YES_VALUE_ID = '1e719da4-9558-461e-960d-b84fe6a31bc1';

function import_people(): array
{
    $startedAt = date('Y-m-d H:i:s');
    $runId = import_run_start('people');

    try {
        $categoryMap = fetch_people_category_map();
        $customDefs = fetch_custom_field_definitions();
        upsert_custom_field_definitions($customDefs);

        $customFieldIds = [];
        foreach ($customDefs['fields'] as $field) {
            $id = (string) ($field['field_id'] ?? '');
            if ($id !== '') {
                $customFieldIds[] = $id;
            }
        }
        if (!in_array(PEOPLE_EXITED_FIELD_ID, $customFieldIds, true)) {
            $customFieldIds[] = PEOPLE_EXITED_FIELD_ID;
        }

        $fields = array_values(array_unique(array_merge([
            'gender',
            'birthday',
            'home_address',
            'home_city',
            'home_postcode',
            'departments',
        ], $customFieldIds)));

        $pdo = db();
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM people_custom_fields');

        $count = 0;
        $skipped = 0;
        $page = 1;
        $pageSize = 100;

        while (true) {
            $data = elvanto_post('people/getAll.json', [
                'page' => $page,
                'page_size' => $pageSize,
                'archived' => 'no',
                'suspended' => 'no',
                'fields' => $fields,
            ]);

            $people = normalize_collection($data['people']['person'] ?? []);
            if (!$people) {
                break;
            }

            foreach ($people as $person) {
                if (!is_array($person) || empty($person['id'])) {
                    continue;
                }
                if (!person_is_importable($person)) {
                    $skipped++;
                    continue;
                }

                upsert_person($person, $categoryMap);
                upsert_person_custom_fields($person, $customDefs);
                $count++;
            }

            if (count($people) < $pageSize) {
                break;
            }
            $page++;
        }

        set_app_setting('DATA_VERSION', (string) time());
        set_app_setting('IMPORT_PERSONEN_LAST', date('c'));

        $pdo->commit();
        import_run_finish($runId, 'ok', $count, "Imported {$count} people, skipped {$skipped}.");

        return [
            'ok' => true,
            'type' => 'people',
            'count' => $count,
            'skipped' => $skipped,
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

function fetch_people_category_map(): array
{
    $data = elvanto_post('people/categories/getAll.json');
    $categories = normalize_collection($data['categories']['category'] ?? []);
    $map = [];

    foreach ($categories as $category) {
        if (!is_array($category)) {
            continue;
        }
        $id = trim((string) ($category['id'] ?? ''));
        if ($id !== '') {
            $map[$id] = trim((string) ($category['name'] ?? ''));
        }
    }

    return $map;
}

function fetch_custom_field_definitions(): array
{
    $data = elvanto_post('people/customFields/getAll.json');
    $fields = normalize_collection($data['custom_fields']['custom_field'] ?? []);
    $outFields = [];
    $outOptions = [];

    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }
        $rawId = trim((string) ($field['id'] ?? ''));
        $name = trim((string) ($field['name'] ?? ''));
        if ($rawId === '' || $name === '') {
            continue;
        }
        $fieldId = str_starts_with($rawId, 'custom_') ? $rawId : 'custom_' . $rawId;
        $outFields[] = [
            'field_id' => $fieldId,
            'raw_id' => $rawId,
            'field_type' => trim((string) ($field['type'] ?? '')),
            'field_name' => $name,
        ];

        $options = normalize_collection($field['values']['value'] ?? []);
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }
            $optionId = trim((string) ($option['id'] ?? ''));
            $optionName = trim((string) ($option['name'] ?? ''));
            if ($optionId === '' || $optionName === '') {
                continue;
            }
            $outOptions[$optionId] = [
                'option_id' => $optionId,
                'field_id' => $fieldId,
                'option_name' => $optionName,
            ];
        }
    }

    return [
        'fields' => $outFields,
        'options' => $outOptions,
    ];
}

function upsert_custom_field_definitions(array $defs): void
{
    $now = date('Y-m-d H:i:s');
    $fieldStmt = db()->prepare(
        'INSERT INTO custom_field_definitions (field_id, field_type, field_name, context_name, examples, imported_at)
         VALUES (:field_id, :field_type, :field_name, NULL, NULL, :imported_at)
         ON DUPLICATE KEY UPDATE field_type = VALUES(field_type), field_name = VALUES(field_name), imported_at = VALUES(imported_at)'
    );
    foreach ($defs['fields'] as $field) {
        $fieldStmt->execute([
            ':field_id' => $field['field_id'],
            ':field_type' => $field['field_type'],
            ':field_name' => $field['field_name'],
            ':imported_at' => $now,
        ]);
    }

    $optionStmt = db()->prepare(
        'INSERT INTO custom_field_options (option_id, field_id, option_name, imported_at)
         VALUES (:option_id, :field_id, :option_name, :imported_at)
         ON DUPLICATE KEY UPDATE field_id = VALUES(field_id), option_name = VALUES(option_name), imported_at = VALUES(imported_at)'
    );
    foreach ($defs['options'] as $option) {
        $optionStmt->execute([
            ':option_id' => $option['option_id'],
            ':field_id' => $option['field_id'],
            ':option_name' => $option['option_name'],
            ':imported_at' => $now,
        ]);
    }
}

function person_is_importable(array $person): bool
{
    $status = strtolower(trim((string) ($person['status'] ?? '')));
    if ($status !== '' && !in_array($status, ['active', 'aktiv'], true)) {
        return false;
    }
    if ((string) ($person['archived'] ?? '0') === '1') {
        return false;
    }
    if ((string) ($person['deceased'] ?? '0') === '1') {
        return false;
    }
    if (person_is_exited($person[PEOPLE_EXITED_FIELD_ID] ?? null)) {
        return false;
    }

    return true;
}

function person_is_exited(mixed $value): bool
{
    if (!is_array($value)) {
        return false;
    }
    if (isset($value['custom_field'])) {
        foreach (normalize_collection($value['custom_field']) as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ((string) ($item['id'] ?? '') === PEOPLE_EXITED_YES_VALUE_ID) {
                return true;
            }
            if (strtolower((string) ($item['name'] ?? '')) === 'ja') {
                return true;
            }
        }
    }

    return strtolower((string) ($value['name'] ?? '')) === 'ja';
}

function upsert_person(array $person, array $categoryMap): void
{
    $id = trim((string) ($person['id'] ?? ''));
    $firstname = trim((string) ($person['firstname'] ?? ''));
    $preferred = trim((string) ($person['preferred_name'] ?? ''));
    $lastname = trim((string) ($person['lastname'] ?? ''));
    $display = trim(($preferred !== '' ? $preferred : $firstname) . ' ' . $lastname);
    $categoryId = trim((string) ($person['category_id'] ?? ''));
    $picture = clean_picture_url($person['picture'] ?? '');

    $stmt = db()->prepare(
        'INSERT INTO people (
            id, date_added, date_modified, firstname, preferred_name, lastname, display_name,
            email, phone, mobile, status, category_id, category_name, family_id, gender, birthday,
            home_address, home_city, home_postcode, departments, picture_url, raw_json, imported_at
         ) VALUES (
            :id, :date_added, :date_modified, :firstname, :preferred_name, :lastname, :display_name,
            :email, :phone, :mobile, :status, :category_id, :category_name, :family_id, :gender, :birthday,
            :home_address, :home_city, :home_postcode, :departments, :picture_url, :raw_json, :imported_at
         )
         ON DUPLICATE KEY UPDATE
            date_added = VALUES(date_added),
            date_modified = VALUES(date_modified),
            firstname = VALUES(firstname),
            preferred_name = VALUES(preferred_name),
            lastname = VALUES(lastname),
            display_name = VALUES(display_name),
            email = VALUES(email),
            phone = VALUES(phone),
            mobile = VALUES(mobile),
            status = VALUES(status),
            category_id = VALUES(category_id),
            category_name = VALUES(category_name),
            family_id = VALUES(family_id),
            gender = VALUES(gender),
            birthday = VALUES(birthday),
            home_address = VALUES(home_address),
            home_city = VALUES(home_city),
            home_postcode = VALUES(home_postcode),
            departments = VALUES(departments),
            picture_url = VALUES(picture_url),
            raw_json = VALUES(raw_json),
            imported_at = VALUES(imported_at)'
    );

    $stmt->execute([
        ':id' => $id,
        ':date_added' => parse_elvanto_datetime($person['date_added'] ?? null),
        ':date_modified' => parse_elvanto_datetime($person['date_modified'] ?? null),
        ':firstname' => $firstname,
        ':preferred_name' => $preferred,
        ':lastname' => $lastname,
        ':display_name' => $display,
        ':email' => normalize_string($person['email'] ?? ''),
        ':phone' => normalize_swiss_phone($person['phone'] ?? ''),
        ':mobile' => normalize_swiss_phone($person['mobile'] ?? ''),
        ':status' => normalize_string($person['status'] ?? ''),
        ':category_id' => $categoryId,
        ':category_name' => $categoryMap[$categoryId] ?? '',
        ':family_id' => normalize_string($person['family_id'] ?? ''),
        ':gender' => translate_to_german(normalize_string($person['gender'] ?? '')),
        ':birthday' => parse_elvanto_date($person['birthday'] ?? null),
        ':home_address' => normalize_string($person['home_address'] ?? ''),
        ':home_city' => normalize_string($person['home_city'] ?? ''),
        ':home_postcode' => normalize_string($person['home_postcode'] ?? ''),
        ':departments' => clean_elvanto_value($person['departments'] ?? ''),
        ':picture_url' => $picture,
        ':raw_json' => json_encode($person, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':imported_at' => date('Y-m-d H:i:s'),
    ]);
}

function upsert_person_custom_fields(array $person, array $defs): void
{
    $personId = trim((string) ($person['id'] ?? ''));
    if ($personId === '') {
        return;
    }

    $stmt = db()->prepare(
        'INSERT INTO people_custom_fields (person_id, field_id, field_name, field_value, option_ids, imported_at)
         VALUES (:person_id, :field_id, :field_name, :field_value, :option_ids, :imported_at)
         ON DUPLICATE KEY UPDATE field_name = VALUES(field_name), field_value = VALUES(field_value),
            option_ids = VALUES(option_ids), imported_at = VALUES(imported_at)'
    );

    foreach ($defs['fields'] as $field) {
        $fieldId = (string) ($field['field_id'] ?? '');
        if ($fieldId === '' || !array_key_exists($fieldId, $person)) {
            continue;
        }

        $raw = $person[$fieldId];
        $value = clean_elvanto_value($raw);
        $optionIds = extract_custom_option_ids($raw);
        if ($value === '' && $optionIds === '') {
            continue;
        }

        $stmt->execute([
            ':person_id' => $personId,
            ':field_id' => $fieldId,
            ':field_name' => $field['field_name'],
            ':field_value' => $value,
            ':option_ids' => $optionIds,
            ':imported_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

function normalize_collection(mixed $value): array
{
    if ($value === null || $value === '') {
        return [];
    }
    if (!is_array($value)) {
        return [$value];
    }
    if (array_is_list($value)) {
        return $value;
    }
    return [$value];
}

function clean_elvanto_value(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_scalar($value)) {
        return normalize_string((string) $value);
    }
    if (!is_array($value)) {
        return '';
    }
    if (isset($value['name'])) {
        return normalize_string($value['name']);
    }
    if (isset($value['custom_field'])) {
        $names = [];
        foreach (normalize_collection($value['custom_field']) as $item) {
            if (is_array($item) && trim((string) ($item['name'] ?? '')) !== '') {
                $names[] = trim((string) $item['name']);
            }
        }
        return implode(', ', array_unique($names));
    }
    if (isset($value['family_member'])) {
        $names = [];
        foreach (normalize_collection($value['family_member']) as $member) {
            if (is_array($member)) {
                $names[] = trim((string) ($member['firstname'] ?? '') . ' (' . (string) ($member['relationship'] ?? '') . ')');
            }
        }
        return implode(', ', array_filter($names));
    }
    foreach (['department', 'departments', 'sub_department', 'sub_departments', 'position', 'positions'] as $collectionKey) {
        if (!isset($value[$collectionKey])) {
            continue;
        }
        $names = [];
        foreach (normalize_collection($value[$collectionKey]) as $item) {
            if (is_array($item)) {
                $name = trim((string) ($item['name'] ?? ($item['title'] ?? '')));
                if ($name !== '') {
                    $names[] = $name;
                }
                foreach (['sub_department', 'sub_departments', 'position', 'positions'] as $nestedKey) {
                    if (!isset($item[$nestedKey])) {
                        continue;
                    }
                    foreach (normalize_collection($item[$nestedKey]) as $nested) {
                        if (is_array($nested)) {
                            $nestedName = trim((string) ($nested['name'] ?? ($nested['title'] ?? '')));
                            if ($nestedName !== '') {
                                $names[] = $nestedName;
                            }
                        }
                    }
                }
            }
        }
        if ($names) {
            return implode(', ', array_unique($names));
        }
    }

    return '';
}

function extract_custom_option_ids(mixed $value): string
{
    if (!is_array($value)) {
        return '';
    }
    $ids = [];
    if (isset($value['custom_field'])) {
        foreach (normalize_collection($value['custom_field']) as $item) {
            if (is_array($item) && trim((string) ($item['id'] ?? '')) !== '') {
                $ids[] = trim((string) $item['id']);
            }
        }
    } elseif (trim((string) ($value['id'] ?? '')) !== '') {
        $ids[] = trim((string) $value['id']);
    }

    return implode(',', array_unique($ids));
}

function normalize_string(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_array($value)) {
        foreach (['name', 'title', 'label', 'value', 'display_name'] as $key) {
            if (isset($value[$key]) && !is_array($value[$key])) {
                return normalize_string($value[$key]);
            }
        }
        return '';
    }
    return trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function translate_to_german(string $value): string
{
    return [
        'Male' => 'Männlich',
        'Female' => 'Weiblich',
        'Married' => 'Verheiratet',
        'Single' => 'Ledig',
        'Child' => 'Kind',
        'Spouse' => 'Partner',
    ][$value] ?? $value;
}

function normalize_swiss_phone(mixed $value): string
{
    $raw = preg_replace('/[^\d+]/', '', (string) $value) ?? '';
    if ($raw === '') {
        return '';
    }
    if (str_starts_with($raw, '0') && !str_starts_with($raw, '00') && preg_match('/^0(7\d|3\d|4\d)(\d+)$/', $raw, $m)) {
        return '+41' . $m[1] . $m[2];
    }
    return $raw;
}

function clean_picture_url(mixed $value): string
{
    $url = clean_elvanto_value($value);
    return str_contains(strtolower($url), 'avatar') ? '' : $url;
}

function parse_elvanto_date(mixed $value): ?string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m)) {
        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $raw, $m)) {
        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d', $ts) : null;
}

function parse_elvanto_datetime(mixed $value): ?string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

function import_run_start(string $type): int
{
    $stmt = db()->prepare('INSERT INTO import_runs (import_type, status, started_at) VALUES (?, ?, ?)');
    $stmt->execute([$type, 'running', date('Y-m-d H:i:s')]);
    return (int) db()->lastInsertId();
}

function import_run_finish(int $runId, string $status, int $count, string $message): void
{
    $stmt = db()->prepare('UPDATE import_runs SET status = ?, finished_at = ?, item_count = ?, message = ? WHERE id = ?');
    $stmt->execute([$status, date('Y-m-d H:i:s'), $count, $message, $runId]);
}

if (!function_exists('set_app_setting')) {
    function set_app_setting(string $key, string $value): void
    {
        $stmt = db()->prepare(
            'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute([$key, $value]);
    }
}
