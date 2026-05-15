<?php

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/http.php';
require __DIR__ . '/db.php';
require __DIR__ . '/elvanto.php';

date_default_timezone_set(env('APP_TIMEZONE', 'Europe/Zurich'));

