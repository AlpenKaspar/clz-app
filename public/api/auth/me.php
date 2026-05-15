<?php

declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';

try {
    json_response([
        'ok' => true,
        'user' => current_user() ?? guest_user_payload(),
        'googleConfigured' => google_oauth_is_configured(),
    ]);
} catch (Throwable $e) {
    json_error('Loginstatus konnte nicht geladen werden.', 500, [
        'detail' => env('APP_DEBUG', '0') === '1' ? $e->getMessage() : null,
    ]);
}
