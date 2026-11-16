<?php

declare(strict_types=1);

use Prime\Kernel\Env;

return [
    // Supported data-cache drivers: 'file' (default, storage/cache/data), 'apcu' (falls back to
    // 'file' when the extension is unavailable) and 'array' (tests and isolated CLI runs).
    'driver' => Env::get('CACHE_DRIVER', 'file'),

    // Default TTL applied by plugin/theme helper calls that omit an explicit lifetime.
    'default_ttl' => (int) Env::get('CACHE_TTL', 3600),

    'page' => [
        // Full-page cache for anonymous public GET requests (storage/cache/pages).
        'enabled' => (bool) Env::get('CACHE_PAGE_ENABLED', true),
        'ttl' => (int) Env::get('CACHE_PAGE_TTL', 600),
    ],
];
