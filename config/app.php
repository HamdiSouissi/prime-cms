<?php

declare(strict_types=1);

use Prime\Admin\AdminServiceProvider;
use Prime\Database\DatabaseServiceProvider;
use Prime\Http\Middleware\RedirectIfNotInstalled;
use Prime\Http\Middleware\SecurityHeaders;
use Prime\Http\Middleware\TrailingSlash;
use Prime\Http\Middleware\VerifyCsrfToken;
use Prime\Install\InstallServiceProvider;
use Prime\Kernel\Env;
use Prime\Kernel\Providers\HooksServiceProvider;
use Prime\Kernel\Providers\HttpServiceProvider;
use Prime\Kernel\Providers\LogServiceProvider;
use Prime\Kernel\Providers\ViewServiceProvider;
use Prime\Settings\SettingsServiceProvider;

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
        DatabaseServiceProvider::class,
        SettingsServiceProvider::class,
        HttpServiceProvider::class,
        InstallServiceProvider::class,
        AdminServiceProvider::class,
    ],

    'middleware' => [
        SecurityHeaders::class,
        TrailingSlash::class,
        RedirectIfNotInstalled::class,
    ],

    'route_middleware' => [
        VerifyCsrfToken::class,
    ],
];
