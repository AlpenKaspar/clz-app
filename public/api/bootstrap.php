<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

try {
    $filters = db()->query("SELECT DISTINCT category_name AS label FROM people WHERE category_name IS NOT NULL AND category_name <> '' ORDER BY category_name")->fetchAll();
    $peopleCount = (int) db()->query('SELECT COUNT(*) AS c FROM people')->fetch()['c'];
    $eventsCount = (int) db()->query('SELECT COUNT(*) AS c FROM calendar_events')->fetch()['c'];

    json_response([
        'ok' => true,
        'ts' => date('c'),
        'dataVersion' => app_setting('DATA_VERSION', '1'),
        'user' => [
            'email' => '',
            'role' => 'guest',
            'permissions' => default_permissions(),
        ],
        'filters' => array_map(static fn(array $row): array => [
            'label' => $row['label'],
            'value' => $row['label'],
        ], $filters),
        'dashboard' => [
            'peopleCount' => $peopleCount,
            'eventsCount' => $eventsCount,
        ],
    ]);
} catch (Throwable $e) {
    json_error('Bootstrap konnte nicht geladen werden.', 500, [
        'detail' => env('APP_DEBUG', '0') === '1' ? $e->getMessage() : null,
    ]);
}

function app_setting(string $key, string $default = ''): string
{
    $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return is_string($value) && $value !== '' ? $value : $default;
}

function default_permissions(): array
{
    return [
        'tabs' => [
            'contacts' => true,
            'calendar' => true,
            'dashboard' => false,
            'tools' => false,
        ],
        'exports' => [
            'contactsCsv' => false,
            'contactsPrint' => false,
            'calendarCsv' => false,
            'calendarPrint' => false,
        ],
        'detailPrint' => [
            'contact' => true,
            'event' => true,
        ],
    ];
}

