<?php

declare(strict_types=1);

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400, array $extra = []): never
{
    json_response(array_merge([
        'ok' => false,
        'error' => $message,
    ], $extra), $status);
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        json_error('Ungueltiger JSON-Body.', 400);
    }

    return $decoded;
}

function require_admin_token(): void
{
    $expected = trim((string) env('ADMIN_IMPORT_TOKEN', ''));
    if ($expected === '') {
        json_error('ADMIN_IMPORT_TOKEN ist nicht konfiguriert.', 500);
    }

    $provided = '';
    $header = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
    if (is_string($header) && trim($header) !== '') {
        $provided = trim($header);
    } elseif (isset($_GET['token'])) {
        $provided = trim((string) $_GET['token']);
    }

    if ($provided === '' || !hash_equals($expected, $provided)) {
        json_error('Nicht autorisiert.', 403);
    }
}
