<?php

declare(strict_types=1);

use Prime\Http\Router;
use Prime\Kernel\Application;
use Prime\Kernel\Config;
use Prime\Kernel\Env;
use Prime\Log\Logger;
use Prime\Security\Csrf;
use Prime\Settings\Settings;
use Prime\View\Icon;
use Prime\View\View;

if (!function_exists('app')) {
    /**
     * Retrieve the Application instance or resolve a service from its container.
     */
    function app(?string $id = null): mixed
    {
        $instance = Application::getInstance();

        return $id === null ? $instance : $instance->get($id);
    }
}

if (!function_exists('config')) {
    /**
     * Retrieve the Config repository or read a dot-notation key.
     */
    function config(?string $key = null, mixed $default = null): mixed
    {
        /** @var Config $repository */
        $repository = Application::getInstance()->get(Config::class);

        return $key === null ? $repository : $repository->get($key, $default);
    }
}

if (!function_exists('env')) {
    /**
     * Read an environment variable with automatic type conversion.
     */
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('e')) {
    /**
     * Escape a value for safe HTML output.
     */
    function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        if (!is_scalar($value) && !$value instanceof Stringable) {
            return '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url')) {
    /**
     * Generate an absolute URL from `APP_URL`, resolving named routes when matched.
     *
     * @param array<string, scalar|null> $params
     */
    function url(string $pathOrRoute = '/', array $params = []): string
    {
        if (str_starts_with($pathOrRoute, 'http://') || str_starts_with($pathOrRoute, 'https://')) {
            return $pathOrRoute;
        }

        $app = Application::getInstance();
        $baseUrl = rtrim((string) $app->config()->get('app.url', 'http://localhost:8080'), '/');

        if ($app->container()->has(Router::class)) {
            /** @var Router $router */
            $router = $app->get(Router::class);
            if ($router->hasRoute($pathOrRoute)) {
                return $baseUrl . $router->url($pathOrRoute, $params);
            }
        }

        $path = '/' . ltrim($pathOrRoute, '/');
        if ($params !== []) {
            $filteredParams = array_filter($params, static fn (mixed $v): bool => $v !== null);
            if ($filteredParams !== []) {
                $path .= '?' . http_build_query($filteredParams);
            }
        }

        return $baseUrl . $path;
    }
}

if (!function_exists('asset')) {
    /**
     * Generate a versioned URL for a static asset inside `public/assets/`.
     */
    function asset(string $path): string
    {
        $relative = ltrim($path, '/');
        $url = '/assets/' . $relative;

        if (Application::hasInstance()) {
            $fullPath = Application::getInstance()->publicPath('assets/' . $relative);
            if (is_file($fullPath)) {
                $mtime = filemtime($fullPath);
                if ($mtime !== false) {
                    $url .= '?v=' . $mtime;
                }
            }
        }

        return $url;
    }
}

if (!function_exists('view')) {
    /**
     * Render a PHP template through the View engine.
     *
     * @param array<string, mixed> $data
     */
    function view(string $template, array $data = []): string
    {
        /** @var View $engine */
        $engine = Application::getInstance()->get(View::class);

        return $engine->render($template, $data);
    }
}

if (!function_exists('logger')) {
    /**
     * Retrieve the application Logger instance.
     */
    function logger(): Logger
    {
        /** @var Logger $log */
        $log = Application::getInstance()->get(Logger::class);

        return $log;
    }
}

if (!function_exists('icon')) {
    /**
     * Render a Lucide SVG icon from the local admin sprite.
     */
    function icon(string $name, int $size = 20, string $class = ''): string
    {
        return Icon::render($name, $size, $class);
    }
}

if (!function_exists('setting')) {
    /**
     * Read a persistent site setting from `Prime\Settings\Settings`.
     */
    function setting(string $name, mixed $default = null): mixed
    {
        /** @var Settings $settings */
        $settings = Application::getInstance()->get(Settings::class);

        return $settings->get($name, $default);
    }
}

if (!function_exists('csrf_token')) {
    /**
     * Retrieve the active session CSRF token.
     */
    function csrf_token(): string
    {
        /** @var Csrf $csrf */
        $csrf = Application::getInstance()->get(Csrf::class);

        return $csrf->token();
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Render the hidden HTML input element carrying the active CSRF token.
     */
    function csrf_field(): string
    {
        /** @var Csrf $csrf */
        $csrf = Application::getInstance()->get(Csrf::class);

        return $csrf->field();
    }
}
