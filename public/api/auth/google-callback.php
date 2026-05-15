<?php

declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';

try {
    start_app_session();
    $expectedState = (string) ($_SESSION['google_oauth_state'] ?? '');
    $state = (string) ($_GET['state'] ?? '');
    unset($_SESSION['google_oauth_state']);

    if ($expectedState === '' || $state === '' || !hash_equals($expectedState, $state)) {
        throw new RuntimeException('Ungueltiger Login-State.');
    }

    $code = trim((string) ($_GET['code'] ?? ''));
    if ($code === '') {
        throw new RuntimeException('Google hat keinen Login-Code geliefert.');
    }

    $token = google_exchange_code_for_token($code);
    $profile = google_fetch_user_profile((string) ($token['access_token'] ?? ''));
    login_user_from_google($profile);

    header('Location: /', true, 302);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    $detail = env('APP_DEBUG', '0') === '1' ? '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>' : '';
    echo '<!doctype html><html lang="de"><meta charset="utf-8"><title>Login Fehler</title><body><h1>Login fehlgeschlagen</h1><p>Bitte versuche es erneut.</p>' . $detail . '<p><a href="/">Zurueck zur App</a></p></body></html>';
    exit;
}

function google_exchange_code_for_token(string $code): array
{
    $body = http_build_query([
        'code' => $code,
        'client_id' => trim((string) env('GOOGLE_CLIENT_ID', '')),
        'client_secret' => trim((string) env('GOOGLE_CLIENT_SECRET', '')),
        'redirect_uri' => google_oauth_redirect_uri(),
        'grant_type' => 'authorization_code',
    ]);

    $response = google_http_request('https://oauth2.googleapis.com/token', [
        'Content-Type: application/x-www-form-urlencoded',
    ], $body);

    if (!isset($response['access_token'])) {
        throw new RuntimeException('Google Token-Antwort ist unvollstaendig.');
    }
    return $response;
}

function google_fetch_user_profile(string $accessToken): array
{
    if ($accessToken === '') {
        throw new RuntimeException('Access Token fehlt.');
    }

    return google_http_request('https://openidconnect.googleapis.com/v1/userinfo', [
        'Authorization: Bearer ' . $accessToken,
    ]);
}

function google_http_request(string $url, array $headers, ?string $body = null): array
{
    $context = stream_context_create([
        'http' => [
            'method' => $body === null ? 'GET' : 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'ignore_errors' => true,
            'timeout' => 20,
        ],
    ]);

    $raw = file_get_contents($url, false, $context);
    if ($raw === false) {
        throw new RuntimeException('Google Anfrage fehlgeschlagen.');
    }

    $statusLine = $http_response_header[0] ?? '';
    if (!str_contains($statusLine, ' 200 ')) {
        throw new RuntimeException('Google Antwort war nicht erfolgreich: ' . $statusLine);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Google Antwort ist kein JSON.');
    }
    return $decoded;
}
