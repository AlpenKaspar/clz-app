<?php

declare(strict_types=1);

require_once __DIR__ . '/import_people.php';

function import_groups(): array
{
    $startedAt = date('Y-m-d H:i:s');
    $runId = import_run_start('groups');

    try {
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM group_members');
        $pdo->exec('DELETE FROM groups');

        $groupCount = 0;
        $memberCount = 0;
        $seenGroups = [];
        $seenMemberships = [];
        $page = 1;

        while (true) {
            $data = elvanto_post('groups/getAll.json', [
                'page' => $page,
                'page_size' => 200,
                'fields' => ['categories', 'people', 'demographics', 'locations'],
            ]);

            $groups = extract_items_by_path_candidates($data, [
                ['groups', 'group'],
                ['group'],
                ['groups'],
            ]);
            if (!$groups) {
                break;
            }

            foreach ($groups as $group) {
                if (!is_array($group)) {
                    continue;
                }
                $groupId = trim((string) ($group['id'] ?? ''));
                if ($groupId === '' || isset($seenGroups[$groupId])) {
                    continue;
                }
                $seenGroups[$groupId] = true;

                $members = extract_group_members($group);
                if (!$members) {
                    $detail = fetch_group_detail($groupId);
                    if ($detail) {
                        $members = extract_group_members($detail);
                        $group = array_merge($group, $detail);
                    }
                }

                upsert_group($group);
                $groupCount++;

                foreach ($members as $member) {
                    if (!is_array($member)) {
                        continue;
                    }
                    $personId = extract_group_person_id($member);
                    if ($personId === '') {
                        continue;
                    }
                    $role = extract_group_member_role($member);
                    $position = extract_group_member_position($member);
                    $dedupe = implode('|', [$groupId, $personId, $role, $position]);
                    if (isset($seenMemberships[$dedupe])) {
                        continue;
                    }
                    $seenMemberships[$dedupe] = true;
                    upsert_group_member($group, $member, $personId, $role, $position);
                    $memberCount++;
                }
            }

            if (count($groups) < 200) {
                break;
            }
            $page++;
        }

        set_app_setting('IMPORT_GROUPS_LAST', date('c'));
        $pdo->commit();

        import_run_finish($runId, 'ok', $groupCount, "Imported {$groupCount} groups with {$memberCount} members.");

        return [
            'ok' => true,
            'type' => 'groups',
            'groups' => $groupCount,
            'members' => $memberCount,
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

function fetch_group_detail(string $groupId): array
{
    $data = elvanto_post('groups/get.json', [
        'id' => $groupId,
        'fields' => ['categories', 'people', 'demographics', 'locations'],
    ]);

    $items = extract_items_by_path_candidates($data, [
        ['group'],
        ['groups', 'group'],
        ['data', 'group'],
    ]);

    return is_array($items[0] ?? null) ? $items[0] : [];
}

function upsert_group(array $group): void
{
    $groupId = trim((string) ($group['id'] ?? ''));
    $stmt = db()->prepare(
        'INSERT INTO groups (
            id, date_added, date_modified, name, description, meeting_address, meeting_city,
            meeting_country, meeting_day, meeting_frequency, meeting_postcode, meeting_state,
            meeting_time, picture_url, status, category_name, raw_json, imported_at
         ) VALUES (
            :id, :date_added, :date_modified, :name, :description, :meeting_address, :meeting_city,
            :meeting_country, :meeting_day, :meeting_frequency, :meeting_postcode, :meeting_state,
            :meeting_time, :picture_url, :status, :category_name, :raw_json, :imported_at
         )
         ON DUPLICATE KEY UPDATE
            date_added = VALUES(date_added),
            date_modified = VALUES(date_modified),
            name = VALUES(name),
            description = VALUES(description),
            meeting_address = VALUES(meeting_address),
            meeting_city = VALUES(meeting_city),
            meeting_country = VALUES(meeting_country),
            meeting_day = VALUES(meeting_day),
            meeting_frequency = VALUES(meeting_frequency),
            meeting_postcode = VALUES(meeting_postcode),
            meeting_state = VALUES(meeting_state),
            meeting_time = VALUES(meeting_time),
            picture_url = VALUES(picture_url),
            status = VALUES(status),
            category_name = VALUES(category_name),
            raw_json = VALUES(raw_json),
            imported_at = VALUES(imported_at)'
    );

    $stmt->execute([
        ':id' => $groupId,
        ':date_added' => parse_elvanto_datetime($group['date_added'] ?? null),
        ':date_modified' => parse_elvanto_datetime($group['date_modified'] ?? null),
        ':name' => normalize_string($group['name'] ?? ''),
        ':description' => normalize_string($group['description'] ?? ''),
        ':meeting_address' => normalize_string($group['meeting_address'] ?? ($group['address'] ?? '')),
        ':meeting_city' => normalize_string($group['meeting_city'] ?? ''),
        ':meeting_country' => normalize_string($group['meeting_country'] ?? ''),
        ':meeting_day' => normalize_string($group['meeting_day'] ?? ''),
        ':meeting_frequency' => normalize_string($group['meeting_frequency'] ?? ''),
        ':meeting_postcode' => normalize_string($group['meeting_postcode'] ?? ''),
        ':meeting_state' => normalize_string($group['meeting_state'] ?? ''),
        ':meeting_time' => normalize_string($group['meeting_time'] ?? ''),
        ':picture_url' => pick_picture_url($group['picture'] ?? ''),
        ':status' => normalize_string($group['status'] ?? ''),
        ':category_name' => implode(' | ', extract_group_category_names($group)),
        ':raw_json' => json_encode($group, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':imported_at' => date('Y-m-d H:i:s'),
    ]);
}

function upsert_group_member(array $group, array $member, string $personId, string $role, string $position): void
{
    $firstname = normalize_string($member['firstname'] ?? ($member['first_name'] ?? ($member['person_firstname'] ?? ($member['person_first_name'] ?? ''))));
    $lastname = normalize_string($member['lastname'] ?? ($member['last_name'] ?? ($member['person_lastname'] ?? ($member['person_last_name'] ?? ''))));
    $displayName = extract_group_member_display_name($member, $firstname, $lastname);

    $stmt = db()->prepare(
        'INSERT INTO group_members (group_id, person_id, firstname, lastname, display_name, role, position, email, mobile, imported_at)
         VALUES (:group_id, :person_id, :firstname, :lastname, :display_name, :role, :position, :email, :mobile, :imported_at)
         ON DUPLICATE KEY UPDATE firstname = VALUES(firstname), lastname = VALUES(lastname),
            display_name = VALUES(display_name), email = VALUES(email), mobile = VALUES(mobile), imported_at = VALUES(imported_at)'
    );
    $stmt->execute([
        ':group_id' => trim((string) ($group['id'] ?? '')),
        ':person_id' => $personId,
        ':firstname' => $firstname,
        ':lastname' => $lastname,
        ':display_name' => $displayName,
        ':role' => $role,
        ':position' => $position,
        ':email' => normalize_string($member['email'] ?? ($member['person']['email'] ?? ($member['contact']['email'] ?? ''))),
        ':mobile' => normalize_swiss_phone($member['mobile'] ?? ($member['phone_mobile'] ?? ($member['mobile_phone'] ?? ($member['person']['mobile'] ?? ($member['contact']['mobile'] ?? ''))))),
        ':imported_at' => date('Y-m-d H:i:s'),
    ]);
}

function extract_group_members(array $group): array
{
    foreach ([
        ['members', 'member'],
        ['members'],
        ['people', 'person'],
        ['people'],
        ['group_members', 'group_member'],
        ['group_members'],
    ] as $path) {
        $items = get_path_array($group, $path);
        if ($items) {
            return $items;
        }
    }

    return [];
}

function extract_group_category_names(array $group): array
{
    foreach ([
        ['categories', 'category'],
        ['categories'],
        ['category'],
        ['group_categories', 'group_category'],
        ['group_categories'],
    ] as $path) {
        $items = get_path_array($group, $path);
        if (!$items) {
            continue;
        }
        $names = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $name = normalize_string($item['name'] ?? ($item['title'] ?? ($item['label'] ?? '')));
            } else {
                $name = normalize_string($item);
            }
            if ($name !== '') {
                $names[] = $name;
            }
        }
        if ($names) {
            return array_values(array_unique($names));
        }
    }

    return [];
}

function extract_group_person_id(array $member): string
{
    return trim((string) (
        $member['person_id']
        ?? $member['people_id']
        ?? $member['member_id']
        ?? $member['id']
        ?? $member['person']['id']
        ?? ''
    ));
}

function extract_group_member_display_name(array $member, string $firstname, string $lastname): string
{
    $direct = normalize_string($member['name'] ?? ($member['display_name'] ?? ($member['full_name'] ?? '')));
    if ($direct !== '') {
        return $direct;
    }
    return trim($firstname . ' ' . $lastname);
}

function extract_group_member_role(array $member): string
{
    return implode(' | ', unique_normalized_values([
        $member['role'] ?? null,
        $member['roles'] ?? null,
        $member['member_role'] ?? null,
        $member['group_role'] ?? null,
        $member['role_name'] ?? null,
        $member['role_label'] ?? null,
    ]));
}

function extract_group_member_position(array $member): string
{
    return implode(' | ', unique_normalized_values([
        $member['position'] ?? null,
        $member['positions'] ?? null,
        $member['member_position'] ?? null,
        $member['group_position'] ?? null,
        $member['position_name'] ?? null,
        $member['position_label'] ?? null,
    ]));
}

function unique_normalized_values(array $values): array
{
    $out = [];
    $seen = [];
    foreach ($values as $value) {
        foreach (flatten_group_value($value) as $item) {
            $key = mb_strtolower($item);
            if ($item !== '' && !isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $item;
            }
        }
    }
    return $out;
}

function flatten_group_value(mixed $value): array
{
    if ($value === null || $value === '') {
        return [];
    }
    if (is_array($value)) {
        if (array_is_list($value)) {
            $out = [];
            foreach ($value as $item) {
                $out = array_merge($out, flatten_group_value($item));
            }
            return $out;
        }
        $candidate = normalize_string($value['name'] ?? ($value['label'] ?? ($value['title'] ?? ($value['value'] ?? ($value['position'] ?? ($value['role'] ?? ''))))));
        return $candidate === '' ? [] : [$candidate];
    }
    $candidate = normalize_string($value);
    return $candidate === '' ? [] : [$candidate];
}

function extract_items_by_path_candidates(array $obj, array $candidates): array
{
    foreach ($candidates as $path) {
        $items = get_path_array($obj, $path);
        if ($items) {
            return $items;
        }
    }
    return [];
}

function get_path_array(array $obj, array $path): array
{
    $cur = $obj;
    foreach ($path as $part) {
        if (!is_array($cur) || !array_key_exists($part, $cur)) {
            return [];
        }
        $cur = $cur[$part];
    }
    return normalize_collection($cur);
}

function pick_picture_url(mixed $picture): string
{
    if (is_string($picture)) {
        return trim($picture);
    }
    if (!is_array($picture)) {
        return '';
    }
    return normalize_string($picture['url'] ?? ($picture['medium'] ?? ($picture['large'] ?? ($picture['small'] ?? ($picture['original'] ?? '')))));
}

