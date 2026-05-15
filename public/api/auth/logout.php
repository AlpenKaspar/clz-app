<?php

declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';

logout_user();
header('Location: /', true, 302);
exit;
