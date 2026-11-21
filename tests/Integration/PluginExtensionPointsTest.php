<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use LogicException;
use PHPUnit\Framework\TestCase;
use Prime\Content\ContentType;
use Prime\Editor\EditorDefinition;
use Prime\Editor\EditorRegistry;
use Prime\Hooks\Hooks;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Http\Router;
use Prime\Kernel\Application;
use Prime\Mail\MailConfig;
use Prime\Mail\Mailer;
use Prime\Mail\NullMailer;
use Prime\Plugins\AssetPublisher;
use Prime\Plugins\PluginDataCleaner;
use Prime\Plugins\PluginEditors;
use Prime\Plugins\PluginRouter;
use Prime\Tests\Support\ApiTestFixture;
use Prime\View\View;
use RuntimeException;

/**
 * Phase 11 — the extension points a plugin relies on: alternative editors (and their fallback),
 * guarded public routes, namespaced mail templates and the data a plugin leaves behind.
 */
final class PluginExtensionPointsTest extends TestCase
{
    use ApiTestFixture;

    // --- Alternative editors ----------------------------------------------------------------

    public function testAContentTypeIsEditedWithThePluginEditorItChose(): void
    {
        $app = $this->createApp('admin');
        $this->registerEditor($app, 'demo-md', static fn (array $state): string => '<div data-demo-editor>' . htmlspecialchars((string) json_encode($state['document']['blocks'] ?? [])) . '</div>');
        $this->chooseEditor($app, 'demo-md');

        $body = $this->html($app, '/admin/content/post/1/edit');

        $this->assertStringContainsString('data-prime-custom-editor="demo-md"', $body);
        $this->assertStringContainsString('data-demo-editor', $body);
        $this->assertStringContainsString('/assets/demo/editor.js', $body);
    }

