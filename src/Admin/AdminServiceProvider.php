<?php

declare(strict_types=1);

namespace Prime\Admin;

use Prime\Admin\Controller\DashboardController;
use Prime\Admin\Demo\DemoDashboardData;
use Prime\Admin\Menu\AdminMenu;
use Prime\Admin\Menu\MenuItem;
use Prime\Admin\Middleware\AdminPreviewGate;
use Prime\Hooks\Hooks;
use Prime\Http\Router;
use Prime\Kernel\ServiceProvider;
use Prime\View\View;

/**
 * Registers the admin dashboard services, default navigation menu, view namespace,
 * and preview-gated `/admin` routes.
 */
final class AdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $container = $this->app->container();

        $container->singleton(AdminMenu::class, function (): AdminMenu {
            return $this->buildDefaultMenu();
        });

        $container->singleton(DemoDashboardData::class, static fn (): DemoDashboardData => new DemoDashboardData());

        $container->singleton(AdminPreviewGate::class, fn (): AdminPreviewGate => new AdminPreviewGate($this->app));

        $container->singleton(AdminContext::class, function (): AdminContext {
            /** @var AdminMenu $menu */
            $menu = $this->app->get(AdminMenu::class);
            /** @var Hooks $hooks */
            $hooks = $this->app->get(Hooks::class);

            return new AdminContext($this->app, $menu, $hooks);
        });

        $container->singleton(DashboardController::class, function (): DashboardController {
            /** @var View $view */
            $view = $this->app->get(View::class);
            /** @var AdminContext $context */
            $context = $this->app->get(AdminContext::class);
            /** @var DemoDashboardData $demo */
            $demo = $this->app->get(DemoDashboardData::class);

            return new DashboardController($this->app, $view, $context, $demo);
        });
    }

    public function boot(): void
    {
        /** @var View $view */
        $view = $this->app->get(View::class);
        $view->addNamespace('admin', $this->app->resourcesPath('views/admin'));

        /** @var Router $router */
        $router = $this->app->get(Router::class);
        $router->group(
            [
                'prefix' => '/admin',
                'middleware' => [AdminPreviewGate::class],
                'name' => 'admin.',
            ],
            static function (Router $r): void {
                $r->get('/', [DashboardController::class, 'index'])->name('dashboard');
                $r->get('/styleguide', [DashboardController::class, 'styleguide'])->name('styleguide');
                $r->get('/{module:[a-z0-9-]+}', [DashboardController::class, 'module'])->name('module');
                $r->get('/{module:[a-z0-9-]+}/{section:[a-z0-9-]+}', [DashboardController::class, 'module'])
                    ->name('module.section');
            },
        );
    }

    private function buildDefaultMenu(): AdminMenu
    {
        $menu = new AdminMenu();

        // Top-level overview
        $menu->add(new MenuItem(
            id: 'dashboard',
            label: 'Tableau de bord',
            icon: 'layout-dashboard',
            url: '/admin',
            position: 10,
            accent: 'brand',
            group: null,
        ));

        // Contenu group (teal)
        $menu->add(new MenuItem(
            id: 'pages',
            label: 'Pages',
            icon: 'file-text',
            url: '/admin/pages',
            position: 20,
            accent: 'teal',
            group: 'Contenu',
        ));

        $menu->add(new MenuItem(
            id: 'articles',
            label: 'Articles',
            icon: 'newspaper',
            url: '/admin/articles',
            position: 30,
            accent: 'teal',
            group: 'Contenu',
        ));

        $menu->add(new MenuItem(
            id: 'media',
            label: 'Médias',
            icon: 'image',
            url: '/admin/media',
            position: 40,
            accent: 'teal',
            group: 'Contenu',
        ));

        // Système group (violet, sky, neutral)
        $menu->add(new MenuItem(
            id: 'appearance',
            label: 'Apparence',
            icon: 'palette',
            url: '/admin/appearance',
            position: 50,
            accent: 'violet',
            group: 'Système',
        ));

        $menu->addChild('appearance', new MenuItem(
            id: 'appearance.themes',
            label: 'Thèmes',
            icon: 'palette',
            url: '/admin/appearance/themes',
            position: 10,
            accent: 'violet',
        ));

        $menu->addChild('appearance', new MenuItem(
            id: 'appearance.menus',
            label: 'Menus',
            icon: 'layers',
            url: '/admin/appearance/menus',
            position: 20,
            accent: 'violet',
        ));

        $menu->addChild('appearance', new MenuItem(
            id: 'appearance.widgets',
            label: 'Widgets',
            icon: 'layers',
            url: '/admin/appearance/widgets',
            position: 30,
            accent: 'violet',
        ));

        $menu->add(new MenuItem(
            id: 'plugins',
            label: 'Plugins',
            icon: 'plug',
            url: '/admin/plugins',
            position: 60,
            accent: 'violet',
            group: 'Système',
        ));

        $menu->add(new MenuItem(
            id: 'users',
            label: 'Utilisateurs',
            icon: 'users',
            url: '/admin/users',
            position: 70,
            accent: 'sky',
            group: 'Système',
        ));

        $menu->add(new MenuItem(
            id: 'settings',
            label: 'Réglages',
            icon: 'settings',
            url: '/admin/settings',
            position: 80,
            accent: 'neutral',
            group: 'Système',
        ));

        $menu->addChild('settings', new MenuItem(
            id: 'settings.general',
            label: 'Général',
            icon: 'settings',
            url: '/admin/settings/general',
            position: 10,
            accent: 'neutral',
        ));

        $menu->addChild('settings', new MenuItem(
            id: 'settings.permalinks',
            label: 'Permaliens',
            icon: 'settings',
            url: '/admin/settings/permalinks',
            position: 20,
            accent: 'neutral',
        ));

        $menu->addChild('settings', new MenuItem(
            id: 'settings.security',
            label: 'Sécurité',
            icon: 'shield',
            url: '/admin/settings/security',
            position: 30,
            accent: 'neutral',
        ));

        $menu->addChild('settings', new MenuItem(
            id: 'settings.emails',
            label: 'E-mails',
            icon: 'inbox',
            url: '/admin/settings/emails',
            position: 40,
            accent: 'neutral',
        ));

        if ($this->app->environment() === 'local') {
            $menu->add(new MenuItem(
                id: 'styleguide',
                label: 'Design System',
                icon: 'sparkles',
                url: '/admin/styleguide',
                position: 90,
                accent: 'amber',
                badge: 'Dev',
                group: 'Système',
            ));
        }

        return $menu;
    }
}
