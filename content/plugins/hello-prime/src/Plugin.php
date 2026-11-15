<?php

declare(strict_types=1);

namespace HelloPrime;

use HelloPrime\Controller\HelloPrimeController;
use Prime\Admin\AdminContext;
use Prime\Admin\Menu\AdminMenu;
use Prime\Admin\Menu\MenuItem;
use Prime\Admin\Notice;
use Prime\Auth\Auth;
use Prime\Auth\User;
use Prime\Http\Router;
use Prime\Plugins\AbstractPlugin;
use Prime\Plugins\PluginContext;

/**
 * Reference plugin exercising the entire official Prime CMS PluginContext API
 * without touching core internals.
 */
final class Plugin extends AbstractPlugin
{
    public const CAPABILITY_MANAGE = 'hello_prime.manage';
    public const DEFAULT_GREETING = 'Bonjour depuis Hello Prime !';

    public function register(PluginContext $context): void
    {
        // 1. Register custom capability grouped under "Hello Prime"
        $context->capabilities()->register(
            self::CAPABILITY_MANAGE,
            'Gérer les réglages et messages Hello Prime',
        );

        // 2. Register controller in container using PluginContext dependencies
        $context->app()->container()->singleton(
            HelloPrimeController::class,
            static function () use ($context): HelloPrimeController {
                /** @var AdminContext $adminContext */
                $adminContext = $context->app()->make(AdminContext::class);
                /** @var Auth $auth */
                $auth = $context->app()->make(Auth::class);

                return new HelloPrimeController($context, $adminContext, $auth);
            },
        );

        // 3. Add sidebar menu item (violet accent) via `admin.menu` filter
        $context->hooks()->filter('admin.menu', static function (mixed $menu): mixed {
            if ($menu instanceof AdminMenu) {
                $menu->add(new MenuItem(
                    id: 'plugin.hello-prime',
                    label: 'Hello Prime',
                    icon: 'sparkles',
                    url: '/admin/hello-prime',
                    position: 62,
                    accent: 'violet',
                    capability: self::CAPABILITY_MANAGE,
                    group: 'Système',
                ));
            }

            return $menu;
        });

        // 4. Register plugin stylesheet via `admin.assets` filter
        $context->hooks()->filter('admin.assets', static function (mixed $assets) use ($context): mixed {
            if (is_array($assets) && isset($assets['css']) && is_array($assets['css'])) {
                $assets['css'][] = $context->url('css/hello-prime.css');
            }

            return $assets;
        });

        // 5. Add a dashboard shortcut tile via `admin.dashboard.shortcuts` filter
        $context->hooks()->filter('admin.dashboard.shortcuts', static function (mixed $shortcuts, mixed $user): mixed {
            if (!is_array($shortcuts)) {
                return $shortcuts;
            }
            if ($user instanceof User && !$user->can(self::CAPABILITY_MANAGE)) {
                return $shortcuts;
            }

            $shortcuts[] = [
                'title' => 'Hello Prime',
                'description' => 'Configurer le message d’accueil et le livre d’or.',
                'icon' => 'sparkles',
                'accent' => 'violet',
                'url' => '/admin/hello-prime',
            ];

            return $shortcuts;
        });

        // 6. Declare settings shortcut URL for `/admin/plugins`
        $context->setSettingsUrl('/admin/hello-prime');

        // 7. Register protected admin routes (`/admin/hello-prime/*`)
        $context->router()->adminGroup(static function (Router $r): void {
            $r->get('/', [HelloPrimeController::class, 'adminIndex'])->name('index');
            $r->post('/settings', [HelloPrimeController::class, 'saveSettings'])->name('settings');
            $r->post('/greetings', [HelloPrimeController::class, 'addGreeting'])->name('greetings.store');
            $r->post('/greetings/{id:\d+}/delete', [HelloPrimeController::class, 'deleteGreeting'])->name('greetings.delete');
        }, capability: self::CAPABILITY_MANAGE);

        // 8. Register public front-office route (`GET /hello-prime`)
        $context->router()->frontGroup(static function (Router $r): void {
            $r->get('/hello-prime', [HelloPrimeController::class, 'publicPage'])->name('public');
        });
    }

    public function activate(PluginContext $context): void
    {
        if (!$context->settings()->has('greeting')) {
            $context->settings()->set('greeting', self::DEFAULT_GREETING);
        }

        $context->notices()->add(
            id: 'welcome',
            type: Notice::TYPE_SUCCESS,
            title: 'Hello Prime est activé !',
            message: 'Personnalisez votre message d’accueil et testez la page publique /hello-prime.',
            actionLabel: 'Ouvrir Hello Prime',
            actionUrl: '/admin/hello-prime',
            capability: self::CAPABILITY_MANAGE,
            dismissible: true,
        );

        $context->logger()->info('Plugin Hello Prime activé.');
    }

    public function deactivate(PluginContext $context): void
    {
        $context->notices()->remove('welcome');
        $context->logger()->info('Plugin Hello Prime désactivé.');
    }

    public function uninstall(PluginContext $context): void
    {
        $context->notices()->clearAll();
        $context->logger()->info('Plugin Hello Prime désinstallé : nettoyage terminé.');
    }
}
