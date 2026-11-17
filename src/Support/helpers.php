<?php

declare(strict_types=1);

use Prime\Http\Router;
use Prime\I18n\LocaleFormatter;
use Prime\I18n\Translator;
use Prime\Kernel\Application;
use Prime\Kernel\Config;
use Prime\Kernel\Env;
use Prime\Log\Logger;
use Prime\Media\Media;
use Prime\Media\MediaHelper;
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

if (!function_exists('media_url')) {
    /**
     * Generate the public URL of a media or one of its generated variants (`thumbnail`, `medium`, `large`).
     */
    function media_url(int|Media|null $media, ?string $variant = null, ?string $format = null): ?string
    {
        return MediaHelper::url($media, $variant, $format);
    }
}

if (!function_exists('media_srcset')) {
    /**
     * Build a responsive `srcset` attribute string for an image media.
     *
     * @param 'original'|'webp'|null $format
     */
    function media_srcset(int|Media|null $media, ?string $format = null): string
    {
        return MediaHelper::srcset($media, $format);
    }
}

if (!function_exists('media_image')) {
    /**
     * Render an accessible responsive `<picture>` / `<img>` HTML tag for an image media.
     *
     * @param array<string, mixed> $attrs
     */
    function media_image(int|Media|null $media, array $attrs = []): string
    {
        return MediaHelper::image($media, $attrs);
    }
}
if (!function_exists('t')) {
    /**
     * Translate a `group.key` string using the active locale.
     *
     * @param array<string|int, mixed> $replace
     */
    function t(string $key, array $replace = [], ?string $locale = null): string
    {
        if (!Application::hasInstance() || !Application::getInstance()->container()->has(Translator::class)) {
            return Translator::fallback()->translate($key, $replace, $locale);
        }

        /** @var Translator $translator */
        $translator = Application::getInstance()->get(Translator::class);

        return $translator->translate($key, $replace, $locale);
    }
}

if (!function_exists('__t')) {
    /**
     * Alias of {@see t()} kept for templates ported from WordPress-style themes.
     *
     * @param array<string|int, mixed> $replace
     */
    function __t(string $key, array $replace = [], ?string $locale = null): string
    {
        return t($key, $replace, $locale);
    }
}

if (!function_exists('tc')) {
    /**
     * Translate a pluralized key (`one|other`) for a given count.
     *
     * @param array<string|int, mixed> $replace
     */
    function tc(string $key, int|float $number, array $replace = [], ?string $locale = null): string
    {
        if (!Application::hasInstance() || !Application::getInstance()->container()->has(Translator::class)) {
            return Translator::fallback()->choice($key, $number, $replace, $locale);
        }

        /** @var Translator $translator */
        $translator = Application::getInstance()->get(Translator::class);

        return $translator->choice($key, $number, $replace, $locale);
    }
}

if (!function_exists('current_locale')) {
    /**
     * Active interface locale (falls back to the Prime CMS default outside an application context).
     */
    function current_locale(): string
    {
        if (!Application::hasInstance() || !Application::getInstance()->container()->has(Translator::class)) {
            return Translator::fallback()->locale();
        }

        /** @var Translator $translator */
        $translator = Application::getInstance()->get(Translator::class);

        return $translator->locale();
    }
}

if (!function_exists('format_date')) {
    /**
     * Format a date according to the active locale (`short`, `medium`, `long`, `full`).
     */
    function format_date(DateTimeInterface|int|string $date, string $preset = 'medium'): string
    {
        return locale_formatter()->date($date, $preset);
    }
}

if (!function_exists('format_datetime')) {
    /**
     * Format a date and time according to the active locale.
     */
    function format_datetime(DateTimeInterface|int|string $date, string $preset = 'dateTime'): string
    {
        return locale_formatter()->dateTime($date, $preset);
    }
}

if (!function_exists('format_number')) {
    /**
     * Format a number using the active locale separators.
     */
    function format_number(int|float $value, ?int $decimals = null): string
    {
        return locale_formatter()->number($value, $decimals);
    }
}

if (!function_exists('locale_formatter')) {
    /**
     * Resolve the locale-aware formatter, or a detached instance outside an application context.
     */
    function locale_formatter(): LocaleFormatter
    {
        if (Application::hasInstance() && Application::getInstance()->container()->has(LocaleFormatter::class)) {
            /** @var LocaleFormatter $formatter */
            $formatter = Application::getInstance()->get(LocaleFormatter::class);

            return $formatter;
        }

        return new LocaleFormatter(new Translator());
    }
}
