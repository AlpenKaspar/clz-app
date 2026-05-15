<?php

declare(strict_types=1);

function env(string $key, ?string $default = null): ?string
{
    static $loaded = false;
    static $values = [];

    if (!$loaded) {
        $path = dirname(__DIR__) . '/.env';
        if (is_file($path) && is_readable($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$name, $value] = explode('=', $line, 2);
                $values[trim($name)] = trim($value);
            }
        }
        $loaded = true;
    }

    $serverValue = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($serverValue !== false && $serverValue !== null && $serverValue !== '') {
        return (string) $serverValue;
    }

    return $values[$key] ?? $default;
}