    public function testAMissingOrFailingEditorFallsBackToPrimeEditorWithANotice(): void
    {
        $app = $this->createApp('admin');
        $this->chooseEditor($app, 'ghost-editor');

        $missing = $this->html($app, '/admin/content/post/1/edit');
        $this->assertStringContainsString('L’éditeur « ghost-editor » n’est pas disponible', html_entity_decode($missing, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $this->assertStringNotContainsString('data-prime-custom-editor=', $missing);

        $app = $this->createApp('admin');
        $this->registerEditor($app, 'demo-broken', static function (): string {
            throw new RuntimeException('boom');
        });
        $this->chooseEditor($app, 'demo-broken');

        $broken = $this->html($app, '/admin/content/post/1/edit');
        $this->assertStringNotContainsString('data-prime-custom-editor=', $broken);
        $this->assertStringNotContainsString('boom', $broken);
    }

    public function testBlocksPrimeEditorDoesNotKnowAreKeptAsTheirHtml(): void
    {
        $app = $this->createApp('admin');
        $this->db($app)->table('entries')->where('id', '=', 1)->update([
            'content' => '{"version":1,"blocks":[{"id":"b1","type":"gone-plugin/thing","data":{"x":1}}]}',
            'content_html' => '<p>Rendu conservé</p>',
        ]);

        $body = html_entity_decode($this->html($app, '/admin/content/post/1/edit'), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $this->assertStringContainsString('<p>Rendu conservé</p>', $body);
        $this->assertStringContainsString('"type":"html"', $body);
    }

    public function testAPluginNamesItsEditorsAfterItsSlug(): void
    {
        $editors = new PluginEditors(new EditorRegistry(), 'demo', static fn (string $path): string => '/assets/demo/' . $path);
        $editors->register('demo-md', 'Markdown', static fn (): string => '');

        $this->expectException(LogicException::class);
        $editors->register('blocks', 'Blocs', static fn (): string => '');
    }

    public function testAnyLowercaseSlugIsAValidEditorForAContentType(): void
    {
        $type = new ContentType(name: 'doc', labels: ['singular' => 'Doc', 'plural' => 'Docs'], editor: 'demo-md');

        $this->assertSame('demo-md', $type->editor);
    }

    // --- Public routes ----------------------------------------------------------------------

    public function testAPluginCannotClaimACorePrefixOrAnExistingRoute(): void
    {
        $router = new Router();
        $router->get('/blog-feed', static fn (): Response => Response::html(''));
        $plugin = new PluginRouter($router, 'demo');

        $plugin->frontGroup(static function (Router $r): void {
            $r->get('/demo-page', static fn (): Response => Response::html(''));
        });
        $this->assertCount(2, $router->routes());

        foreach (['/admin/demo', '/api/demo', '/uploads/x', '/blog-feed'] as $path) {
            try {
                (new PluginRouter($router, 'demo'))->frontGroup(static function (Router $r) use ($path): void {
                    $r->get($path, static fn (): Response => Response::html(''));
                });
                $this->fail($path . ' aurait dû être refusée');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('demo', $exception->getMessage());
            }
        }
    }

    // --- Mail -------------------------------------------------------------------------------

    public function testAPluginMailTemplateIsRenderedFromItsOwnNamespace(): void
    {
        $dir = sys_get_temp_dir() . '/prime_mail_ns_' . bin2hex(random_bytes(4));
        mkdir($dir . '/mail', 0755, true);
        file_put_contents($dir . '/mail/digest.php', '<p>Bonjour <?= $this->e($who) ?> de <?= $this->e($site_name) ?></p>');

        try {
            $view = new View(dirname(__DIR__, 2) . '/resources/views');
            $view->addNamespace('demo', $dir);
            $mailer = new Mailer(new MailConfig(['from_name' => 'Atelier']), new NullMailer(), view: $view);

            $message = $mailer->render('demo::mail/digest', 'a@prime.test', 'Sujet', ['who' => 'Léa']);

            $this->assertStringContainsString('Bonjour Léa de Atelier', (string) $message->html);
        } finally {
            AssetPublisher::deleteDirectory($dir);
        }
    }

    // --- Uninstall --------------------------------------------------------------------------

    public function testTheDataCleanerRemovesWhatAPluginLeftInCoreTables(): void
    {
        $app = $this->createApp(null);
        $pdo = $this->db($app)->pdo();
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (2, 'demo_shop.manage'), (2, 'demo_shopping.view')");
        $pdo->exec("INSERT INTO pc_jobs (handler, payload, available_at, created_at) VALUES ('demo-shop.sync', '{}', '2026-11-21 10:00:00', '2026-11-21 10:00:00'), ('mail.send', '{\"template\":\"demo-shop::mail\\/order\"}', '2026-11-21 10:00:00', '2026-11-21 10:00:00'), ('mail.send', '{\"template\":\"user-invite\"}', '2026-11-21 10:00:00', '2026-11-21 10:00:00')");
        $token = $this->tokens($app)->issue($this->user($app, 1), 'mixte', ['content.view', 'demo_shop.manage']);

        $stamps = sys_get_temp_dir() . '/prime_stamps_' . bin2hex(random_bytes(4));
        mkdir($stamps, 0755, true);
        touch($stamps . '/demo-shop.sync.last');
        touch($stamps . '/demo-shopping.daily.last');

        try {
            $report = (new PluginDataCleaner($this->db($app), $stamps))->clean('demo-shop');

            $this->assertSame(['capabilities' => 1, 'tokens' => 1, 'jobs' => 2, 'stamps' => 1], $report);
            $left = array_column($this->db($app)->table('role_capabilities')->where('role_id', '=', 2)->get(), 'capability');
            sort($left);
            $this->assertSame(['content.create', 'content.edit_own', 'content.view', 'dashboard.view', 'demo_shopping.view'], $left);
            $this->assertSame(['user-invite'], array_map(static fn (array $row): string => (string) (json_decode((string) $row['payload'], true)['template'] ?? ''), $this->db($app)->table('jobs')->get()));
            $abilities = $this->db($app)->table('api_tokens')->where('id', '=', $token['id'])->value('abilities');
            $this->assertSame(['content.view'], json_decode((string) $abilities, true));
            $this->assertFileExists($stamps . '/demo-shopping.daily.last');
        } finally {
            AssetPublisher::deleteDirectory($stamps);
        }
    }

    private function registerEditor(Application $app, string $name, \Closure $render): void
    {
        $app->get(EditorRegistry::class)->register(new EditorDefinition(
            name: $name,
            label: 'Démo',
            render: $render,
            scripts: ['/assets/demo/editor.js'],
            owner: 'demo',
        ));
    }

    private function chooseEditor(Application $app, string $name): void
    {
        $app->get(Hooks::class)->filter('content.editor', static fn (mixed $editor, mixed $type = null): mixed => $type instanceof ContentType && $type->name === 'post' ? $name : $editor);
    }

    private function html(Application $app, string $uri): string
    {
        $response = $app->get(Kernel::class)->handle(Request::create('GET', $uri, headers: ['Accept' => 'text/html']));
        $this->assertSame(200, $response->status(), substr($response->body(), 0, 300));

        return $response->body();
    }
}
