<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/import_service_details.php';

try {
    $force = in_array('--force', $argv ?? [], true);
    $limit = null;
    $footprintLimit = 3;
    foreach ($argv ?? [] as $arg) {
        if (preg_match('/^--limit=(\d+)$/', (string) $arg, $m)) {
            $limit = max(1, (int) $m[1]);
        }
        if (preg_match('/^--footprint-limit=(\d+)$/', (string) $arg, $m)) {
            $footprintLimit = max(1, (int) $m[1]);
        }
    }

    $result = import_service_details($limit, $force, $footprintLimit);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
