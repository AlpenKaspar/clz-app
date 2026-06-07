<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/import_people.php';

try {
    $args = array_slice($argv ?? [], 1);
    $result = import_people([
        'smart' => in_array('--smart', $args, true),
        'force' => in_array('--force', $args, true) || in_array('--full', $args, true),
    ]);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
