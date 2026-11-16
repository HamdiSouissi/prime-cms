<?php

declare(strict_types=1);

namespace Prime\Admin;

use Prime\Admin\Controller\ContentController;
use Prime\Admin\Controller\DashboardController;
use Prime\Admin\Controller\MediaController;
use Prime\Admin\Controller\NoticeController;
use Prime\Admin\Controller\PluginController;
use Prime\Admin\Controller\ProfileController;
use Prime\Admin\Controller\RoleController;
use Prime\Admin\Controller\TaxonomyController;
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
use Prime\Content\ContentTypeRegistry;
use Prime\Content\EntryLockRepository;
use Prime\Content\EntryQuery;
use Prime\Content\EntryRepository;
use Prime\Content\EntryService;
use Prime\Content\Fields\FieldRegistry;
use Prime\Content\RevisionRepository;
use Prime\Content\TaxonomyRegistry;
use Prime\Content\TermRepository;
use Prime\Database\Connection;
use Prime\Editor\BlockRegistry;
use Prime\Editor\Renderer;
use Prime\Hooks\Hooks;
use Prime\Http\Response;
use Prime\Http\Router;
use Prime\Http\Session;
use Prime\Kernel\ServiceProvider;
use Prime\Log\Logger;
use Prime\Media\MediaConfig;
use Prime\Media\MediaFolderRepository;
use Prime\Media\MediaRepository;
use Prime\Media\MediaUsageRepository;
use Prime\Media\UploadService;
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
            $contentTypes = $this->app->container()->has(ContentTypeRegistry::class)
                ? $this->app->get(ContentTypeRegistry::class)
                : null;
            $taxonomies = $this->app->container()->has(TaxonomyRegistry::class)
                ? $this->app->get(TaxonomyRegistry::class)
                : null;
            $entries = $this->app->container()->has(EntryRepository::class)
                ? $this->app->get(EntryRepository::class)
                : null;

            return new AdminContext(
                $this->app,
                $menu,
                $hooks,
                $auth,
                $session,
                $notices instanceof Notices ? $notices : null,
                $logger instanceof Logger ? $logger : null,
                $contentTypes instanceof ContentTypeRegistry ? $contentTypes : null,
                $taxonomies instanceof TaxonomyRegistry ? $taxonomies : null,
                $entries instanceof EntryRepository ? $entries : null,
            );
        });

        $container->singleton(ContentController::class, function (): ContentController {
            return new ContentController(
                app: $this->app,
                view: $this->app->get(View::class),
                context: $this->app->get(AdminContext::class),
                auth: $this->app->get(Auth::class),
                types: $this->app->get(ContentTypeRegistry::class),
                taxonomies: $this->app->get(TaxonomyRegistry::class),
                fields: $this->app->get(FieldRegistry::class),
                blocks: $this->app->get(BlockRegistry::class),
                renderer: $this->app->get(Renderer::class),
                entries: $this->app->get(EntryRepository::class),
                query: $this->app->get(EntryQuery::class),
                service: $this->app->get(EntryService::class),
                terms: $this->app->get(TermRepository::class),
                revisions: $this->app->get(RevisionRepository::class),
                locks: $this->app->get(EntryLockRepository::class),
                users: $this->app->get(UserRepository::class),
            );
        });

        $container->singleton(TaxonomyController::class, function (): TaxonomyController {
            return new TaxonomyController(
                view: $this->app->get(View::class),
                context: $this->app->get(AdminContext::class),
                taxonomies: $this->app->get(TaxonomyRegistry::class),
                terms: $this->app->get(TermRepository::class),
            );
        });

        $container->singleton(MediaController::class, function (): MediaController {
            return new MediaController(
                view: $this->app->get(View::class),
                context: $this->app->get(AdminContext::class),
                auth: $this->app->get(Auth::class),
                config: $this->app->get(MediaConfig::class),
                media: $this->app->get(MediaRepository::class),
                folders: $this->app->get(MediaFolderRepository::class),
                usages: $this->app->get(MediaUsageRepository::class),
                uploader: $this->app->get(UploadService::class),
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
            $entries = $this->app->container()->has(EntryRepository::class) ? $this->app->get(EntryRepository::class) : null;
            $users = $this->app->container()->has(UserRepository::class) ? $this->app->get(UserRepository::class) : null;
            $types = $this->app->container()->has(ContentTypeRegistry::class) ? $this->app->get(ContentTypeRegistry::class) : null;

            return new DashboardController(
                app: $this->app,
                view: $this->app->get(View::class),
                context: $this->app->get(AdminContext::class),
                demo: $this->app->get(DemoDashboardData::class),
                auth: $this->app->get(Auth::class),
                hooks: $this->app->get(Hooks::class),
                logger: $logger instanceof Logger ? $logger : null,
                entries: $entries instanceof EntryRepository ? $entries : null,
                users: $users instanceof UserRepository ? $users : null,
                types: $types instanceof ContentTypeRegistry ? $types : null,
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

                // Content management (Phase 6 — dynamic for all registered content types)
                $r->get('/content/search-entries', [ContentController::class, 'searchEntries'])
                    ->middleware('can:content.view')
                    ->name('content.search');
                $r->get('/content/{type:[a-z0-9_-]+}', [ContentController::class, 'index'])
                    ->middleware('can:content.view')
                    ->name('content.index');
                $r->get('/content/{type:[a-z0-9_-]+}/create', [ContentController::class, 'create'])
                    ->middleware('can:content.create')
                    ->name('content.create');
                $r->post('/content/{type:[a-z0-9_-]+}', [ContentController::class, 'store'])
                    ->middleware('can:content.create')
                    ->name('content.store');
                $r->post('/content/{type:[a-z0-9_-]+}/bulk', [ContentController::class, 'bulk'])
                    ->middleware('can:content.view')
                    ->name('content.bulk');
                $r->post('/content/{type:[a-z0-9_-]+}/trash/empty', [ContentController::class, 'emptyTrash'])
                    ->middleware('can:content.delete')
                    ->name('content.trash.empty');
                $r->get('/content/{type:[a-z0-9_-]+}/{id:\d+}/edit', [ContentController::class, 'edit'])
                    ->middleware('can:content.view')
                    ->name('content.edit');
                $r->post('/content/{type:[a-z0-9_-]+}/{id:\d+}', [ContentController::class, 'update'])
                    ->middleware('can:content.view')
                    ->name('content.update');
                $r->post('/content/{type:[a-z0-9_-]+}/{id:\d+}/autosave', [ContentController::class, 'autosave'])
                    ->middleware('can:content.view')
                    ->name('content.autosave');
                $r->post('/content/{type:[a-z0-9_-]+}/{id:\d+}/lock/heartbeat', [ContentController::class, 'lockHeartbeat'])
                    ->middleware('can:content.view')
                    ->name('content.lock.heartbeat');
                $r->post('/content/{type:[a-z0-9_-]+}/{id:\d+}/lock/takeover', [ContentController::class, 'lockTakeover'])
                    ->middleware('can:content.view')
                    ->name('content.lock.takeover');
                $r->post('/content/{type:[a-z0-9_-]+}/{id:\d+}/lock/release', [ContentController::class, 'lockRelease'])
                    ->middleware('can:content.view')
                    ->name('content.lock.release');
                $r->post('/content/{type:[a-z0-9_-]+}/{id:\d+}/trash', [ContentController::class, 'trash'])
                    ->middleware('can:content.view')
                    ->name('content.trash');
                $r->post('/content/{type:[a-z0-9_-]+}/{id:\d+}/restore', [ContentController::class, 'restore'])
                    ->middleware('can:content.view')
                    ->name('content.restore');
                $r->post('/content/{type:[a-z0-9_-]+}/{id:\d+}/delete', [ContentController::class, 'forceDelete'])
                    ->middleware('can:content.view')
                    ->name('content.delete');
                $r->post('/content/{type:[a-z0-9_-]+}/{id:\d+}/duplicate', [ContentController::class, 'duplicate'])
                    ->middleware('can:content.create')
                    ->name('content.duplicate');
                $r->get('/content/{type:[a-z0-9_-]+}/{id:\d+}/preview', [ContentController::class, 'preview'])
                    ->middleware('can:content.view')
                    ->name('content.preview');
                $r->get('/content/{type:[a-z0-9_-]+}/{id:\d+}/revisions', [ContentController::class, 'revisions'])
                    ->middleware('can:content.view')
                    ->name('content.revisions');
                $r->post('/content/{type:[a-z0-9_-]+}/{id:\d+}/revisions/{revId:\d+}/restore', [ContentController::class, 'restoreRevision'])
                    ->middleware('can:content.view')
                    ->name('content.revisions.restore');

                // Taxonomies management (Phase 6 — dynamic for all registered taxonomies)
                $r->get('/taxonomies/{taxonomy:[a-z0-9_-]+}', [TaxonomyController::class, 'index'])
                    ->middleware('can:content.view')
                    ->name('taxonomies.index');
                $r->post('/taxonomies/{taxonomy:[a-z0-9_-]+}', [TaxonomyController::class, 'store'])
                    ->middleware('can:content.edit')
                    ->name('taxonomies.store');
                $r->get('/taxonomies/{taxonomy:[a-z0-9_-]+}/{id:\d+}/edit', [TaxonomyController::class, 'edit'])
                    ->middleware('can:content.edit')
                    ->name('taxonomies.edit');
                $r->post('/taxonomies/{taxonomy:[a-z0-9_-]+}/{id:\d+}', [TaxonomyController::class, 'update'])
                    ->middleware('can:content.edit')
                    ->name('taxonomies.update');
                $r->post('/taxonomies/{taxonomy:[a-z0-9_-]+}/{id:\d+}/delete', [TaxonomyController::class, 'delete'])
                    ->middleware('can:content.delete')
                    ->name('taxonomies.delete');

                // Media Library & JSON API (Phase 7)
                $r->get('/media', [MediaController::class, 'index'])
                    ->middleware('can:media.view')
                    ->name('media.index');
                $r->get('/media/api/items', [MediaController::class, 'apiList'])
                    ->middleware('can:media.view')
                    ->name('media.api.items');
                $r->get('/media/api/items/{id:\d+}', [MediaController::class, 'apiShow'])
                    ->middleware('can:media.view')
                    ->name('media.api.show');
                $r->post('/media/api/upload', [MediaController::class, 'apiUpload'])
                    ->middleware('can:media.upload')
                    ->name('media.api.upload');
                $r->post('/media/api/items/{id:\d+}', [MediaController::class, 'apiUpdate'])
                    ->middleware('can:media.view')
                    ->name('media.api.update');
                $r->post('/media/api/items/{id:\d+}/regenerate', [MediaController::class, 'apiRegenerate'])
                    ->middleware('can:media.view')
                    ->name('media.api.regenerate');
                $r->post('/media/api/items/{id:\d+}/replace', [MediaController::class, 'apiReplace'])
                    ->middleware('can:media.view')
                    ->name('media.api.replace');
                $r->post('/media/api/items/{id:\d+}/delete', [MediaController::class, 'apiDelete'])
                    ->middleware('can:media.view')
                    ->name('media.api.delete');
                $r->post('/media/api/bulk', [MediaController::class, 'apiBulk'])
                    ->middleware('can:media.view')
                    ->name('media.api.bulk');
                $r->get('/media/api/folders', [MediaController::class, 'apiFolders'])
                    ->middleware('can:media.view')
                    ->name('media.api.folders');
                $r->post('/media/api/folders', [MediaController::class, 'apiCreateFolder'])
                    ->middleware('can:media.upload')
                    ->name('media.api.folders.create');
                $r->post('/media/api/folders/{id:\d+}', [MediaController::class, 'apiUpdateFolder'])
                    ->middleware('can:media.upload')
                    ->name('media.api.folders.update');
                $r->post('/media/api/folders/{id:\d+}/delete', [MediaController::class, 'apiDeleteFolder'])
                    ->middleware('can:media.upload')
                    ->name('media.api.folders.delete');

                // Backwards-compatible redirects from Phase 2 placeholder content routes
                $r->get('/pages', static fn (): Response => Response::redirect('/admin/content/page'))
                    ->middleware('can:content.view')
                    ->name('legacy.pages');
                $r->get('/articles', static fn (): Response => Response::redirect('/admin/content/post'))
                    ->middleware('can:content.view')
                    ->name('legacy.articles');

                // Upcoming CMS module placeholders (capability verified in DashboardController::module)
                $r->get('/{module:appearance|settings}', [DashboardController::class, 'module'])
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

        // Contenu group (teal) — dynamic ContentType items are injected in AdminContext::build()
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
