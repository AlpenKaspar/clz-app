<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

try {
    $stmt = db()->query('SELECT 1 AS ok');
    $row = $stmt->fetch();
    json_response([
        'ok' => true,
        'database' => 'connected',
        'result' => (int) ($row['ok'] ?? 0),
        'ts' => date('c'),
    ]);
} catch (Throwable $e) {
    json_error('Datenbankverbindung fehlgeschlagen.', 500, [
        'detail' => env('APP_DEBUG', '0') === '1' ? $e->getMessage() : null,
    ]);
}

