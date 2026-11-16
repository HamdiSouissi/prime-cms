<?php

declare(strict_types=1);

use Prime\Admin\AdminServiceProvider;
use Prime\Auth\AuthServiceProvider;
use Prime\Content\ContentServiceProvider;
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
use Prime\Mail\MailServiceProvider;
use Prime\Media\MediaServiceProvider;
use Prime\Plugins\PluginServiceProvider;
use Prime\Settings\SettingsServiceProvider;
use Prime\Themes\ThemeServiceProvider;

return [
    'name' => (string) Env::get('APP_NAME', 'Prime CMS'),
    'key' => (string) Env::get('APP_KEY', ''),
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
        MailServiceProvider::class,
        HttpServiceProvider::class,
        AuthServiceProvider::class,
        InstallServiceProvider::class,
        MediaServiceProvider::class,
        ContentServiceProvider::class,
        AdminServiceProvider::class,
        PluginServiceProvider::class,
        ThemeServiceProvider::class,
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
