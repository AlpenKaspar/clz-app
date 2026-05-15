<?php

declare(strict_types=1);

require_once __DIR__ . '/import_people.php';

function import_families(): array
{
    $startedAt = date('Y-m-d H:i:s');
    $runId = import_run_start('families');

    try {
        $activePeople = load_active_people_ids();
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM family_members');
        $pdo->exec('DELETE FROM families');

        $processed = [];
        $families = [];
        $memberCount = 0;
        $skipped = 0;
        $page = 1;

        while (true) {
            $data = elvanto_post('people/getAll.json', [
                'page' => $page,
                'page_size' => 1000,
                'archived' => 'no',
                'suspended' => 'no',
                'fields' => ['family', PEOPLE_EXITED_FIELD_ID],
            ]);

            $people = normalize_collection($data['people']['person'] ?? []);
            if (!$people) {
                break;
            }

            foreach ($people as $person) {
                if (!is_array($person) || empty($person['id'])) {
                    continue;
                }
                if (person_is_exited($person[PEOPLE_EXITED_FIELD_ID] ?? null)) {
                    $skipped++;
                    continue;
                }

                $personId = (string) $person['id'];
                $familyId = trim((string) ($person['family_id'] ?? ''));
                if ($familyId === '') {
                    $familyId = 'Einzel_' . $personId;
                }

                $members = extract_family_members($person);
                if (!$members) {
                    $members = [[
                        'id' => $personId,
                        'firstname' => $person['firstname'] ?? '',
                        'lastname' => $person['lastname'] ?? '',
                        'relationship' => 'Einzelperson',
                    ]];
                }

                foreach ($members as $member) {
                    if (!is_array($member) || empty($member['id'])) {
                        continue;
                    }
                    $memberId = (string) $member['id'];
                    if (!isset($activePeople[$memberId])) {
                        continue;
                    }

                    $key = $familyId . '|' . $memberId;
                    if (isset($processed[$key])) {
                        continue;
                    }
                    $processed[$key] = true;
                    $families[$familyId] = true;

                    upsert_family($familyId);
                    upsert_family_member($familyId, $memberId, $member, $memberCount + 1);
                    $memberCount++;
                }
            }

            if (count($people) < 1000) {
                break;
            }
            $page++;
        }

        set_app_setting('IMPORT_FAMILIES_LAST', date('c'));
        $pdo->commit();

        import_run_finish($runId, 'ok', $memberCount, 'Imported ' . count($families) . " families with {$memberCount} members.");

        return [
            'ok' => true,
            'type' => 'families',
            'families' => count($families),
            'members' => $memberCount,
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

function load_active_people_ids(): array
{
    $rows = db()->query('SELECT id FROM people')->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $id = trim((string) ($row['id'] ?? ''));
        if ($id !== '') {
            $out[$id] = true;
        }
    }
    return $out;
}

function extract_family_members(array $person): array
{
    $family = $person['family'] ?? null;
    if (!is_array($family) || !isset($family['family_member'])) {
        return [];
    }
    return normalize_collection($family['family_member']);
}

function upsert_family(string $familyId): void
{
    $stmt = db()->prepare(
        'INSERT INTO families (id, label, imported_at)
         VALUES (:id, :label, :imported_at)
         ON DUPLICATE KEY UPDATE label = VALUES(label), imported_at = VALUES(imported_at)'
    );
    $stmt->execute([
        ':id' => $familyId,
        ':label' => str_starts_with($familyId, 'Einzel_') ? 'Einzelperson' : 'Familie',
        ':imported_at' => date('Y-m-d H:i:s'),
    ]);
}

function upsert_family_member(string $familyId, string $personId, array $member, int $sortOrder): void
{
    $relationship = trim((string) ($member['relationship'] ?? ''));
    if ($relationship === '') {
        $relationship = 'Einzelperson';
    }

    $stmt = db()->prepare(
        'INSERT INTO family_members (family_id, person_id, firstname, lastname, relationship, sort_order, imported_at)
         VALUES (:family_id, :person_id, :firstname, :lastname, :relationship, :sort_order, :imported_at)
         ON DUPLICATE KEY UPDATE firstname = VALUES(firstname), lastname = VALUES(lastname),
            relationship = VALUES(relationship), sort_order = VALUES(sort_order), imported_at = VALUES(imported_at)'
    );
    $stmt->execute([
        ':family_id' => $familyId,
        ':person_id' => $personId,
        ':firstname' => normalize_string($member['firstname'] ?? ''),
        ':lastname' => normalize_string($member['lastname'] ?? ''),
        ':relationship' => translate_family_relationship($relationship),
        ':sort_order' => $sortOrder,
        ':imported_at' => date('Y-m-d H:i:s'),
    ]);
}

function translate_family_relationship(string $relationship): string
{
    return [
        'Primary Contact' => 'Hauptkontakt',
        'Spouse' => 'EhepartnerIn',
        'Child' => 'Kind',
        'Partner' => 'PartnerIn',
        'Sibling' => 'Geschwister',
    ][$relationship] ?? $relationship;
}

