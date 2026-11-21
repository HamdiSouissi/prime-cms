<?php

declare(strict_types=1);

namespace PrimeStarter\Tests;

use PHPUnit\Framework\TestCase;
use Prime\Admin\SystemStatus;
use Prime\Content\ContentTypeRegistry;
use Prime\Editor\BlockRegistry;
use Prime\Editor\EditorRegistry;
use Prime\Hooks\Hooks;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Http\Session;
use Prime\Jobs\JobHandlerRegistry;
use Prime\Kernel\Application;
use Prime\Plugins\AssetPublisher;
use Prime\Plugins\PluginManager;
use Prime\Scheduler\Schedule;
use Prime\Security\Csrf;
use Prime\Security\HtmlSanitizer;
use Prime\Settings\Settings;
use Prime\Tests\Support\ApiTestFixture;
use PrimeStarter\Markdown;

require_once dirname(__DIR__) . '/src/Markdown.php';

/**
 * Prime Starter, activated for real: admin screens, public page, REST API, extension points,
 * and an uninstall that leaves nothing behind in the database.
 *
 * The plugin folder is the real one: uninstall runs with `deleteFiles: false`.
 */
final class PrimeStarterTest extends TestCase
{
    use ApiTestFixture;

    private string $assets = '';

    protected function tearDown(): void
    {
        if ($this->assets !== '') {
            AssetPublisher::deleteDirectory($this->assets);
        }
    }

