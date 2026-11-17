<?php

declare(strict_types=1);

namespace Prime\Settings;

use DateTimeZone;
use Prime\Auth\RoleRepository;
use Prime\Cache\CacheInterface;
use Prime\Content\EntryQuery;
use Prime\Content\TermRepository;
use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\I18n\LocaleFormatter;
use Prime\I18n\Translator;
use Prime\Kernel\Container;
use Prime\Kernel\ServiceProvider;
use Prime\Security\Encrypter;
use Throwable;

/**
 * Registers the settings store, its declarative registry, the lazy option sources, and the form
 * processor shared by every settings writer (admin screens, CLI, plugins).
 */
final class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;
        $c = $app->container();

        $c->singleton(Settings::class, static function (Container $c): Settings {
            /** @var Connection $connection */
            $connection = $c->get(Connection::class);
            $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;
            $cache = $c->has(CacheInterface::class) ? $c->get(CacheInterface::class) : null;

            // `Encrypter` needs a valid APP_KEY: a site mid-installation keeps working without secrets.
            $crypt = null;
            try {
                $candidate = $c->has(Encrypter::class) ? $c->get(Encrypter::class) : null;
                $crypt = $candidate instanceof Encrypter ? $candidate : null;
            } catch (Throwable) {
                $crypt = null;
            }

            return new Settings(
                $connection,
                $hooks instanceof Hooks ? $hooks : null,
                $cache instanceof CacheInterface ? $cache : null,
                $crypt,
            );
        });

        $c->singleton(SettingsOptionSources::class, static function (Container $c): SettingsOptionSources {
            $sources = new SettingsOptionSources();

            $sources->provide('timezones', static function (): array {
                $options = [];
                foreach (DateTimeZone::listIdentifiers() as $identifier) {
                    $options[$identifier] = $identifier;
                }

                return $options;
            });

            $sources->provide('locales', static function () use ($c): array {
                if (!$c->has(Translator::class)) {
                    return ['fr' => 'Français', 'en' => 'English'];
                }

                /** @var Translator $translator */
                $translator = $c->get(Translator::class);
                $formatter = $c->has(LocaleFormatter::class) ? $c->get(LocaleFormatter::class) : null;

                $options = [];
                foreach ($translator->availableLocales() as $locale) {
                    $options[$locale] = $formatter instanceof LocaleFormatter
                        ? $formatter->localeName($locale)
                        : strtoupper($locale);
                }

                return $options === [] ? ['fr' => 'Français', 'en' => 'English'] : $options;
            });

            $sources->provide('roles', static function () use ($c): array {
                if (!$c->has(RoleRepository::class)) {
                    return [];
                }

                /** @var RoleRepository $roles */
                $roles = $c->get(RoleRepository::class);
                $options = [];

                foreach ($roles->all() as $role) {
                    $options[$role->slug] = $role->label();
                }

                return $options;
            });

            $sources->provide('pages', static function () use ($c): array {
                return self::entryOptions($c, 'page');
            });

            $sources->provide('categories', static function () use ($c): array {
                return self::termOptions($c, 'category');
            });

            $sources->provide('tags', static function () use ($c): array {
                return self::termOptions($c, 'post_tag');
            });

            return $sources;
        });

        $c->singleton(SettingsRegistry::class, static function (Container $c) use ($app): SettingsRegistry {
            $registry = new SettingsRegistry();

            $config = $app->config()->get('settings', []);
            if (is_array($config)) {
                /** @var array<string, mixed> $config */
                $registry->register(
                    is_array($config['sections'] ?? null) ? $config['sections'] : [],
                    is_array($config['fields'] ?? null) ? $config['fields'] : [],
                    'core',
                );
            }

            if ($c->has(SettingsOptionSources::class)) {
                /** @var SettingsOptionSources $sources */
                $sources = $c->get(SettingsOptionSources::class);
                $registry->setSources($sources);
            }

            return $registry;
        });

        $c->singleton(SettingsFormProcessor::class, static function (Container $c): SettingsFormProcessor {
            /** @var SettingsRegistry $registry */
            $registry = $c->get(SettingsRegistry::class);
            /** @var Settings $settings */
            $settings = $c->get(Settings::class);
            $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;

            return new SettingsFormProcessor($registry, $settings, $hooks instanceof Hooks ? $hooks : null);
        });
    }

    public function boot(): void
    {
        $app = $this->app;
        $c = $app->container();

        if (!$c->has(SettingsRegistry::class)) {
            return;
        }

        /** @var SettingsRegistry $registry */
        $registry = $c->get(SettingsRegistry::class);

        // Extensions declare their settings during `app.booted`, then the catalogue is frozen.
        $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;
        if ($hooks instanceof Hooks) {
            $hooks->on('app.booted', static function () use ($registry, $hooks): void {
                $hooks->apply('settings.register', $registry);
                $registry->lock();
            }, 5);
        }
    }

    /**
     * @return array<int, string>
     */
    private static function entryOptions(Container $c, string $type): array
    {
        if (!$c->has(EntryQuery::class)) {
            return [];
        }

        try {
            /** @var EntryQuery $query */
            $query = $c->get(EntryQuery::class);
            $rows = $query->newQuery()
                ->type($type)
                ->status('publish')
                ->orderBy('title', 'ASC')
                ->limit(300)
                ->get();
        } catch (Throwable) {
            return [];
        }

        $options = [];
        foreach ($rows as $entry) {
            $options[$entry->id] = $entry->title !== '' ? $entry->title : '#' . $entry->id;
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private static function termOptions(Container $c, string $taxonomy): array
    {
        if (!$c->has(TermRepository::class)) {
            return [];
        }

        try {
            /** @var TermRepository $terms */
            $terms = $c->get(TermRepository::class);
            $rows = $terms->allForTaxonomy($taxonomy);
        } catch (Throwable) {
            return [];
        }

        $options = [];
        foreach ($rows as $term) {
            $options[$term->id] = $term->name;
        }

        return $options;
    }
}
