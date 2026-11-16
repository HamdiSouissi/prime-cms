<?php

declare(strict_types=1);

namespace Prime\I18n;

use Prime\Cache\CacheInterface;
use Prime\Hooks\Hooks;
use Prime\Kernel\ServiceProvider;
use Prime\Validation\Validator;

/**
 * Registers the internationalization layer: translations, locale-aware formatting, and the
 * language catalogue checker used by `php bin/prime lang:check`.
 */
final class I18nServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;
        $c = $app->container();

        $c->singleton(Translator::class, static function () use ($app, $c): Translator {
            $locale = (string) $app->config()->get('app.locale', Translator::DEFAULT_LOCALE);
            $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;

            // Catalogue trees are cached in the data cache: parsing hundreds of PHP files on
            // every request is the single most expensive part of the I18n layer.
            $cache = $c->has(CacheInterface::class) ? $c->get(CacheInterface::class) : null;

            $translator = new Translator(
                locale: $locale !== '' ? $locale : Translator::DEFAULT_LOCALE,
                fallbackLocale: Translator::FALLBACK_LOCALE,
                paths: self::languagePaths($app->contentPath(), $app->resourcesPath('lang')),
                hooks: $hooks instanceof Hooks ? $hooks : null,
                cache: $cache instanceof CacheInterface ? $cache : null,
            );

            return $translator;
        });

        $c->singleton(LocaleFormatter::class, static function () use ($app, $c): LocaleFormatter {
            /** @var Translator $translator */
            $translator = $c->get(Translator::class);
            $timezone = (string) $app->config()->get('app.timezone', 'UTC');

            return new LocaleFormatter($translator, $timezone !== '' ? $timezone : 'UTC');
        });

        $c->singleton(LanguageChecker::class, static function () use ($app, $c): LanguageChecker {
            /** @var Translator $translator */
            $translator = $c->get(Translator::class);

            return new LanguageChecker(
                langPath: $app->resourcesPath('lang'),
                reference: $translator->locale(),
                scanPaths: [
                    $app->resourcesPath('views'),
                    $app->basePath('src'),
                    $app->contentPath('themes'),
                    $app->contentPath('plugins'),
                ],
            );
        });
    }

    public function boot(): void
    {
        $c = $this->app->container();

        if (!$c->has(Translator::class)) {
            return;
        }

        /** @var Translator $translator */
        $translator = $c->get(Translator::class);

        // Global `t()`/`tc()` helpers, theme templates and CLI output share this instance.
        Translator::setInstance($translator);

        if ($c->has(LocaleFormatter::class)) {
            /** @var LocaleFormatter $formatter */
            $formatter = $c->get(LocaleFormatter::class);
            LocaleFormatter::setInstance($formatter);
        }

        // Validation messages are translated unless a caller provides its own message overrides.
        Validator::setTranslator($translator);

        // Plugins and themes may register inline catalogues (`$translator->register()` or `addPath()`).
        $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;
        if ($hooks instanceof Hooks) {
            $hooks->apply('translations.register', $translator);
        }
    }

    /**
     * Core language directory plus every `lang/` folder shipped by installed themes and plugins.
     *
     * @return list<string>
     */
    public static function languagePaths(string $contentPath, string $corePath): array
    {
        $paths = [$corePath];

        foreach (['themes', 'plugins'] as $kind) {
            $dirs = @glob($contentPath . '/' . $kind . '/*/lang') ?: [];
            foreach ($dirs as $dir) {
                if (is_dir($dir)) {
                    $paths[] = (string) $dir;
                }
            }
        }

        return $paths;
    }
}
