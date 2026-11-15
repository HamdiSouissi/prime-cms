<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Prime\Admin\Menu\AdminMenu;
use Prime\Admin\Menu\MenuItem;
use Prime\Hooks\Hooks;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Install\InstallCheckerInterface;
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

    public function testModulePlaceholdersAnnounceOfficialRoadmapPhases(): void
    {
        $app = $this->createApp(env: 'local', debug: true);
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);

        $expectedPhases = [
            '/admin/users' => 'Phase 4',
            '/admin/plugins' => 'Phase 5',
            '/admin/pages' => 'Phase 6',
            '/admin/articles' => 'Phase 6',
            '/admin/media' => 'Phase 7',
            '/admin/appearance' => 'Phase 8',
            '/admin/appearance/themes' => 'Phase 8',
            '/admin/appearance/menus' => 'Phase 8',
            '/admin/appearance/widgets' => 'Phase 8',
            '/admin/settings' => 'Phase 9',
            '/admin/settings/general' => 'Phase 9',
            '/admin/settings/permalinks' => 'Phase 9',
            '/admin/settings/security' => 'Phase 9',
            '/admin/settings/emails' => 'Phase 9',
        ];

        foreach ($expectedPhases as $path => $expectedPhase) {
            $response = $kernel->handle(Request::create('GET', $path));
            $this->assertSame(200, $response->status(), sprintf('Expected 200 for %s.', $path));
            $this->assertStringContainsString(
                $expectedPhase,
                $response->body(),
                sprintf('Expected %s to announce %s.', $path, $expectedPhase),
            );
        }

        // Styleguide row actions reflect Phase 6 editing and accurate demo duplicate text.
        $styleguideBody = $kernel->handle(Request::create('GET', '/admin/styleguide'))->body();
        $this->assertStringContainsString('(Phase 6).', $styleguideBody);
        $this->assertStringContainsString('Élément dupliqué (démo).', $styleguideBody);
        $this->assertStringNotContainsString('Lien copié.', $styleguideBody);
    }

    public function testCommandPalettePayloadHasKeywordsCleanSubtitlesAndSingleCloseControl(): void
    {
        $app = $this->createApp(env: 'local', debug: true);
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);

        $body = $kernel->handle(Request::create('GET', '/admin'))->body();

        $this->assertStringContainsString('type="text"', $body);
        $this->assertStringContainsString('role="combobox"', $body);
        $this->assertStringNotContainsString('type="search"', $body);

        $matched = preg_match(
            '/<script type="application\/json" id="prime-command-palette-data">(.*?)<\/script>/s',
            $body,
            $matches,
        );
        $this->assertSame(1, $matched, 'Command palette JSON script tag must exist.');

        $items = json_decode($matches[1], true);
        $this->assertIsArray($items);
        $this->assertNotEmpty($items);

        $byId = [];
        foreach ($items as $item) {
            $this->assertIsArray($item);
            $subtitle = (string) ($item['subtitle'] ?? '');
            $this->assertStringNotContainsString('/admin/', $subtitle, 'Subtitles must not display raw URLs.');
            $byId[(string) $item['id']] = $item;
        }

        $this->assertArrayHasKey('nav.settings', $byId);
        $this->assertContains('paramètres', $byId['nav.settings']['keywords']);
        $this->assertContains('configuration', $byId['nav.settings']['keywords']);

        $this->assertArrayHasKey('nav.media', $byId);
        $this->assertContains('médiathèque', $byId['nav.media']['keywords']);
    }

    public function testTopbarMobileSearchButtonAndNeutralSecondaryStyles(): void
    {
        $app = $this->createApp(env: 'local', debug: true);
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);

        $body = $kernel->handle(Request::create('GET', '/admin'))->body();

        // Mobile search icon button with accessible label and tooltip (C1)
        $this->assertStringContainsString('class="pc-topbar__search-mobile"', $body);
        $this->assertStringContainsString('aria-label="Rechercher"', $body);
        $this->assertStringContainsString('data-prime-tooltip="Rechercher"', $body);

        // Stat tile metadata uses muted link class instead of coral accent (C6)
        $this->assertStringContainsString('class="pc-stat__link"', $body);

        // Logout menu item is neutral, not danger-styled (C6)
        $this->assertStringNotContainsString('pc-dropdown__item--danger', $body);
    }

    public function testAllAdminPagesUsePrimePrefixedDataAttributesOnly(): void
    {
        $app = $this->createApp(env: 'local', debug: true);
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);

        $allowedStandardDataAttrs = ['data-theme'];
        $paths = ['/admin', '/admin/styleguide', '/admin/pages', '/admin/settings/security'];

        foreach ($paths as $path) {
            $body = $kernel->handle(Request::create('GET', $path))->body();
            // Strip JSON payload contents so we only inspect real HTML element attributes.
            $htmlOnly = preg_replace(
                '/<script type="application\/json"[^>]*>.*?<\/script>/s',
                '',
                $body,
            ) ?? $body;

            if (preg_match_all('/<[a-zA-Z][^>]*>/', $htmlOnly, $tagMatches) > 0) {
                foreach ($tagMatches[0] as $tag) {
                    if (preg_match_all('/\s(data-[a-z0-9-]+)\b/i', $tag, $attrMatches) > 0) {
                        foreach ($attrMatches[1] as $attr) {
                            $isPrimePrefixed = str_starts_with($attr, 'data-prime-');
                            $isWhitelisted = in_array($attr, $allowedStandardDataAttrs, true);
                            $this->assertTrue(
                                $isPrimePrefixed || $isWhitelisted,
                                sprintf('Unprefixed attribute [%s] found on %s.', $attr, $path),
                            );
                        }
                    }
                }
            }
        }
    }

    private function createApp(string $env, bool $debug): Application
    {
        $app = new Application(dirname(__DIR__, 2), loadEnv: false);
        $app->config()->set('app.env', $env);
        $app->config()->set('app.debug', $debug);
        $app->boot();

        $app->container()->instance(InstallCheckerInterface::class, new class () implements InstallCheckerInterface {
            public function isInstalled(): bool
            {
                return true;
            }
        });

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
