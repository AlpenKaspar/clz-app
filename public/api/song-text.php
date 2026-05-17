<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

try {
    require_user();
    $url = trim((string) ($_GET['url'] ?? ''));
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
        json_error('Datei-URL fehlt.', 400);
    }
    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        json_error('Nur HTTP/HTTPS-Dateien sind erlaubt.', 400);
    }
    $path = strtolower((string) ($parts['path'] ?? ''));
    if (!preg_match('/\.(chopro|cho|chordpro|crd|pro|onsong|txt|html?)$/', $path)) {
        json_error('Diese Datei ist kein unterstuetztes Text-/Chord-Format.', 400);
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 12,
            'follow_location' => 1,
            'max_redirects' => 3,
            'header' => "User-Agent: CLZ-App-SongText/1.0\r\nAccept: text/plain,text/html,*/*;q=0.8\r\n",
        ],
    ]);
    $handle = @fopen($url, 'rb', false, $context);
    if (!is_resource($handle)) {
        json_error('Songtext konnte nicht geladen werden.', 502);
    }
    $raw = stream_get_contents($handle, 350000);
    fclose($handle);
    if (!is_string($raw) || $raw === '') {
        json_error('Songtext konnte nicht geladen werden.', 502);
    }
    if (strlen($raw) >= 350000) {
        json_error('Songtext ist zu gross zum Anzeigen.', 413);
    }

    if (function_exists('mb_check_encoding') && function_exists('mb_convert_encoding') && !mb_check_encoding($raw, 'UTF-8')) {
        $converted = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252, ISO-8859-1, UTF-8');
        if (is_string($converted) && $converted !== '') {
            $raw = $converted;
        }
    }

    json_response([
        'ok' => true,
        'url' => $url,
        'text' => $raw,
    ]);
} catch (Throwable $e) {
    json_error('Songtext konnte nicht geladen werden.', 500, [
        'detail' => env('APP_DEBUG', '0') === '1' ? $e->getMessage() : null,
    ]);
}