    public function testMarkdownIsEscapedBeforeAnyTagIsAdded(): void
    {
        $html = Markdown::toHtml("# Titre\n\nUn **gras** et <script>alert(1)</script>\n\n- un\n- deux\n\n[ok](https://prime.test) [ko](javascript:alert(1))");

        $this->assertStringContainsString('<h2>Titre</h2>', $html);
        $this->assertStringContainsString('<strong>gras</strong>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('<ul><li>un</li><li>deux</li></ul>', $html);
        $this->assertStringContainsString('<a href="https://prime.test" rel="noopener noreferrer">ok</a>', $html);
        $this->assertStringNotContainsString('href="javascript', $html);
        $this->assertSame('Titre Un gras', Markdown::toText('# Titre Un **gras**'));
    }

    public function testNotesAreManagedFromTheAdmin(): void
    {
        $app = $this->activated('admin');

        $empty = $this->send($app, 'GET', '/admin/prime-starter');
        $this->assertSame(200, $empty->status());
        $this->assertStringContainsString('Aucune note pour l’instant', $empty->body());

        $invalid = $this->send($app, 'POST', '/admin/prime-starter/notes', ['title' => '', 'body' => 'x']);
        $this->assertSame(422, $invalid->status());
        $this->assertStringContainsString('Donnez un titre à la note.', $invalid->body());

        $created = $this->send($app, 'POST', '/admin/prime-starter/notes', ['title' => 'Réunion', 'body' => '**Ordre du jour**', 'color' => 'mint', 'public' => '1', 'pinned' => '0']);
        $this->assertSame(302, $created->status());
        $id = (int) $this->db($app)->table('prime_starter_notes')->value('id');
        $this->assertGreaterThan(0, $id);

        $list = $this->send($app, 'GET', '/admin/prime-starter')->body();
        $this->assertStringContainsString('Réunion', $list);
        $this->assertStringContainsString('data-prime-confirm-form="Supprimer la note « Réunion »', $list);

        $this->assertSame(200, $this->send($app, 'GET', '/admin/prime-starter/notes/' . $id . '/edit')->status());
        $this->send($app, 'POST', '/admin/prime-starter/notes/' . $id, ['title' => 'Réunion lundi', 'body' => '', 'color' => 'sky', 'public' => '0']);
        $this->assertSame('Réunion lundi', $this->db($app)->table('prime_starter_notes')->where('id', '=', $id)->value('title'));

        $this->send($app, 'POST', '/admin/prime-starter/notes/' . $id . '/delete');
        $this->assertSame(0, $this->db($app)->table('prime_starter_notes')->count());
    }

    public function testTheAdminIsReservedToTheCapability(): void
    {
        $app = $this->activated('contributor');

        $this->assertSame(403, $this->send($app, 'GET', '/admin/prime-starter')->status());
        $this->assertSame(403, $this->send($app, 'POST', '/admin/prime-starter/notes', ['title' => 'Non'])->status());
        $this->assertSame(0, $this->db($app)->table('prime_starter_notes')->count());
    }

    public function testThePublicPageShowsOnlyPublicNotesInsideTheTheme(): void
    {
        $app = $this->activated(null);
        $this->insertNote($app, 'Note publique', '*bonjour*', true);
        $this->insertNote($app, 'Note privée', 'secret', false);

        $page = $this->send($app, 'GET', '/notes', accept: 'text/html');
        $this->assertSame(200, $page->status(), substr($page->body(), 0, 300));
        $this->assertStringContainsString('<title>Carnet', $page->body());
        $this->assertStringContainsString('class="site-main"', $page->body(), 'la page doit être encadrée par le thème');
        $this->assertStringContainsString('<em>bonjour</em>', $page->body());
        $this->assertStringContainsString('/css/public.css', $page->body());
        $this->assertStringNotContainsString('Note privée', $page->body());

        $app->get(Settings::class)->set('plugin.prime-starter.public_enabled', false);
        $this->assertSame(404, $this->send($app, 'GET', '/notes', accept: 'text/html')->status());
    }

    public function testTheApiReadsPubliclyAndWritesWithTheCapability(): void
    {
        $app = $this->activated(null);
        $this->insertNote($app, 'Publique', 'a', true);
        $private = $this->insertNote($app, 'Privée', 'b', false);

        $this->assertSame(['Publique'], array_column($this->json($this->send($app, 'GET', '/api/prime-starter/v1/notes'))['data'] ?? [], 'title'));
        $this->assertSame(404, $this->send($app, 'GET', '/api/prime-starter/v1/notes/' . $private)->status());

        $admin = $this->tokens($app)->issue($this->user($app, 1), 'starter', ['*'])['token'];
        $contrib = $this->tokens($app)->issue($this->user($app, 2), 'starter', ['*'])['token'];
        $this->assertCount(2, $this->json($this->send($app, 'GET', '/api/prime-starter/v1/notes', token: $admin))['data'] ?? []);

        $this->assertSame(401, $this->send($app, 'POST', '/api/prime-starter/v1/notes', ['title' => 'x'])->status());
        $this->assertSame(403, $this->send($app, 'POST', '/api/prime-starter/v1/notes', ['title' => 'x'], token: $contrib)->status());
        $this->assertSame(422, $this->send($app, 'POST', '/api/prime-starter/v1/notes', ['title' => ''], token: $admin)->status());

        $created = $this->send($app, 'POST', '/api/prime-starter/v1/notes', ['title' => 'Par API', 'body' => '# Hop', 'public' => true], token: $admin);
        $this->assertSame(201, $created->status());
        $note = $this->json($created)['data'] ?? [];
        $this->assertSame('<h2>Hop</h2>', $note['html'] ?? null);

        $patched = $this->json($this->send($app, 'PATCH', '/api/prime-starter/v1/notes/' . $note['id'], ['pinned' => true], token: $admin))['data'] ?? [];
        $this->assertSame(['Par API', true, true], [$patched['title'] ?? null, $patched['pinned'] ?? null, $patched['public'] ?? null]);

        $this->assertSame(204, $this->send($app, 'DELETE', '/api/prime-starter/v1/notes/' . $note['id'], token: $admin)->status());
        $this->assertSame(404, $this->send($app, 'DELETE', '/api/prime-starter/v1/notes/' . $note['id'], token: $admin)->status());
    }

    public function testEveryExtensionPointIsWired(): void
    {
        $app = $this->activated('admin');
        $hooks = $app->get(Hooks::class);

        $schedule = $hooks->apply('scheduler.register', new Schedule());
        $this->assertInstanceOf(Schedule::class, $schedule);
        $this->assertTrue($schedule->has('prime-starter.weekly-digest'));
        $this->assertSame('prime-starter', $schedule->get('prime-starter.weekly-digest')->owner);

        $handlers = $hooks->apply('jobs.register_handlers', $app->get(JobHandlerRegistry::class));
        $this->assertInstanceOf(JobHandlerRegistry::class, $handlers);
        $this->assertTrue($handlers->has('prime-starter.digest'));

        $this->assertNotNull($app->get(EditorRegistry::class)->get('prime-starter-markdown'));
        $posts = $app->get(ContentTypeRegistry::class)->get('post');
        $this->assertSame('blocks', $hooks->apply('content.editor', 'blocks', $posts));
        $app->get(Settings::class)->set('plugin.prime-starter.markdown_posts', true);
        $this->assertSame('prime-starter-markdown', $hooks->apply('content.editor', 'blocks', $posts));

        $checks = array_column($app->get(SystemStatus::class)->checks(), null, 'id');
        $this->assertSame('ok', $checks['prime-starter.notes']['status'] ?? null);

        $block = $app->get(BlockRegistry::class)->get('prime-starter/markdown');
        $this->assertNotNull($block);
        $rendered = ($block->renderer)(['markdown' => "## Plan\n\n- **un**\n\n<img src=x onerror=alert(1)>"], $app->get(HtmlSanitizer::class));
        $this->assertStringContainsString('<h3>Plan</h3>', $rendered);
        $this->assertStringContainsString('<li><strong>un</strong></li>', $rendered);
        $this->assertStringNotContainsString('<img', $rendered);

        $dynamic = $hooks->apply('blocks.dynamic', []);
        $this->assertIsArray($dynamic);
        $this->assertArrayHasKey('prime_starter_notes', $dynamic);
    }

    public function testUninstallLeavesNoTraceInTheDatabase(): void
    {
        $app = $this->activated('admin');
        $this->insertNote($app, 'À effacer', '', true);
        $app->get(Settings::class)->set('plugin.prime-starter.public_title', 'Mon carnet');

        $manager = $app->get(PluginManager::class);
        $manager->deactivate('prime-starter');
        $manager->uninstall('prime-starter', deleteFiles: false);

        $tables = $this->db($app)->pdo()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'pc_prime_starter_notes'");
        $this->assertNotFalse($tables);
        $this->assertSame([], $tables->fetchAll());
        $this->assertSame(0, $this->db($app)->table('settings')->where('name', 'LIKE', 'plugin.prime-starter.%')->count());
        $this->assertDirectoryExists(dirname(__DIR__) . '/src');
    }

    private function activated(?string $as): Application
    {
        $app = $this->createApp($as);
        $pdo = $this->db($app)->pdo();
        $pdo->exec("CREATE TABLE pc_migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration VARCHAR(191) UNIQUE, source VARCHAR(64) DEFAULT 'core', batch INTEGER, ran_at DATETIME)");
        // The theme reads its menus and zones while it frames the public page.
        $pdo->exec('CREATE TABLE pc_menus (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(150) NOT NULL, slug VARCHAR(120) NOT NULL UNIQUE, location VARCHAR(80) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $pdo->exec("CREATE TABLE pc_menu_items (id INTEGER PRIMARY KEY AUTOINCREMENT, menu_id INTEGER NOT NULL, parent_id INTEGER NOT NULL DEFAULT 0, position INTEGER NOT NULL DEFAULT 0, type VARCHAR(20) NOT NULL DEFAULT 'url', target_id INTEGER NULL, url VARCHAR(500) NULL, label VARCHAR(255) NOT NULL DEFAULT '', target VARCHAR(16) NOT NULL DEFAULT '_self', css_class VARCHAR(255) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)");
        $pdo->exec('CREATE TABLE pc_zones (id INTEGER PRIMARY KEY AUTOINCREMENT, theme VARCHAR(80) NOT NULL, slug VARCHAR(80) NOT NULL, content LONGTEXT NOT NULL, content_html LONGTEXT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE(theme, slug))');
        $pdo->exec('CREATE TABLE pc_plugins (slug VARCHAR(64) PRIMARY KEY, version VARCHAR(64), status VARCHAR(20), manifest_hash VARCHAR(64), last_error TEXT, errored_at DATETIME, installed_at DATETIME, activated_at DATETIME, created_at DATETIME, updated_at DATETIME)');

        $this->assets = sys_get_temp_dir() . '/prime_starter_assets_' . bin2hex(random_bytes(4));
        mkdir($this->assets, 0755, true);
        $app->container()->instance(AssetPublisher::class, new AssetPublisher($this->assets, '/assets/plugins'));

        $app->get(PluginManager::class)->activate('prime-starter');

        return $app;
    }

    private function insertNote(Application $app, string $title, string $body, bool $public): int
    {
        return $this->db($app)->table('prime_starter_notes')->insertGetId([
            'title' => $title,
            'body' => $body,
            'color' => 'sand',
            'pinned' => 0,
            'public' => $public ? 1 : 0,
            'author_id' => null,
            'created_at' => '2026-11-20 10:00:00',
            'updated_at' => '2026-11-20 10:00:00',
        ]);
    }

    /**
     * Admin forms post with the session CSRF token; API calls send JSON with an optional token.
     *
     * @param array<string, mixed>|null $body
     */
    private function send(Application $app, string $method, string $uri, ?array $body = null, ?string $token = null, string $accept = 'application/json'): Response
    {
        $headers = ['Accept' => $accept];
        if (str_starts_with($uri, '/api/')) {
            $raw = $body !== null ? (string) json_encode($body) : '';
            if ($body !== null) {
                $headers['Content-Type'] = 'application/json';
            }
            if ($token !== null) {
                $headers['Authorization'] = 'Bearer ' . $token;
            }

            return $app->get(Kernel::class)->handle(Request::create($method, $uri, headers: $headers, rawBody: $raw));
        }

        if ($method === 'POST') {
            $body = ($body ?? []) + ['_csrf_token' => (new Csrf($app->get(Session::class)))->token()];
            $headers['Accept'] = 'text/html';
        }

        return $app->get(Kernel::class)->handle(Request::create($method, $uri, body: $body ?? [], headers: $headers));
    }
}
