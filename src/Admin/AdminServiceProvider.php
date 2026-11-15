<?php

declare(strict_types=1);

namespace Prime\Admin;

use Prime\Admin\Controller\DashboardController;
use Prime\Admin\Controller\NoticeController;
use Prime\Admin\Controller\PluginController;
use Prime\Admin\Controller\ProfileController;
use Prime\Admin\Controller\RoleController;
use Prime\Admin\Controller\UserController;
use Prime\Admin\Demo\DemoDashboardData;
use Prime\Admin\Menu\AdminMenu;
use Prime\Admin\Menu\MenuItem;
use Prime\Auth\Auth;
use Prime\Auth\Capabilities;
use Prime\Auth\Controller\AuthController;
use Prime\Auth\PasswordResetService;
use Prime\Auth\RoleRepository;
use Prime\Auth\TwoFactorService;
use Prime\Auth\UserGuard;
use Prime\Auth\UserRepository;
use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\Http\Router;
use Prime\Http\Session;
use Prime\Kernel\ServiceProvider;
use Prime\Log\Logger;
use Prime\Plugins\PluginInstaller;
use Prime\Plugins\PluginManager;
use Prime\Security\Hasher;
use Prime\Security\PasswordPolicy;
use Prime\View\View;

/**
 * Registers the admin dashboard services, capability-annotated navigation menu,
 * authentication routes, and RBAC-protected `/admin` routes.
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

        $container->singleton(AdminContext::class, function (): AdminContext {
            /** @var AdminMenu $menu */
            $menu = $this->app->get(AdminMenu::class);
            /** @var Hooks $hooks */
            $hooks = $this->app->get(Hooks::class);
            /** @var Auth $auth */
            $auth = $this->app->get(Auth::class);
            /** @var Session $session */
            $session = $this->app->get(Session::class);
            $notices = $this->app->container()->has(Notices::class) ? $this->app->get(Notices::class) : null;
            $logger = $this->app->container()->has(Logger::class) ? $this->app->get(Logger::class) : null;

            return new AdminContext(
                $this->app,
                $menu,
                $hooks,
                $auth,
                $session,
                $notices instanceof Notices ? $notices : null,
                $logger instanceof Logger ? $logger : null,
            );
        });

        $container->singleton(NoticeController::class, function (): NoticeController {
            return new NoticeController(
                notices: $this->app->get(Notices::class),
                auth: $this->app->get(Auth::class),
            );
        });

        $container->singleton(PluginController::class, function (): PluginController {
            return new PluginController(
                app: $this->app,
                view: $this->app->get(View::class),
                context: $this->app->get(AdminContext::class),
                manager: $this->app->get(PluginManager::class),
                installer: $this->app->get(PluginInstaller::class),
            );
        });

        $container->singleton(AuthController::class, function (): AuthController {
            return new AuthController(
                app: $this->app,
                view: $this->app->get(View::class),
                auth: $this->app->get(Auth::class),
                passwordResets: $this->app->get(PasswordResetService::class),
                session: $this->app->get(Session::class),
            );
        });

        $container->singleton(DashboardController::class, function (): DashboardController {
            $logger = $this->app->container()->has(Logger::class) ? $this->app->get(Logger::class) : null;

            return new DashboardController(
                app: $this->app,
                view: $this->app->get(View::class),
                context: $this->app->get(AdminContext::class),
                demo: $this->app->get(DemoDashboardData::class),
                auth: $this->app->get(Auth::class),
                hooks: $this->app->get(Hooks::class),
                logger: $logger instanceof Logger ? $logger : null,
            );
        });

        $container->singleton(UserController::class, function (): UserController {
            return new UserController(
                app: $this->app,
                view: $this->app->get(View::class),
                context: $this->app->get(AdminContext::class),
                auth: $this->app->get(Auth::class),
                users: $this->app->get(UserRepository::class),
                roles: $this->app->get(RoleRepository::class),
                guard: $this->app->get(UserGuard::class),
                passwordResets: $this->app->get(PasswordResetService::class),
                twoFactor: $this->app->get(TwoFactorService::class),
                connection: $this->app->get(Connection::class),
                passwordPolicy: $this->app->get(PasswordPolicy::class),
            );
        });

        $container->singleton(RoleController::class, function (): RoleController {
            return new RoleController(
                view: $this->app->get(View::class),
                context: $this->app->get(AdminContext::class),
                roles: $this->app->get(RoleRepository::class),
                capabilities: $this->app->get(Capabilities::class),
                connection: $this->app->get(Connection::class),
            );
        });

        $container->singleton(ProfileController::class, function (): ProfileController {
            return new ProfileController(
                app: $this->app,
                view: $this->app->get(View::class),
                context: $this->app->get(AdminContext::class),
                auth: $this->app->get(Auth::class),
                users: $this->app->get(UserRepository::class),
                twoFactor: $this->app->get(TwoFactorService::class),
                passwordResets: $this->app->get(PasswordResetService::class),
                session: $this->app->get(Session::class),
                connection: $this->app->get(Connection::class),
                passwordPolicy: $this->app->get(PasswordPolicy::class),
                hasher: $this->app->get(Hasher::class),
            );
        });
    }

    public function boot(): void
    {
        /** @var View $view */
        $view = $this->app->get(View::class);
        $view->addNamespace('admin', $this->app->resourcesPath('views/admin'));

        /** @var Router $router */
        $router = $this->app->get(Router::class);

        // Public authentication routes (no-store headers, CSRF verified globally on POST).
        $router->group(
            [
                'prefix' => '/admin',
                'middleware' => ['no-store'],
                'name' => 'auth.',
            ],
            static function (Router $r): void {
                $r->get('/login', [AuthController::class, 'showLogin'])->name('login');
                $r->post('/login', [AuthController::class, 'login'])->name('login.submit');
                $r->get('/login/two-factor', [AuthController::class, 'showTwoFactor'])->name('two_factor');
                $r->post('/login/two-factor', [AuthController::class, 'verifyTwoFactor'])->name('two_factor.verify');
                $r->post('/logout', [AuthController::class, 'logout'])->name('logout');
                $r->get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('forgot');
                $r->post('/forgot-password', [AuthController::class, 'sendResetLink'])->name('forgot.submit');
                $r->get('/reset-password/{token:[a-f0-9]+}', [AuthController::class, 'showResetPassword'])->name('reset');
                $r->post('/reset-password/{token:[a-f0-9]+}', [AuthController::class, 'resetPassword'])->name('reset.submit');
            },
        );

        // Authenticated administration routes.
        $router->group(
            [
                'prefix' => '/admin',
                'middleware' => ['auth'],
                'name' => 'admin.',
            ],
            static function (Router $r): void {
                $r->get('/', [DashboardController::class, 'index'])
                    ->middleware('can:dashboard.view')
                    ->name('dashboard');

                $r->get('/styleguide', [DashboardController::class, 'styleguide'])
                    ->middleware('can:*')
                    ->name('styleguide');

                // Self-service profile & security (any authenticated user)
                $r->get('/profile', [ProfileController::class, 'index'])->name('profile');
                $r->post('/profile/info', [ProfileController::class, 'updateInfo'])->name('profile.info');
                $r->post('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
                $r->post('/profile/2fa/start', [ProfileController::class, 'startTwoFactor'])->name('profile.2fa.start');
                $r->post('/profile/2fa/cancel', [ProfileController::class, 'cancelTwoFactorSetup'])->name('profile.2fa.cancel');
                $r->post('/profile/2fa/confirm', [ProfileController::class, 'confirmTwoFactor'])->name('profile.2fa.confirm');
                $r->post('/profile/2fa/recovery', [ProfileController::class, 'regenerateRecoveryCodes'])->name('profile.2fa.recovery');
                $r->post('/profile/2fa/disable', [ProfileController::class, 'disableTwoFactor'])->name('profile.2fa.disable');
                $r->post('/profile/logout-devices', [ProfileController::class, 'logoutOtherDevices'])->name('profile.logout_devices');

                // Users CRUD
                $r->get('/users', [UserController::class, 'index'])
                    ->middleware('can:users.view')
                    ->name('users.index');
                $r->get('/users/create', [UserController::class, 'create'])
                    ->middleware('can:users.create')
                    ->name('users.create');
                $r->post('/users', [UserController::class, 'store'])
                    ->middleware('can:users.create')
                    ->name('users.store');
                $r->post('/users/bulk', [UserController::class, 'bulk'])
                    ->middleware('can:users.edit')
                    ->name('users.bulk');
                $r->get('/users/{id:\d+}/edit', [UserController::class, 'edit'])
                    ->middleware('can:users.edit')
                    ->name('users.edit');
                $r->post('/users/{id:\d+}', [UserController::class, 'update'])
                    ->middleware('can:users.edit')
                    ->name('users.update');
                $r->post('/users/{id:\d+}/toggle-status', [UserController::class, 'toggleStatus'])
                    ->middleware('can:users.edit')
                    ->name('users.toggle_status');
                $r->post('/users/{id:\d+}/delete', [UserController::class, 'delete'])
                    ->middleware('can:users.delete')
                    ->name('users.delete');
                $r->post('/users/{id:\d+}/reset-password', [UserController::class, 'sendResetLink'])
                    ->middleware('can:users.edit')
                    ->name('users.reset_password');
                $r->post('/users/{id:\d+}/logout-devices', [UserController::class, 'logoutDevices'])
                    ->middleware('can:users.edit')
                    ->name('users.logout_devices');
                $r->post('/users/{id:\d+}/reset-2fa', [UserController::class, 'resetTwoFactor'])
                    ->middleware('can:users.edit')
                    ->name('users.reset_2fa');

                // Roles CRUD
                $r->get('/roles', [RoleController::class, 'index'])
                    ->middleware('can:roles.manage')
                    ->name('roles.index');
                $r->get('/roles/create', [RoleController::class, 'create'])
                    ->middleware('can:roles.manage')
                    ->name('roles.create');
                $r->post('/roles', [RoleController::class, 'store'])
                    ->middleware('can:roles.manage')
                    ->name('roles.store');
                $r->get('/roles/{id:\d+}/edit', [RoleController::class, 'edit'])
                    ->middleware('can:roles.manage')
                    ->name('roles.edit');
                $r->post('/roles/{id:\d+}', [RoleController::class, 'update'])
                    ->middleware('can:roles.manage')
                    ->name('roles.update');
                $r->post('/roles/{id:\d+}/delete', [RoleController::class, 'delete'])
                    ->middleware('can:roles.manage')
                    ->name('roles.delete');

                // Persistent dashboard notices dismissal
                $r->post('/notices/{id:[a-zA-Z0-9._-]+}/dismiss', [NoticeController::class, 'dismiss'])
                    ->name('notices.dismiss');

                // Plugins management (L9)
                $r->get('/plugins', [PluginController::class, 'index'])
                    ->middleware('can:plugins.manage')
                    ->name('plugins.index');
                $r->post('/plugins/upload', [PluginController::class, 'upload'])
                    ->middleware('can:plugins.manage')
                    ->name('plugins.upload');
                $r->post('/plugins/{slug:[a-z0-9-]+}/activate', [PluginController::class, 'activate'])
                    ->middleware('can:plugins.manage')
                    ->name('plugins.activate');
                $r->post('/plugins/{slug:[a-z0-9-]+}/deactivate', [PluginController::class, 'deactivate'])
                    ->middleware('can:plugins.manage')
                    ->name('plugins.deactivate');
                $r->post('/plugins/{slug:[a-z0-9-]+}/uninstall', [PluginController::class, 'uninstall'])
                    ->middleware('can:plugins.manage')
                    ->name('plugins.uninstall');

                // Upcoming CMS module placeholders (capability verified in DashboardController::module)
                $r->get('/{module:pages|articles|media|appearance|settings}', [DashboardController::class, 'module'])
                    ->name('module');
                $r->get('/{module:appearance|settings}/{section:[a-z0-9-]+}', [DashboardController::class, 'module'])
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
            capability: 'dashboard.view',
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
            capability: 'content.view',
            group: 'Contenu',
        ));

        $menu->add(new MenuItem(
            id: 'articles',
            label: 'Articles',
            icon: 'newspaper',
            url: '/admin/articles',
            position: 30,
            accent: 'teal',
            capability: 'content.view',
            group: 'Contenu',
        ));

        $menu->add(new MenuItem(
            id: 'media',
            label: 'Médias',
            icon: 'image',
            url: '/admin/media',
            position: 40,
            accent: 'teal',
            capability: 'media.view',
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
            capability: 'appearance.manage',
            group: 'Système',
        ));

        $menu->addChild('appearance', new MenuItem(
            id: 'appearance.themes',
            label: 'Thèmes',
            icon: 'palette',
            url: '/admin/appearance/themes',
            position: 10,
            accent: 'violet',
            capability: 'appearance.manage',
        ));

        $menu->addChild('appearance', new MenuItem(
            id: 'appearance.menus',
            label: 'Menus',
            icon: 'layers',
            url: '/admin/appearance/menus',
            position: 20,
            accent: 'violet',
            capability: 'appearance.manage',
        ));

        $menu->addChild('appearance', new MenuItem(
            id: 'appearance.widgets',
            label: 'Widgets',
            icon: 'layers',
            url: '/admin/appearance/widgets',
            position: 30,
            accent: 'violet',
            capability: 'appearance.manage',
        ));

        $menu->add(new MenuItem(
            id: 'plugins',
            label: 'Plugins',
            icon: 'plug',
            url: '/admin/plugins',
            position: 60,
            accent: 'violet',
            capability: 'plugins.manage',
            group: 'Système',
        ));

        $menu->add(new MenuItem(
            id: 'users',
            label: 'Utilisateurs',
            icon: 'users',
            url: '/admin/users',
            position: 70,
            accent: 'sky',
            capability: 'users.view',
            group: 'Système',
        ));

        $menu->addChild('users', new MenuItem(
            id: 'users.list',
            label: 'Tous les utilisateurs',
            icon: 'users',
            url: '/admin/users',
            position: 10,
            accent: 'sky',
            capability: 'users.view',
        ));

        $menu->addChild('users', new MenuItem(
            id: 'users.create',
            label: 'Ajouter',
            icon: 'plus',
            url: '/admin/users/create',
            position: 20,
            accent: 'sky',
            capability: 'users.create',
        ));

        $menu->addChild('users', new MenuItem(
            id: 'users.roles',
            label: 'Rôles',
            icon: 'shield',
            url: '/admin/roles',
            position: 30,
            accent: 'sky',
            capability: 'roles.manage',
        ));

        $menu->add(new MenuItem(
            id: 'settings',
            label: 'Réglages',
            icon: 'settings',
            url: '/admin/settings',
            position: 80,
            accent: 'neutral',
            capability: 'settings.manage',
            group: 'Système',
        ));

        $menu->addChild('settings', new MenuItem(
            id: 'settings.general',
            label: 'Général',
            icon: 'settings',
            url: '/admin/settings/general',
            position: 10,
            accent: 'neutral',
            capability: 'settings.manage',
        ));

        $menu->addChild('settings', new MenuItem(
            id: 'settings.permalinks',
            label: 'Permaliens',
            icon: 'settings',
            url: '/admin/settings/permalinks',
            position: 20,
            accent: 'neutral',
            capability: 'settings.manage',
        ));

        $menu->addChild('settings', new MenuItem(
            id: 'settings.security',
            label: 'Sécurité',
            icon: 'shield',
            url: '/admin/settings/security',
            position: 30,
            accent: 'neutral',
            capability: 'settings.manage',
        ));

        $menu->addChild('settings', new MenuItem(
            id: 'settings.emails',
            label: 'E-mails',
            icon: 'inbox',
            url: '/admin/settings/emails',
            position: 40,
            accent: 'neutral',
            capability: 'settings.manage',
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
                capability: '*',
                group: 'Système',
            ));
        }

        return $menu;
    }
}
