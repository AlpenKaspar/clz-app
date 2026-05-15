<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

json_response([
    'ok' => true,
    'service' => 'clz-app',
    'environment' => env('APP_ENV', 'production'),
    'timezone' => date_default_timezone_get(),
    'dataVersion' => ping_data_version(),
    'ts' => date('c'),
]);

function ping_data_version(): string
{
    try {
        $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
        $stmt->execute(['DATA_VERSION']);
        $value = $stmt->fetchColumn();
        return is_string($value) && $value !== '' ? $value : '1';
    } catch (Throwable) {
        return '1';
    }
}
