<?php

declare(strict_types=1);

$args = $argv ?? [__FILE__];
array_splice($args, 1, 0, '--mode=week');
$argv = $args;

require __DIR__ . '/send_birthday_notifications.php';
