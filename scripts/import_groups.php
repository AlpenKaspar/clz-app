<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/import_groups.php';

try {
    $result = import_groups();
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

