<?php

declare(strict_types=1);

namespace Prime\Themes;

use Prime\Admin\Notices;
use Prime\Hooks\Hooks;
use Prime\Install\InstallCheckerInterface;
use Prime\Kernel\Autoloader;
use Prime\Kernel\Container;
use Prime\Kernel\ServiceProvider;
use Prime\Log\Logger;
use Prime\Media\MediaRepository;
use Prime\Settings\Settings;

/**
 * Registers the Prime CMS theme engine (`Prime\Themes`) into the application container
 * and boots the active theme with fatal-error isolation.
 */
final class ThemeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $container = $this->app->container();
        $app = $this->app;

        $container->singleton(ThemeManifestValidator::class, static fn (): ThemeManifestValidator => new ThemeManifestValidator());

        $container->singleton(ThemeAssetPublisher::class, static fn (): ThemeAssetPublisher => new ThemeAssetPublisher(
            publicThemesRoot: $app->publicPath('assets/themes'),
            publicUrlPrefix: '/assets/themes',
        ));

        $container->singleton(ThemeAssetRegistry::class, static fn (): ThemeAssetRegistry => new ThemeAssetRegistry(
            publicRoot: $app->publicPath(),
        ));

        $container->singleton(ThemeOptions::class, static function (Container $c) use ($app): ThemeOptions {
            $settings = $c->has(Settings::class) ? $c->get(Settings::class) : null;
            $media = $c->has(MediaRepository::class) ? $c->get(MediaRepository::class) : null;

            return new ThemeOptions(
                settings: $settings instanceof Settings ? $settings : null,
                publisher: $c->get(ThemeAssetPublisher::class),
                publicRoot: $app->publicPath(),
                media: $media instanceof MediaRepository ? $media : null,
            );
        });

        $container->singleton(PublicCsp::class, static function (Container $c): PublicCsp {
            $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;

            return new PublicCsp($hooks instanceof Hooks ? $hooks : null);
        });

        $container->singleton(ThemeManager::class, static function (Container $c) use ($app): ThemeManager {
            $themesPath = (string) $app->config()->get('themes.path', $app->contentPath('themes'));
            $settings = $c->has(Settings::class) ? $c->get(Settings::class) : null;
            $autoloader = $c->has(Autoloader::class) ? $c->get(Autoloader::class) : null;
            $notices = $c->has(Notices::class) ? $c->get(Notices::class) : null;
            $logger = $c->has(Logger::class) ? $c->get(Logger::class) : null;

            return new ThemeManager(
                app: $app,
                themesPath: $themesPath,
                validator: $c->get(ThemeManifestValidator::class),
                publisher: $c->get(ThemeAssetPublisher::class),
                assets: $c->get(ThemeAssetRegistry::class),
                options: $c->get(ThemeOptions::class),
                hooks: $c->get(Hooks::class),
                settings: $settings instanceof Settings ? $settings : null,
                autoloader: $autoloader instanceof Autoloader ? $autoloader : null,
                notices: $notices instanceof Notices ? $notices : null,
                logger: $logger instanceof Logger ? $logger : null,
            );
        });

        $container->singleton(ThemeInstaller::class, static function (Container $c) use ($app): ThemeInstaller {
            $themesPath = (string) $app->config()->get('themes.path', $app->contentPath('themes'));

            return new ThemeInstaller(
                themesPath: $themesPath,
                tmpPath: $app->storagePath('tmp'),
                validator: $c->get(ThemeManifestValidator::class),
                manager: $c->get(ThemeManager::class),
                hooks: $c->get(Hooks::class),
                maxUploadMb: (int) $app->config()->get('themes.max_upload_mb', 20),
                maxZipFiles: (int) $app->config()->get('themes.max_zip_files', 2000),
                maxUncompressedBytes: (int) $app->config()->get('themes.max_uncompressed_bytes', 104857600),
                maxCompressionRatio: (float) $app->config()->get('themes.max_compression_ratio', 100.0),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->container()->has(InstallCheckerInterface::class)) {
            /** @var InstallCheckerInterface $checker */
            $checker = $this->app->make(InstallCheckerInterface::class);
            if (!$checker->isInstalled()) {
                return;
            }
        }

        /** @var ThemeManager $manager */
        $manager = $this->app->get(ThemeManager::class);
        $manager->bootActive();
    }
}
