<?php

declare(strict_types=1);

use Prime\Kernel\Env;

return [
    // Supported drivers in Phase 4: 'log' (default, writes to storage/logs/mail-YYYY-MM-DD.log) and 'native' (PHP mail()).
    'driver' => Env::get('MAIL_DRIVER', 'log'),

    'from' => [
        'address' => Env::get('MAIL_FROM_ADDRESS', 'no-reply@prime-cms.local'),
        'name' => Env::get('MAIL_FROM_NAME', 'Prime CMS'),
    ],
];
