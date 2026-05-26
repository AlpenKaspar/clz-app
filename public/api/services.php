<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

function services_api_color(array $row): string
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

function services_api_category_label(mixed $value): string
{
    $name = trim((string) $value);
    if ($name === '') {
        return '';
    }
    $clean = preg_replace('/^[^_]*_+/', '', $name, 1) ?? $name;
    $clean = trim($clean);
    if ($clean !== 'Mitarbeiter') {
        $clean = preg_replace('/\s+Mitarbeiter$/iu', '', $clean) ?? $clean;
    }
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
        $end = (new DateTimeImmutable($start))->modify('+90 days')->format('Y-m-d');
    }

    $stmt = db()->prepare(
        "SELECT ce.id, ce.elvanto_id, ce.start_date, ce.start_time, ce.end_date, ce.end_time,
                ce.title, ce.category, ce.location, ce.status, ce.category_color, ce.category_key,
                s.service_id, s.imported_at AS details_imported_at,
                (SELECT COUNT(*) FROM service_times st WHERE st.service_id = s.service_id) AS time_count,
                (SELECT COUNT(*) FROM service_volunteers sv WHERE sv.service_id = s.service_id) AS volunteer_count,
                (SELECT COUNT(*) FROM service_plan_items spi WHERE spi.service_id = s.service_id) AS plan_item_count
         FROM calendar_events ce
         LEFT JOIN services s ON s.service_id = SUBSTRING(ce.elvanto_id, 9)
         WHERE ce.elvanto_id LIKE 'SERVICE-%'
           AND ce.start_date <= :end_date
           AND COALESCE(ce.end_date, ce.start_date) >= :start_date
         ORDER BY ce.start_date, ce.start_time, ce.title"
    );
    $stmt->execute([
        ':start_date' => $start,
        ':end_date' => $end,
    ]);

    $services = [];
    foreach ($stmt as $row) {
        $services[] = [
            'id' => (string) $row['id'],
            'elvantoId' => $row['elvanto_id'],
            'serviceId' => $row['service_id'],
            'title' => $row['title'],
            'date' => $row['start_date'],
            'time' => $row['start_time'] ? substr((string) $row['start_time'], 0, 5) : '',
            'endDate' => $row['end_date'],
            'endTime' => $row['end_time'] ? substr((string) $row['end_time'], 0, 5) : '',
            'category' => services_api_category_label($row['category']),
            'location' => $row['location'],
            'status' => $row['status'],
            'categoryColor' => services_api_color($row),
            'detailsImportedAt' => $row['details_imported_at'],
            'counts' => [
                'times' => (int) $row['time_count'],
                'volunteers' => (int) $row['volunteer_count'],
                'planItems' => (int) $row['plan_item_count'],
            ],
        ];
    }

    json_response([
        'ok' => true,
        'start' => $start,
        'end' => $end,
        'count' => count($services),
        'services' => $services,
    ]);
} catch (Throwable $e) {
    json_error('Gottesdienste konnten nicht geladen werden.', 500, [
        'detail' => env('APP_DEBUG', '0') === '1' ? $e->getMessage() : null,
    ]);
}
