<?php

declare(strict_types=1);

use Prime\Kernel\Env;

return [
    'host' => (string) Env::get('DB_HOST', '127.0.0.1'),
    'port' => (int) Env::get('DB_PORT', 3306),
    'database' => (string) Env::get('DB_DATABASE', 'prime_cms'),
    'username' => (string) Env::get('DB_USERNAME', 'prime'),
    'password' => (string) Env::get('DB_PASSWORD', ''),
    'prefix' => (string) Env::get('DB_PREFIX', 'pc_'),
    'charset' => (string) Env::get('DB_CHARSET', 'utf8mb4'),
    'collation' => (string) Env::get('DB_COLLATION', 'utf8mb4_unicode_ci'),
];
