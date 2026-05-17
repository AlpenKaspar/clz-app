<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

try {
    $filters = db()->query("SELECT DISTINCT category_name AS label FROM people WHERE category_name IS NOT NULL AND category_name <> '' ORDER BY category_name")->fetchAll();
    $peopleCount = (int) db()->query('SELECT COUNT(*) AS c FROM people')->fetch()['c'];
    $eventsCount = (int) db()->query('SELECT COUNT(*) AS c FROM calendar_events')->fetch()['c'];

    $user = current_user() ?? guest_user_payload();

    json_response([
        'ok' => true,
        'ts' => date('c'),
        'dataVersion' => app_setting('DATA_VERSION', '1'),
        'user' => array_merge($user, [
            'permissions' => default_permissions($user),
        ]),
        'auth' => [
            'googleConfigured' => google_oauth_is_configured(),
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

function default_permissions(array $user = []): array
{
    $role = strtolower((string) ($user['role'] ?? 'guest'));
    $isAuthenticated = (bool) ($user['isAuthenticated'] ?? false);
    $isAdmin = in_array($role, ['admin', 'super_admin'], true);
    $isSuperAdmin = $role === 'super_admin';
    $isGuest = $role === 'guest' || $role === 'gast';

    return [
        'tabs' => [
            'contacts' => true,
            'calendar' => true,
            'songs' => true,
            'dashboard' => $isAuthenticated && !$isGuest,
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
