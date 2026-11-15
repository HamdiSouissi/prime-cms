<?php

declare(strict_types=1);

use Prime\Kernel\Env;

return [
    'level' => (string) Env::get('LOG_LEVEL', 'debug'),
    'path' => dirname(__DIR__) . '/storage/logs',
    'prefix' => 'prime',
];
