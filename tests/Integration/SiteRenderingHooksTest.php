<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Prime\Database\Connection;
use Prime\Database\Migrations\Migrator;
use Prime\Database\Schema\Schema;
use Prime\Hooks\Hooks;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Install\InstallCheckerInterface;
use Prime\Kernel\Application;
use Prime\Site\PageContext;

/**
 * Prime CMS 1.0.1 — rendering hooks a page builder relies on:
 * `site.partial` (replace a theme partial such as the header) and the dynamic-block
 * detection of an entry body, decided on block types rather than on a substring of the JSON.
 */
#[Group('database')]
final class SiteRenderingHooksTest extends DatabaseTestCase
{
    public function testAPluginCanReplaceAThemePartial(): void
    {
        $app = $this->boot();
        $this->entry('{"version":1,"blocks":[]}', '<p>Corps statique</p>');
        $seen = [];
        $app->get(Hooks::class)->filter('site.partial', static function (mixed $html, mixed $name = null, mixed $context = null) use (&$seen): mixed {
            $seen[] = is_string($name) ? $name : '';
            if ($name === 'header' && $context instanceof PageContext) {
                return '<header data-builder-header>En-tête du constructeur</header>';
            }

            return $html;
        });

        $body = $this->html($app, '/page-rendu');

        $this->assertContains('header', $seen);
        $this->assertContains('footer', $seen);
        $this->assertStringContainsString('data-builder-header', $body);
        $this->assertStringContainsString('<p>Corps statique</p>', $body);
    }

    public function testTheThemePartialIsKeptWhenThePluginReturnsNoString(): void
    {
        $app = $this->boot();
        $this->entry('{"version":1,"blocks":[]}', '<p>Corps statique</p>');
        $app->get(Hooks::class)->filter('site.partial', static fn (mixed $html): mixed => 42);

        $body = $this->html($app, '/page-rendu');

        $this->assertStringNotContainsString('data-builder-header', $body);
        $this->assertMatchesRegularExpression('/<header\b/i', $body, 'Aurora garde son propre en-tête');
    }

    public function testTheWordDynamicInsideAStaticBlockKeepsTheStoredHtml(): void
    {
        $app = $this->boot();
        $this->entry(
            '{"version":1,"blocks":[{"id":"b1","type":"gone-plugin/layout","data":{"text":"dynamic"}}]}',
            '<h2>dynamic</h2>',
        );

        $body = $this->html($app, '/page-rendu');

        $this->assertStringContainsString('<h2>dynamic</h2>', $body);
    }

    private function boot(): Application
    {
        (new Migrator($this->connection, new Schema($this->connection), [dirname(__DIR__, 2) . '/database/migrations']))->run();

        $app = new Application(dirname(__DIR__, 2), loadEnv: false, registerErrorHandler: false);
        $app->config()->set('app.env', 'testing');
        $app->config()->set('app.debug', true);
        $app->config()->set('app.url', 'https://prime-hooks.test');
        $app->config()->set('app.key', 'base64:' . base64_encode(str_repeat('h', 32)));
        $app->config()->set('cache.page.enabled', false);
        $app->config()->set('cache.page.directory', sys_get_temp_dir() . '/prime_hooks_pages_' . uniqid('', true));
        $app->boot();
        $app->container()->instance(InstallCheckerInterface::class, new class () implements InstallCheckerInterface {
            public function isInstalled(): bool
            {
                return true;
            }
        });
        $app->container()->instance(Connection::class, $this->connection);

        return $app;
    }

    private function entry(string $content, string $html): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->table('entries')->insert([
            'type' => 'page',
            'status' => 'published',
            'title' => 'Page rendu',
            'slug' => 'page-rendu',
            'excerpt' => '',
            'content' => $content,
            'content_html' => $html,
            'content_text' => strip_tags($html),
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function html(Application $app, string $uri): string
    {
        $response = $app->get(Kernel::class)->handle(Request::create('GET', $uri, headers: ['Accept' => 'text/html']));
        $this->assertSame(200, $response->status(), substr(strip_tags($response->body()), 0, 600));

        return $response->body();
    }
}
