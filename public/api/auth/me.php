<?php

declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';

try {
    $user = current_user() ?? guest_user_payload();
    json_response([
        'ok' => true,
        'user' => array_merge($user, [
            'permissions' => auth_me_permissions($user),
        ]),
        'googleConfigured' => google_oauth_is_configured(),
    ]);
} catch (Throwable $e) {
    json_error('Loginstatus konnte nicht geladen werden.', 500, [
        'detail' => env('APP_DEBUG', '0') === '1' ? $e->getMessage() : null,
    ]);
}

function auth_me_permissions(array $user): array
{
    $role = strtolower((string) ($user['role'] ?? 'guest'));
    $isAuthenticated = (bool) ($user['isAuthenticated'] ?? false);
    $isAdmin = $role === 'admin';
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
            'contact' => true,
            'event' => true,
        ],
    ];
}
