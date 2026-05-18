<?php

declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';

try {
    require_admin_token();

    $enabled = function_exists('opcache_reset');
    $reset = $enabled ? @opcache_reset() : false;

    json_response([
        'ok' => true,
        'opcacheAvailable' => $enabled,
        'reset' => (bool) $reset,
        'ts' => date('c'),
    ]);
} catch (Throwable $e) {
    json_error('OPcache konnte nicht zurueckgesetzt werden.', 500, [
        'detail' => env('APP_DEBUG', '0') === '1' ? $e->getMessage() : null,
    ]);
}
