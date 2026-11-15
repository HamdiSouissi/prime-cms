<?php

declare(strict_types=1);

namespace Prime\Plugins;

use Prime\Admin\Menu\AdminMenu;
use Prime\Admin\Notices;
use Prime\Auth\Capabilities;
use Prime\Database\Connection;
use Prime\Database\Migrations\Migrator;
use Prime\Database\Schema\Schema;
use Prime\Hooks\Hooks;
use Prime\Http\Router;
use Prime\Install\InstallCheckerInterface;
use Prime\Kernel\Autoloader;
use Prime\Kernel\ServiceProvider;
use Prime\Log\Logger;
use Prime\Settings\Settings;
use Prime\View\View;

/**
 * Registers all Phase 5 plugin infrastructure services and boots active plugins
 * once all core providers have finished booting.
 */
final class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;
        $c = $app->container();

        $c->singleton(ManifestValidator::class, static fn (): ManifestValidator => new ManifestValidator());
        $c->singleton(DependencyResolver::class, static fn (): DependencyResolver => new DependencyResolver());

        $c->singleton(Notices::class, static function () use ($c): Notices {
            /** @var Settings $settings */
            $settings = $c->make(Settings::class);
            $logger = $c->has(Logger::class) ? $c->get(Logger::class) : null;

            return new Notices($settings, $logger instanceof Logger ? $logger : null);
        });

        $c->singleton(PluginRepository::class, static function () use ($c): PluginRepository {
            /** @var Connection $connection */
            $connection = $c->make(Connection::class);
            /** @var Schema $schema */
            $schema = $c->make(Schema::class);

            return new PluginRepository($connection, $schema);
        });

        $c->singleton(AssetPublisher::class, static function () use ($app): AssetPublisher {
            $relPath = (string) $app->config()->get('plugins.public_assets_path', 'public/assets/plugins');
            $urlPrefix = (string) $app->config()->get('plugins.public_assets_url', '/assets/plugins');

            return new AssetPublisher($app->basePath($relPath), $urlPrefix);
        });

        $c->singleton(FatalLoadGuard::class, static function () use ($app): FatalLoadGuard {
            return new FatalLoadGuard($app->storagePath('plugins/loading.json'));
        });

        $c->singleton(PluginManager::class, static function () use ($app, $c): PluginManager {
            $pluginsRelPath = (string) $app->config()->get('plugins.path', 'content/plugins');
            $safeMode = (bool) $app->config()->get('plugins.safe_mode', false);

            if ($c->has(Autoloader::class)) {
                /** @var Autoloader $autoloader */
                $autoloader = $c->make(Autoloader::class);
            } else {
                $autoloader = new Autoloader();
                $autoloader->register();
                $c->instance(Autoloader::class, $autoloader);
            }
            /** @var PluginRepository $repository */
            $repository = $c->make(PluginRepository::class);
            /** @var ManifestValidator $validator */
            $validator = $c->make(ManifestValidator::class);
            /** @var DependencyResolver $resolver */
            $resolver = $c->make(DependencyResolver::class);
            /** @var AssetPublisher $publisher */
            $publisher = $c->make(AssetPublisher::class);
            /** @var FatalLoadGuard $guard */
            $guard = $c->make(FatalLoadGuard::class);
            /** @var Migrator $migrator */
            $migrator = $c->make(Migrator::class);
            /** @var Hooks $hooks */
            $hooks = $c->make(Hooks::class);
            /** @var Router $router */
            $router = $c->make(Router::class);
            /** @var View $views */
            $views = $c->make(View::class);
            /** @var AdminMenu $adminMenu */
            $adminMenu = $c->make(AdminMenu::class);
            /** @var Capabilities $capabilities */
            $capabilities = $c->make(Capabilities::class);
            /** @var Settings $settings */
            $settings = $c->make(Settings::class);
            /** @var Connection $db */
            $db = $c->make(Connection::class);
            /** @var Notices $notices */
            $notices = $c->make(Notices::class);
            /** @var Logger $logger */
            $logger = $c->make(Logger::class);

            return new PluginManager(
                app: $app,
                pluginsPath: $app->basePath($pluginsRelPath),
                autoloader: $autoloader,
                repository: $repository,
                validator: $validator,
                resolver: $resolver,
                assetPublisher: $publisher,
                fatalGuard: $guard,
                migrator: $migrator,
                hooks: $hooks,
                router: $router,
                views: $views,
                adminMenu: $adminMenu,
                capabilities: $capabilities,
                settings: $settings,
                db: $db,
                notices: $notices,
                logger: $logger,
                safeMode: $safeMode,
            );
        });

        $c->singleton(PluginInstaller::class, static function () use ($app, $c): PluginInstaller {
            $pluginsRelPath = (string) $app->config()->get('plugins.path', 'content/plugins');

            /** @var ManifestValidator $validator */
            $validator = $c->make(ManifestValidator::class);
            /** @var PluginRepository $repository */
            $repository = $c->make(PluginRepository::class);
            /** @var PluginManager $manager */
            $manager = $c->make(PluginManager::class);
            /** @var Hooks $hooks */
            $hooks = $c->make(Hooks::class);

            return new PluginInstaller(
                pluginsPath: $app->basePath($pluginsRelPath),
                tmpPath: $app->storagePath('tmp'),
                validator: $validator,
                repository: $repository,
                manager: $manager,
                hooks: $hooks,
                maxUploadMb: max(1, (int) $app->config()->get('plugins.max_upload_mb', 20)),
                maxZipFiles: max(1, (int) $app->config()->get('plugins.max_zip_files', 2000)),
                maxUncompressedBytes: max(1024 * 1024, (int) $app->config()->get('plugins.max_zip_uncompressed_bytes', 104857600)),
                maxCompressionRatio: max(10.0, (float) $app->config()->get('plugins.max_zip_compression_ratio', 100.0)),
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

        /** @var PluginManager $manager */
        $manager = $this->app->make(PluginManager::class);
        $manager->bootActivePlugins();
    }
}
