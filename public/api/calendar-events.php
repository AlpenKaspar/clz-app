<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

function calendar_api_color(array $row): string
{
    $color = trim((string) ($row['category_color'] ?? ''));
    if ($color !== '') {
        return $color;
    }
    $seed = trim((string) (($row['category_key'] ?? '') ?: ($row['category'] ?? '')));
    if ($seed === '') {
        return '#cbd5e1';
    }
    $palette = ['#2563eb', '#16a34a', '#d97706', '#dc2626', '#7c3aed', '#0891b2', '#db2777', '#4f46e5', '#059669', '#ea580c'];
    return $palette[(int) (abs(crc32($seed)) % count($palette))];
}

function calendar_api_category_label(mixed $value): string
{
    $name = trim((string) $value);
    if ($name === '') {
        return '';
    }
    $clean = preg_replace('/^[^_]*_+/', '', $name, 1) ?? $name;
    return trim($clean) !== '' ? trim($clean) : $name;
}

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
            'category' => calendar_api_category_label($row['category']),
            'location' => $row['location'],
            'details' => $row['details'],
            'status' => $row['status'],
            'categoryColor' => calendar_api_color($row),
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
