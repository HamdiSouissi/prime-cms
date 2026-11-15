<?php

declare(strict_types=1);

use Prime\Kernel\Env;

$storageRoot = Env::get('PRIME_STORAGE_PATH');
$defaultLogPath = is_string($storageRoot) && trim($storageRoot) !== ''
    ? rtrim(trim($storageRoot), DIRECTORY_SEPARATOR . '/') . '/logs'
    : dirname(__DIR__) . '/storage/logs';

return [
    'level' => (string) Env::get('LOG_LEVEL', 'debug'),
    'path' => (string) Env::get('LOG_PATH', $defaultLogPath),
    'prefix' => 'prime',
];
