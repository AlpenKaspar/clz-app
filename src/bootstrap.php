<?php

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/http.php';
require __DIR__ . '/db.php';
require __DIR__ . '/settings.php';
require __DIR__ . '/elvanto.php';
require __DIR__ . '/auth.php';

date_default_timezone_set(env('APP_TIMEZONE', 'Europe/Zurich'));
