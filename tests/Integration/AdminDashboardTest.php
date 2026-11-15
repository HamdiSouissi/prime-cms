<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Prime\Admin\Menu\AdminMenu;
use Prime\Admin\Menu\MenuItem;
use Prime\Auth\Auth;
use Prime\Auth\UserRepository;
use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Install\InstallCheckerInterface;
use Prime\Kernel\Application;

final class AdminDashboardTest extends TestCase
{
    public function testGuestsAreRedirectedToLoginWithNoStoreHeaders(): void
    {
        $app = $this->createApp(env: 'local', debug: true, authenticateAs: null);
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);

        foreach (['/admin', '/admin/users', '/admin/roles', '/admin/profile', '/admin/styleguide'] as $path) {
            $response = $kernel->handle(Request::create('GET', $path));
            $this->assertSame(302, $response->status(), sprintf('Guest should be redirected on %s.', $path));
            $this->assertSame('/admin/login', $response->header('Location'));
            $this->assertStringContainsString('no-store', (string) $response->header('Cache-Control'));
            $this->assertSame('no-cache', $response->header('Pragma'));
        }

        // JSON clients get 401 JSON instead of redirect
        $jsonResponse = $kernel->handle(Request::create('GET', '/admin/users', headers: ['Accept' => 'application/json']));
        $this->assertSame(401, $jsonResponse->status());
    }

    public function testAdminRoutesReturn200ForAuthenticatedAdministrator(): void
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
            '/admin/users/create',
            '/admin/roles',
            '/admin/roles/create',
            '/admin/profile',
            '/admin/settings',
            '/admin/settings/general',
            '/admin/settings/permalinks',
            '/admin/settings/security',
            '/admin/settings/emails',
        ];

        foreach ($paths as $path) {
            $response = $kernel->handle(Request::create('GET', $path));
            $this->assertSame(200, $response->status(), sprintf('Expected 200 for %s.', $path));
            $this->assertSame('nosniff', $response->header('X-Content-Type-Options'));
            $this->assertSame('SAMEORIGIN', $response->header('X-Frame-Options'));
            $this->assertStringContainsString("script-src 'self'", (string) $response->header('Content-Security-Policy'));
            $this->assertStringContainsString('no-store', (string) $response->header('Cache-Control'));
            $this->assertSame('no-cache', $response->header('Pragma'));
            $this->assertNoExecutableInlineScripts($response->body());
        }
    }

    public function testStyleguideRequiresBothLocalEnvAndAdministratorRole(): void
    {
        // Outside local environment -> 404 even for administrator
        $prodApp = $this->createApp(env: 'production', debug: false, authenticateAs: 'admin');
        /** @var Kernel $prodKernel */
        $prodKernel = $prodApp->get(Kernel::class);
        $this->assertSame(200, $prodKernel->handle(Request::create('GET', '/admin'))->status());
        $this->assertSame(404, $prodKernel->handle(Request::create('GET', '/admin/styleguide'))->status());

        // Non-admin in local environment -> 403
        $contribApp = $this->createApp(env: 'local', debug: true, authenticateAs: 'contributor');
        /** @var Kernel $contribKernel */
        $contribKernel = $contribApp->get(Kernel::class);
        $this->assertSame(403, $contribKernel->handle(Request::create('GET', '/admin/styleguide'))->status());
    }

    public function testMenuCreateDropdownAndCommandPaletteAreFilteredByCapabilities(): void
    {
        $app = $this->createApp(env: 'local', debug: true, authenticateAs: 'contributor');
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);

        $response = $kernel->handle(Request::create('GET', '/admin'));
        $body = $response->body();

        $this->assertSame(200, $response->status());
        // Contributor can access dashboard, pages, media, profile
        $this->assertStringContainsString('Bonjour, Lucas Contributeur', $body);
        $this->assertStringContainsString('href="/admin/pages"', $body);
        $this->assertStringContainsString('href="/admin/media"', $body);
        $this->assertStringContainsString('href="/admin/profile"', $body);

        // Contributor must NOT see users, roles, plugins, settings, styleguide in sidebar or palette
        $this->assertStringNotContainsString('href="/admin/users"', $body);
        $this->assertStringNotContainsString('href="/admin/roles"', $body);
        $this->assertStringNotContainsString('href="/admin/plugins"', $body);
        $this->assertStringNotContainsString('href="/admin/settings"', $body);
        $this->assertStringNotContainsString('href="/admin/styleguide"', $body);

        // Direct access to unauthorized routes returns 403 HTML and 403 JSON
        $this->assertSame(403, $kernel->handle(Request::create('GET', '/admin/users'))->status());
        $this->assertSame(403, $kernel->handle(Request::create('GET', '/admin/roles'))->status());
        $this->assertSame(403, $kernel->handle(Request::create('GET', '/admin/settings'))->status());

        $jsonForbidden = $kernel->handle(Request::create('GET', '/admin/users', headers: ['Accept' => 'application/json']));
        $this->assertSame(403, $jsonForbidden->status());
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
        $this->assertStringContainsString('Bonjour, Camille Admin', $body);
        $this->assertStringContainsString('action="/admin/logout"', $body);

        // Zero external HTTP(S) asset requests (no CDN fonts/scripts/stylesheets)
        $this->assertSame(
            0,
            preg_match('/<(?:script|link|img)[^>]*(?:src|href)=["\']https?:\/\//i', $body),
            'Admin HTML must never load external CDN assets.',
        );

        // Strict CSP: zero inline executable <script> tags
        $this->assertNoExecutableInlineScripts($body);
    }

    public function testAuthPagesHaveNoInlineScriptsAndNoStoreHeaders(): void
    {
        $app = $this->createApp(env: 'local', debug: true, authenticateAs: null);
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);

        foreach (['/admin/login', '/admin/forgot-password'] as $path) {
            $res = $kernel->handle(Request::create('GET', $path));
            $this->assertSame(200, $res->status());
            $this->assertStringContainsString('no-store', (string) $res->header('Cache-Control'));
            $this->assertSame('no-cache', $res->header('Pragma'));
            $this->assertNoExecutableInlineScripts($res->body());
        }
    }

    public function testStyleguideRendersAllDesignSystemComponentsWithStrictCsp(): void
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

    public function testActiveSubmenuAutomaticallyExpandsParentGroup(): void
    {
        $app = $this->createApp(env: 'local', debug: true);
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);

        $response = $kernel->handle(Request::create('GET', '/admin/settings/security'));
        $body = $response->body();

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('id="pc-submenu-settings"', $body);
        $this->assertStringNotContainsString('id="pc-submenu-settings" hidden', $body);
        $this->assertStringContainsString('href="/admin/settings/security"', $body);
        $this->assertStringContainsString('pc-sidebar__sublink--active', $body);
    }

    public function testAdminHooksAllowExtendingMenuPaletteAssetsAndInjectionPoints(): void
    {
        $app = $this->createApp(env: 'local', debug: true);
        /** @var Hooks $hooks */
        $hooks = $app->get(Hooks::class);

        $hooks->filter('admin.menu', static function (AdminMenu $menu): AdminMenu {
            $menu->add(new MenuItem(
                id: 'commerce',
                label: 'Boutique',
                icon: 'sparkles',
                url: '/admin/commerce',
                position: 45,
                accent: 'amber',
                badge: 'New',
                group: 'Contenu',
            ));

            return $menu;
        });

        $hooks->filter('admin.command_palette', static function (array $items): array {
            $items[] = [
                'id' => 'plugin.sync',
                'label' => 'Synchroniser le catalogue',
                'subtitle' => 'Plugin Boutique',
                'group' => 'Extensions',
                'section' => 'Extensions',
                'icon' => 'sparkles',
                'accent' => 'amber',
                'url' => '/admin/commerce',
                'keywords' => ['catalogue', 'sync'],
            ];

            return $items;
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
        $paths = ['/admin', '/admin/styleguide', '/admin/plugins', '/admin/users', '/admin/roles', '/admin/profile', '/admin/pages', '/admin/settings/security'];

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

    private function createApp(string $env, bool $debug, ?string $authenticateAs = 'admin'): Application
    {
        $app = new Application(dirname(__DIR__, 2), loadEnv: false);
        $app->config()->set('app.env', $env);
        $app->config()->set('app.debug', $debug);
        $app->config()->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app->boot();

        $app->container()->instance(InstallCheckerInterface::class, new class () implements InstallCheckerInterface {
            public function isInstalled(): bool
            {
                return true;
            }
        });

        // In-memory SQLite fixture implementing the auth/roles schema for fast unit/shell HTTP tests.
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE pc_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(191) UNIQUE, password_hash VARCHAR(255), display_name VARCHAR(191), status VARCHAR(20), locale VARCHAR(10), last_login_at DATETIME NULL, session_version INTEGER DEFAULT 1, two_factor_secret TEXT NULL, two_factor_recovery_codes TEXT NULL, two_factor_confirmed_at DATETIME NULL, two_factor_last_step INTEGER NULL, password_changed_at DATETIME NULL, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, slug VARCHAR(191) UNIQUE, name VARCHAR(191), description TEXT NULL, is_system INTEGER DEFAULT 0, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_role_capabilities (role_id INTEGER, capability VARCHAR(100), PRIMARY KEY (role_id, capability))');
        $pdo->exec('CREATE TABLE pc_user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id))');
        $pdo->exec('CREATE TABLE pc_remember_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, selector CHAR(24) UNIQUE, validator_hash CHAR(64), expires_at DATETIME, last_used_at DATETIME NULL, user_agent VARCHAR(255) NULL, created_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_password_resets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash CHAR(64) UNIQUE, expires_at DATETIME, used_at DATETIME NULL, created_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_rate_limits (key CHAR(64) PRIMARY KEY, hits INTEGER DEFAULT 0, reset_at DATETIME)');

        $now = '2026-11-15 12:00:00';
        $pdo->exec("INSERT INTO pc_roles (id, slug, name, description, is_system, created_at, updated_at) VALUES (1, 'administrator', 'Administrateur', 'Accès complet', 1, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO pc_roles (id, slug, name, description, is_system, created_at, updated_at) VALUES (2, 'contributor', 'Contributeur', 'Rédaction brouillons', 1, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (1, '*')");
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (2, 'dashboard.view'), (2, 'content.view'), (2, 'content.create'), (2, 'content.edit_own'), (2, 'media.view')");

        $hash = password_hash('Saffron!Horizon#8429', PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('INSERT INTO pc_users (id, email, password_hash, display_name, status, locale, session_version, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)');
        $stmt->execute([1, 'camille@prime-cms.local', $hash, 'Camille Admin', 'active', 'fr', $now, $now]);
        $stmt->execute([2, 'lucas@prime-cms.local', $hash, 'Lucas Contributeur', 'active', 'fr', $now, $now]);
        $pdo->exec('INSERT INTO pc_user_roles (user_id, role_id) VALUES (1, 1), (2, 2)');

        $connection = new Connection(['prefix' => 'pc_'], pdo: $pdo);
        $app->container()->instance(Connection::class, $connection);

        if ($authenticateAs !== null) {
            /** @var UserRepository $userRepo */
            $userRepo = $app->get(UserRepository::class);
            /** @var Auth $auth */
            $auth = $app->get(Auth::class);
            $user = $userRepo->find($authenticateAs === 'admin' ? 1 : 2);
            if ($user !== null) {
                $auth->login($user);
            }
        }

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
