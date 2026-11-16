<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Prime\Cache\PageCache;
use Prime\Hooks\Hooks;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Http\Session;

final class PageCacheTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/prime_page_cache_unit_' . uniqid('', true);
        mkdir($this->tempDir, 0750, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            @rmdir($this->tempDir);
        }
    }

    public function testCachesAnonymousGet200ServesHitAndHandlesEtag304(): void
    {
        $now = 1_700_000_000;
        $cache = new PageCache($this->tempDir, enabled: true, defaultTtl: 300, clock: static function () use (&$now): int {
            return $now;
        });

        $req = Request::create('GET', '/blog/article-test');
        $this->assertNull($cache->lookup($req));

        $stored = $cache->store($req, Response::html('<h1>Hello Prime</h1>', 200));
        $this->assertSame('MISS', $stored->header('X-Prime-Cache'));
        $etag = $stored->header('ETag');
        $this->assertNotNull($etag);

        // Second anonymous GET returns HIT
        $hit = $cache->lookup($req);
        $this->assertNotNull($hit);
        $this->assertSame(200, $hit->status());
        $this->assertSame('HIT', $hit->header('X-Prime-Cache'));
        $this->assertSame('<h1>Hello Prime</h1>', $hit->body());

        // Matching If-None-Match returns 304 Not Modified with empty body
        $condReq = Request::create('GET', '/blog/article-test', headers: ['If-None-Match' => $etag]);
        $notModified = $cache->lookup($condReq);
        $this->assertNotNull($notModified);
        $this->assertSame(304, $notModified->status());
        $this->assertSame('', $notModified->body());
        $this->assertSame('HIT', $notModified->header('X-Prime-Cache'));

        // HEAD returns empty body with HIT
        $headHit = $cache->lookup(Request::create('HEAD', '/blog/article-test'));
        $this->assertNotNull($headHit);
        $this->assertSame('', $headHit->body());
        $this->assertSame('HIT', $headHit->header('X-Prime-Cache'));
    }

    public function testBypassesAuthenticatedCookiesDisallowedQueriesSearchPreviewsNoindexAndPrivate(): void
    {
        $cache = new PageCache($this->tempDir, enabled: true, defaultTtl: 300);

        // Auth session cookie bypasses
        $authReq = Request::create('GET', '/', cookies: [Session::DEFAULT_NAME => 'abc123']);
        $this->assertFalse($cache->isCacheableRequest($authReq));

        // Remember cookie bypasses
        $remReq = Request::create('GET', '/', cookies: ['prime_remember' => 'sel:val']);
        $this->assertFalse($cache->isCacheableRequest($remReq));

        // Allowed query param `page` is cacheable; others bypass
        $this->assertTrue($cache->isCacheableRequest(Request::create('GET', '/blog', ['page' => '2'])));
        $this->assertFalse($cache->isCacheableRequest(Request::create('GET', '/blog', ['utm_source' => 'newsletter'])));
        $this->assertFalse($cache->isCacheableRequest(Request::create('GET', '/', ['preview' => '1'])));
        $this->assertFalse($cache->isCacheableRequest(Request::create('GET', '/recherche', ['q' => 'php'])));
        $this->assertFalse($cache->isCacheableRequest(Request::create('GET', '/admin')));
        $this->assertFalse($cache->isCacheableRequest(Request::create('POST', '/')));

        // Non-200, Cache-Control private, X-Robots-Tag noindex, and meta noindex all bypass
        $req = Request::create('GET', '/secret-page');
        $this->assertSame('BYPASS', $cache->store($req, Response::html('Not found', 404))->header('X-Prime-Cache'));
        $this->assertSame(
            'BYPASS',
            $cache->store($req, Response::html('Private', 200)->withHeader('Cache-Control', 'private, no-store'))->header('X-Prime-Cache'),
        );
        $this->assertSame(
            'BYPASS',
            $cache->store($req, Response::html('Noindex', 200)->withHeader('X-Robots-Tag', 'noindex, nofollow'))->header('X-Prime-Cache'),
        );
        $this->assertSame(
            'BYPASS',
            $cache->store($req, Response::html('<meta name="robots" content="noindex, follow"><p>Hi</p>', 200))->header('X-Prime-Cache'),
        );
    }

    public function testPluginFilterCanDisablePageCachingAndTargetedPurgeClearsOnlyMatchingPaths(): void
    {
        $hooks = new Hooks();
        $hooks->filter('cache.page.cacheable', static function (bool $cacheable, Request $request): bool {
            return $request->path() === '/no-cache-zone' ? false : $cacheable;
        });

        $cache = new PageCache($this->tempDir, enabled: true, defaultTtl: 300, hooks: $hooks);

        $this->assertFalse($cache->isCacheableRequest(Request::create('GET', '/no-cache-zone')));
        $this->assertTrue($cache->isCacheableRequest(Request::create('GET', '/about')));

        $cache->store(Request::create('GET', '/'), Response::html('Home'));
        $cache->store(Request::create('GET', '/blog/hello'), Response::html('Post'));
        $cache->store(Request::create('GET', '/blog/page/2'), Response::html('Blog P2'));
        $cache->store(Request::create('GET', '/about'), Response::html('About'));

        $purgedEvents = [];
        $hooks->on('cache.purged', static function (array $payload) use (&$purgedEvents): void {
            $purgedEvents[] = $payload;
        });

        $removed = $cache->purgePaths(['/', '/blog/hello'], ['/blog/page']);
        $this->assertSame(3, $removed);
        $this->assertNull($cache->lookup(Request::create('GET', '/')));
        $this->assertNull($cache->lookup(Request::create('GET', '/blog/hello')));
        $this->assertNull($cache->lookup(Request::create('GET', '/blog/page/2')));
        $this->assertNotNull($cache->lookup(Request::create('GET', '/about')));
        $this->assertCount(1, $purgedEvents);
    }
}
