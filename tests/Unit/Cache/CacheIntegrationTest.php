<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Prime\Cache\ArrayCache;
use Prime\Cache\CacheManager;
use Prime\Cache\CachePurgeSubscriber;
use Prime\Cache\CacheRuntimeConfig;
use Prime\Cache\FileCache;
use Prime\Cache\Http\PageCacheMiddleware;
use Prime\Cache\PageCache;
use Prime\Content\Entry;
use Prime\Hooks\Hooks;
use Prime\Http\Request;
use Prime\Http\Response;

final class CacheIntegrationTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/prime_cache_integration_' . uniqid('', true);
        mkdir($this->tempDir, 0750, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                if ($item->isDir()) {
                    @rmdir($item->getPathname());
                } else {
                    @unlink($item->getPathname());
                }
            }
            @rmdir($this->tempDir);
        }
    }

    public function testRuntimeConfigPersistsOnlyWhitelistedValuesAndSurvivesReRead(): void
    {
        $path = $this->tempDir . '/config.json';

        $this->assertSame([], CacheRuntimeConfig::read($path));

        $changes = CacheRuntimeConfig::applySetting($path, 'cache.page_ttl', '900');
        $this->assertSame(900, $changes['page_ttl']);

        CacheRuntimeConfig::applySetting($path, 'cache.page_enabled', false);
        CacheRuntimeConfig::applySetting($path, 'cache.driver', 'apcu');

        // Unknown setting names and invalid drivers are ignored
        CacheRuntimeConfig::applySetting($path, 'site.title', 'Ignoré');
        CacheRuntimeConfig::applySetting($path, 'cache.driver', 'mysql');

        $stored = CacheRuntimeConfig::read($path);
        $this->assertSame(900, $stored['page_ttl']);
        $this->assertFalse($stored['page_enabled']);
        $this->assertSame('apcu', $stored['driver']);
        $this->assertArrayNotHasKey('site.title', $stored);

        // TTL is clamped to the supported range
        CacheRuntimeConfig::applySetting($path, 'cache.page_ttl', 5);
        $this->assertSame(10, CacheRuntimeConfig::read($path)['page_ttl']);
    }

    public function testManagerCombinesStatsAndClearAllEmitsPurgeEvent(): void
    {
        $hooks = new Hooks();
        $data = new FileCache($this->tempDir . '/data');
        $pages = new PageCache($this->tempDir . '/pages', true, 300, $hooks);
        $manager = new CacheManager($data, $pages, 'file', $hooks);

        $data->set('menus:primary', ['items' => 3], 120);
        $pages->store(Request::create('GET', '/'), Response::html('<h1>Accueil</h1>'));

        $events = [];
        $hooks->on('cache.purged', static function (array $payload) use (&$events): void {
            $events[] = $payload;
        });

        $stats = $manager->stats();
        $this->assertSame('file', $stats['driver']);
        $this->assertFalse($stats['apcu_available']);
        $this->assertSame(1, $stats['data']['entries']);
        $this->assertSame(1, $stats['pages']['entries']);
        $this->assertGreaterThan(0, $stats['total_bytes']);

        $manager->clearAll();
        $this->assertNull($data->get('menus:primary'));
        $this->assertNull($pages->lookup(Request::create('GET', '/')));
        $this->assertCount(2, $events);
        $this->assertSame('all', $events[1]['scope']);
    }

    public function testMiddlewareServesCacheHitOnSecondAnonymousRequestAndIgnoresAuthRequests(): void
    {
        $pages = new PageCache($this->tempDir . '/mw', true, 300);
        $middleware = new PageCacheMiddleware($pages);

        $renders = 0;
        $next = static function () use (&$renders): Response {
            $renders++;

            return Response::html('<main>Site</main>');
        };

        $first = $middleware->process(Request::create('GET', '/about'), $next);
        $second = $middleware->process(Request::create('GET', '/about'), $next);

        $this->assertSame('MISS', $first->header('X-Prime-Cache'));
        $this->assertSame('HIT', $second->header('X-Prime-Cache'));
        $this->assertSame('<main>Site</main>', $second->body());
        $this->assertSame(1, $renders);

        // Authenticated requests always fall through to the application
        $auth = $middleware->process(
            Request::create('GET', '/about', cookies: ['prime_session' => 'abc']),
            static fn (): Response => Response::html('<main>Compte</main>'),
        );
        $this->assertNull($auth->header('X-Prime-Cache'));
        $this->assertSame('<main>Compte</main>', $auth->body());
    }

    public function testPurgeSubscriberInvalidatesEntryUrlHomeAndArchivesOnly(): void
    {
        $hooks = new Hooks();
        $data = new ArrayCache();
        $pages = new PageCache($this->tempDir . '/purge', true, 300, $hooks);
        $manager = new CacheManager($data, $pages, 'array', $hooks);

        (new CachePurgeSubscriber($pages, $manager))->register($hooks);

        $entry = $this->makeEntry('mon-article');
        $entryUrl = $entry->url();

        $pages->store(Request::create('GET', '/'), Response::html('Accueil'));
        $pages->store(Request::create('GET', $entryUrl), Response::html('Article'));
        $pages->store(Request::create('GET', '/contact'), Response::html('Contact'));
        $data->namespace('content')->set('count', 12);

        $hooks->emit('content.published', $entry, null);

        $this->assertNull($pages->lookup(Request::create('GET', '/')));
        $this->assertNull($pages->lookup(Request::create('GET', $entryUrl)));
        $this->assertNotNull($pages->lookup(Request::create('GET', '/contact')));
        $this->assertNull($data->namespace('content')->get('count'));
    }

    private function makeEntry(string $slug): Entry
    {
        return Entry::fromRow([
            'id' => 1,
            'type' => 'post',
            'status' => Entry::STATUS_PUBLISHED,
            'title' => 'Mon article',
            'slug' => $slug,
            'content' => '{"version":1,"blocks":[]}',
            'content_html' => '<p>Corps</p>',
            'content_text' => 'Corps',
            'author_id' => 1,
            'published_at' => '2026-01-01 10:00:00',
            'created_at' => '2026-01-01 10:00:00',
            'updated_at' => '2026-01-01 10:00:00',
        ]);
    }
}
