<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

json_response([
    'ok' => true,
    'service' => 'clz-app',
    'environment' => env('APP_ENV', 'production'),
    'timezone' => date_default_timezone_get(),
    'ts' => date('c'),
]);

