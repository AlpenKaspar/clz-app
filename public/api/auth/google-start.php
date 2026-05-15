<?php

declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';

try {
    $clientId = trim((string) env('GOOGLE_CLIENT_ID', ''));
    if (!google_oauth_is_configured()) {
        json_error('Google Login ist noch nicht konfiguriert.', 500);
    }

    start_app_session();
    $state = bin2hex(random_bytes(24));
    $_SESSION['google_oauth_state'] = $state;

    $params = http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => google_oauth_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'access_type' => 'online',
        'prompt' => 'select_account',
    ]);

    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params, true, 302);
    exit;
} catch (Throwable $e) {
    json_error('Google Login konnte nicht gestartet werden.', 500, [
        'detail' => env('APP_DEBUG', '0') === '1' ? $e->getMessage() : null,
    ]);
}
