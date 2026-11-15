<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Prime\Admin\Menu\AdminMenu;
use Prime\Admin\Menu\MenuItem;
use Prime\Hooks\Hooks;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Kernel\Application;

final class AdminDashboardTest extends TestCase
{
    public function testAdminRoutesReturn200InLocalEnvironment(): void
    {
        $app = $this->createApp(env: 'local', debug: true);
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);

        $paths = [
            '/admin',
            '/admin/styleguide',
            '/admin/pages',
            '/admin/articles',
            '/admin/media',
            '/admin/appearance',
            '/admin/appearance/themes',
            '/admin/appearance/menus',
            '/admin/appearance/widgets',
            '/admin/plugins',
            '/admin/users',
            '/admin/settings',
            '/admin/settings/general',
            '/admin/settings/permalinks',
            '/admin/settings/security',
            '/admin/settings/emails',
        ];

        foreach ($paths as $path) {
            $response = $kernel->handle(Request::create('GET', $path));
            $this->assertSame(200, $response->status(), sprintf('Expected 200 for %s in local env.', $path));
            $this->assertSame('nosniff', $response->header('X-Content-Type-Options'));
            $this->assertSame('SAMEORIGIN', $response->header('X-Frame-Options'));
            $this->assertStringContainsString("script-src 'self'", (string) $response->header('Content-Security-Policy'));
        }
    }

    public function testAdminRoutesReturn404OutsideLocalEnvironment(): void
    {
        foreach (['production', 'staging'] as $env) {
            $app = $this->createApp(env: $env, debug: false);
            /** @var Kernel $kernel */
            $kernel = $app->get(Kernel::class);

            foreach (['/admin', '/admin/styleguide', '/admin/pages', '/admin/settings/general'] as $path) {
                $response = $kernel->handle(Request::create('GET', $path));
                $this->assertSame(404, $response->status(), sprintf('Expected 404 for %s in %s env.', $path, $env));
            }
        }
    }

    public function testUnknownAdminModuleReturns404EvenInLocalEnvironment(): void
    {
        $app = $this->createApp(env: 'local', debug: true);
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);

        $response = $kernel->handle(Request::create('GET', '/admin/unknown-module'));
        $this->assertSame(404, $response->status());
    }

    public function testDashboardHasAccessibleLandmarksNoInlineScriptsAndNoExternalRequests(): void
    {
        $app = $this->createApp(env: 'local', debug: true);
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);

        $response = $kernel->handle(Request::create('GET', '/admin'));
        $body = $response->body();

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('<html lang="fr">', $body);
        $this->assertStringContainsString('href="#content">Aller au contenu</a>', $body);
        $this->assertStringContainsString('aria-label="Navigation principale"', $body);
        $this->assertStringContainsString('<header class="pc-topbar">', $body);
        $this->assertStringContainsString('<main id="content"', $body);
        $this->assertStringContainsString('aria-current="page"', $body);
        $this->assertStringContainsString('logo-64.webp', $body);
        $this->assertStringContainsString('InterVariable.woff2', $body);
        $this->assertStringContainsString('id="prime-command-palette"', $body);

        // Zero external font/CDN URLs
        $this->assertStringNotContainsString('fonts.googleapis.com', $body);
        $this->assertStringNotContainsString('fonts.gstatic.com', $body);
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $body);
        $this->assertStringNotContainsString('unpkg.com', $body);

        // Zero executable inline <script> blocks (every <script> must have src= or type="application/json")
        $this->assertNoExecutableInlineScripts($body);
    }

    public function testStyleguideRendersAllComponentsWithZeroInlineExecutableScripts(): void
    {
        $app = $this->createApp(env: 'local', debug: true);
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);

        $response = $kernel->handle(Request::create('GET', '/admin/styleguide'));
        $body = $response->body();

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('Design System Prime CMS', $body);
        $this->assertStringContainsString('id="sg-tokens"', $body);
        $this->assertStringContainsString('id="sg-buttons"', $body);
        $this->assertStringContainsString('id="sg-forms"', $body);
        $this->assertStringContainsString('id="sg-badges"', $body);
        $this->assertStringContainsString('id="sg-table"', $body);
        $this->assertStringContainsString('id="sg-demo-modal"', $body);
        $this->assertStringContainsString('id="sg-demo-drawer"', $body);
        $this->assertStringContainsString('id="sg-icons"', $body);
        $this->assertNoExecutableInlineScripts($body);
    }

    public function testSubmenuRouteMarksChildActiveAndExpandsParent(): void
    {
        $app = $this->createApp(env: 'local', debug: true);
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);

        $response = $kernel->handle(Request::create('GET', '/admin/settings/security'));
        $body = $response->body();

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('Bientôt disponible', $body);
        $this->assertStringContainsString('Phase 9', $body);
        $this->assertStringContainsString('href="/admin/settings/security"', $body);
        $this->assertStringContainsString('pc-sidebar__sublink--active', $body);
        $this->assertStringContainsString('aria-current="page"', $body);
    }

    public function testAdminHooksAllowPluginsToExtendMenuCreatePaletteAndAssets(): void
    {
        $app = $this->createApp(env: 'local', debug: true);

        /** @var Hooks $hooks */
        $hooks = $app->get(Hooks::class);

        $hooks->filter('admin.menu', static function (AdminMenu $menu): AdminMenu {
            $menu->add(new MenuItem(
                id: 'commerce',
                label: 'Boutique',
                icon: 'shopping-bag',
                url: '/admin/commerce',
                position: 45,
                accent: 'amber',
                group: 'Contenu',
            ));

            return $menu;
        });

        $hooks->filter('admin.create.items', static function (array $items): array {
            $items[] = [
                'id' => 'create.product',
                'label' => 'Nouveau produit',
                'description' => 'Catalogue boutique',
                'icon' => 'shopping-bag',
                'accent' => 'amber',
                'url' => '/admin/commerce',
            ];

            return $items;
        });

        $hooks->filter('admin.command_palette', static function (array $entries): array {
            $entries[] = [
                'id' => 'plugin.sync',
                'label' => 'Synchroniser le catalogue',
                'subtitle' => 'Action plugin',
                'section' => 'Plugins',
                'icon' => 'sparkles',
                'accent' => 'violet',
                'action' => 'toast',
            ];

            return $entries;
        });

        $hooks->filter('admin.assets', static function (array $assets): array {
            $assets['css'][] = '/assets/plugins/shop.css';

            return $assets;
        });

        $hooks->filter('admin.head', static fn (string $html): string => $html . '<meta name="x-prime-plugin" content="shop">');
        $hooks->filter('admin.footer', static fn (string $html): string => $html . '<!-- prime-plugin-footer -->');

        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        $response = $kernel->handle(Request::create('GET', '/admin'));
        $body = $response->body();

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('Boutique', $body);
        $this->assertStringContainsString('Nouveau produit', $body);
        $this->assertStringContainsString('Synchroniser le catalogue', $body);
        $this->assertStringContainsString('/assets/plugins/shop.css', $body);
        $this->assertStringContainsString('<meta name="x-prime-plugin" content="shop">', $body);
        $this->assertStringContainsString('<!-- prime-plugin-footer -->', $body);
    }

    private function createApp(string $env, bool $debug): Application
    {
        $app = new Application(dirname(__DIR__, 2), loadEnv: false);
        $app->config()->set('app.env', $env);
        $app->config()->set('app.debug', $debug);
        $app->boot();

        return $app;
    }

    private function assertNoExecutableInlineScripts(string $html): void
    {
        if (preg_match_all('/<script\b([^>]*)>/i', $html, $matches) > 0) {
            foreach ($matches[1] as $attrs) {
                $hasSrc = preg_match('/\bsrc=/i', $attrs) === 1;
                $isJsonData = preg_match('/\btype=["\']application\/json["\']/i', $attrs) === 1;
                $this->assertTrue(
                    $hasSrc || $isJsonData,
                    sprintf('Executable inline <script%s> violates strict CSP.', $attrs),
                );
            }
        }

        // Also ensure zero inline DOM event handler attributes (`onclick="..."`, etc.)
        $this->assertSame(
            0,
            preg_match('/\son[a-z]+\s*=/i', $html),
            'Inline event handler attributes violate strict CSP.',
        );
    }
}
