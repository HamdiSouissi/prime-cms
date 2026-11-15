<?php

declare(strict_types=1);

use Prime\Http\Middleware\SecurityHeaders;
use Prime\Http\Middleware\TrailingSlash;
use Prime\Kernel\Env;
use Prime\Kernel\Providers\HooksServiceProvider;
use Prime\Kernel\Providers\HttpServiceProvider;
use Prime\Kernel\Providers\LogServiceProvider;
use Prime\Kernel\Providers\ViewServiceProvider;

return [
    'name' => (string) Env::get('APP_NAME', 'Prime CMS'),
    'env' => (string) Env::get('APP_ENV', 'production'),
    'debug' => (bool) Env::get('APP_DEBUG', false),
    'url' => rtrim((string) Env::get('APP_URL', 'http://localhost:8080'), '/'),
    'timezone' => (string) Env::get('APP_TIMEZONE', 'UTC'),
    'locale' => (string) Env::get('APP_LOCALE', 'fr'),

    'providers' => [
        HooksServiceProvider::class,
        LogServiceProvider::class,
        ViewServiceProvider::class,
        HttpServiceProvider::class,
    ],

    'middleware' => [
        SecurityHeaders::class,
        TrailingSlash::class,
    ],
];
