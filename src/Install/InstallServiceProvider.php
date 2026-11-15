<?php

declare(strict_types=1);

namespace Prime\Install;

use Prime\Hooks\Hooks;
use Prime\Http\Router;
use Prime\Install\Controller\InstallController;
use Prime\Kernel\Container;
use Prime\Kernel\ServiceProvider;
use Prime\Settings\Settings;
use Prime\View\View;

/**
 * Registers installation detection, services, views (`install::`), and wizard routes.
 */
final class InstallServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $container = $this->app->container();
        $app = $this->app;

        $container->singleton(EnvWriter::class, static fn (): EnvWriter => new EnvWriter());
        $container->singleton(InstallerValidator::class, static fn (): InstallerValidator => new InstallerValidator());
        $container->singleton(DatabaseTester::class, static fn (): DatabaseTester => new DatabaseTester());

        $container->singleton(RequirementsChecker::class, static function (Container $c) use ($app): RequirementsChecker {
            /** @var EnvWriter $envWriter */
            $envWriter = $c->get(EnvWriter::class);

            return new RequirementsChecker($app, $envWriter);
        });

        $container->singleton(InstallCheckerInterface::class, static function (Container $c) use ($app): InstallCheckerInterface {
            /** @var Settings $settings */
            $settings = $c->get(Settings::class);

            return new InstallChecker($app, $settings);
        });

        $container->singleton(Installer::class, static function (Container $c) use ($app): Installer {
            /** @var InstallerValidator $validator */
            $validator = $c->get(InstallerValidator::class);
            /** @var DatabaseTester $tester */
            $tester = $c->get(DatabaseTester::class);
            /** @var EnvWriter $envWriter */
            $envWriter = $c->get(EnvWriter::class);
            $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;

            return new Installer(
                app: $app,
                validator: $validator,
                databaseTester: $tester,
                envWriter: $envWriter,
                hooks: $hooks instanceof Hooks ? $hooks : null,
            );
        });
    }

    public function boot(): void
    {
        /** @var View $view */
        $view = $this->app->get(View::class);
        $view->addNamespace('install', $this->app->resourcesPath('views/install'));

        /** @var Router $router */
        $router = $this->app->get(Router::class);

        $router->get('/install', [InstallController::class, 'welcome'])->name('install.welcome');
        $router->post('/install', [InstallController::class, 'saveWelcome'])->name('install.welcome.save');
        $router->get('/install/requirements', [InstallController::class, 'requirements'])->name('install.requirements');
        $router->get('/install/database', [InstallController::class, 'database'])->name('install.database');
        $router->post('/install/database', [InstallController::class, 'saveDatabase'])->name('install.database.save');
        $router->get('/install/site', [InstallController::class, 'site'])->name('install.site');
        $router->post('/install/site', [InstallController::class, 'saveSite'])->name('install.site.save');
        $router->get('/install/admin', [InstallController::class, 'admin'])->name('install.admin');
        $router->post('/install/admin', [InstallController::class, 'saveAdmin'])->name('install.admin.save');
        $router->get('/install/run', [InstallController::class, 'summary'])->name('install.run');
        $router->post('/install/run', [InstallController::class, 'execute'])->name('install.run.execute');
        $router->get('/install/done', [InstallController::class, 'done'])->name('install.done');
    }
}
