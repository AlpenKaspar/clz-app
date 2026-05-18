<?php

declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';

try {
    require_admin_token();

    $counts = [
        'people' => count_table('people'),
        'families' => count_table('families'),
        'groups' => count_table('groups'),
        'calendarEvents' => count_table('calendar_events'),
        'calendarServiceEntries' => count_calendar_service_entries(),
        'serviceDetails' => count_table('services'),
        'serviceTimes' => count_table('service_times'),
        'serviceVolunteers' => count_table('service_volunteers'),
        'servicePlanItems' => count_table('service_plan_items'),
    ];

    $runs = db()->query(
        'SELECT import_type, status, started_at, finished_at, item_count, message
         FROM import_runs
         ORDER BY started_at DESC
         LIMIT 12'
    )->fetchAll();

    $sampleServices = db()->query(
        "SELECT elvanto_id, title, start_date, start_time
         FROM calendar_events
         WHERE elvanto_id LIKE 'SERVICE-%'
         ORDER BY start_date, start_time
         LIMIT 8"
    )->fetchAll();

    json_response([
        'ok' => true,
        'counts' => $counts,
        'dashboardDiagnostics' => dashboard_diagnostics(),
        'latestRuns' => $runs,
        'sampleServices' => $sampleServices,
    ]);
} catch (Throwable $e) {
    json_error('Import-Status konnte nicht geladen werden.', 500, [
        'detail' => env('APP_DEBUG', '0') === '1' ? $e->getMessage() : null,
    ]);
}

function count_table(string $table): int
{
    return (int) db()->query("SELECT COUNT(*) AS c FROM {$table}")->fetch()['c'];
}

function count_calendar_service_entries(): int
{
    return (int) db()->query("SELECT COUNT(*) AS c FROM calendar_events WHERE elvanto_id LIKE 'SERVICE-%'")->fetch()['c'];
}

function dashboard_diagnostics(): array
{
    $people = db()->query(
        "SELECT
            COUNT(*) AS total,
            SUM(LOWER(COALESCE(category_name, '')) = 'gemeinde') AS gemeinde,
            SUM(LOWER(COALESCE(category_name, '')) = 'kontakte') AS kontakte,
            SUM(LOWER(COALESCE(category_name, '')) = 'gemeinde' AND COALESCE(gender, '') <> '') AS gemeinde_gender,
            SUM(LOWER(COALESCE(category_name, '')) = 'gemeinde' AND birthday IS NOT NULL) AS gemeinde_birthday,
            SUM(LOWER(COALESCE(category_name, '')) = 'gemeinde' AND COALESCE(family_id, '') <> '') AS gemeinde_family_id,
            SUM(COALESCE(departments, '') <> '') AS departments
         FROM people
         WHERE status IS NULL OR status = '' OR LOWER(status) = 'active'"
    )->fetch();

    $families = db()->query(
        "SELECT
            COUNT(*) AS rows_total,
            SUM(COALESCE(family_id, '') <> '') AS with_family_id,
            SUM(LOWER(COALESCE(relationship, '')) LIKE '%haupt%') AS main_relations,
            SUM(LOWER(COALESCE(relationship, '')) LIKE '%kind%') AS child_relations,
            SUM(LOWER(COALESCE(relationship, '')) LIKE '%einzel%') AS single_relations
         FROM family_members"
    )->fetch();

    $groups = db()->query(
        "SELECT
            COUNT(*) AS members_total,
            COUNT(DISTINCT person_id) AS people_in_groups
         FROM group_members"
    )->fetch();

    $relationshipRows = db()->query(
        "SELECT relationship, COUNT(*) AS c
         FROM family_members
         GROUP BY relationship
         ORDER BY c DESC
         LIMIT 12"
    )->fetchAll();

    return [
        'people' => array_map('intval', $people ?: []),
        'families' => array_map('intval', $families ?: []),
        'groups' => array_map('intval', $groups ?: []),
        'relationships' => $relationshipRows,
    ];
}
