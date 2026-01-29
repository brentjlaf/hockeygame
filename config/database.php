<?php

declare(strict_types=1);

$envPath = __DIR__ . '/.env.php';
$env = [];

if (is_file($envPath)) {
    $loaded = require $envPath;
    if (is_array($loaded)) {
        $env = $loaded;
    }
}

return [
    'host' => $env['DB_HOST'] ?? (getenv('DB_HOST') ?: 'localhost'),
    'name' => $env['DB_NAME'] ?? (getenv('DB_NAME') ?: 'hockeysim'),
    'user' => $env['DB_USER'] ?? (getenv('DB_USER') ?: 'root'),
    'pass' => $env['DB_PASS'] ?? (getenv('DB_PASS') ?: ''),
];
