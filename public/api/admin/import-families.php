<?php

declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';
require __DIR__ . '/../../../src/import_families.php';

try {
    require_admin_token();
    $result = import_families();
    json_response($result);
} catch (Throwable $e) {
    json_error('Familienimport fehlgeschlagen.', 500, [
        'detail' => env('APP_DEBUG', '0') === '1' ? $e->getMessage() : null,
    ]);
}

