<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

try {
    require_user();

    $start = trim((string) ($_GET['start'] ?? $_POST['start'] ?? ''));
    $end = trim((string) ($_GET['end'] ?? $_POST['end'] ?? ''));

    if ($start === '') {
        $start = (new DateTimeImmutable('today'))->format('Y-m-d');
    }
    if ($end === '') {
        $end = (new DateTimeImmutable($start))->modify('+60 days')->format('Y-m-d');
    }

    $stmt = db()->prepare(
        'SELECT id, elvanto_id, start_date, start_time, end_date, end_time, title, category, location,
                details, status, category_color, category_key, resources, predigtskript_url
         FROM calendar_events
         WHERE start_date <= :end_date AND COALESCE(end_date, start_date) >= :start_date
         ORDER BY start_date, start_time, title'
    );
    $stmt->execute([
        ':start_date' => $start,
        ':end_date' => $end,
    ]);

    $events = [];
    foreach ($stmt as $row) {
        $events[] = [
            'id' => (string) $row['id'],
            'elvantoId' => $row['elvanto_id'],
            'title' => $row['title'],
            'startDate' => $row['start_date'],
            'startTime' => substr((string) $row['start_time'], 0, 5),
            'endDate' => $row['end_date'],
            'endTime' => $row['end_time'] ? substr((string) $row['end_time'], 0, 5) : '',
            'category' => $row['category'],
            'location' => $row['location'],
            'details' => $row['details'],
            'status' => $row['status'],
            'categoryColor' => $row['category_color'],
            'categoryKey' => $row['category_key'],
            'resources' => $row['resources'],
            'predigtskriptUrl' => $row['predigtskript_url'],
            'meta' => [
                'type' => str_starts_with((string) $row['elvanto_id'], 'SERVICE-') ? 'service' : 'event',
                'elvantoId' => $row['elvanto_id'],
            ],
        ];
    }

    json_response([
        'ok' => true,
        'start' => $start,
        'end' => $end,
        'events' => $events,
        'count' => count($events),
    ]);
} catch (Throwable $e) {
    json_error('Kalender konnte nicht geladen werden.', 500, [
        'detail' => env('APP_DEBUG', '0') === '1' ? $e->getMessage() : null,
    ]);
}
