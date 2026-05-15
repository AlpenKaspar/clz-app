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
