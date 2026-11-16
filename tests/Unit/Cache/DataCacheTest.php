<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Prime\Cache\ApcuCache;
use Prime\Cache\ArrayCache;
use Prime\Cache\FileCache;

final class DataCacheTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/prime_cache_unit_' . uniqid('', true);
        mkdir($this->tempDir, 0750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testArrayCacheSupportsBasicOperationsTtlAndIncrement(): void
    {
        $now = 1_700_000_000;
        $cache = new ArrayCache(static function () use (&$now): int {
            return $now;
        });

        $this->assertFalse($cache->has('missing'));
        $this->assertSame('fallback', $cache->get('missing', 'fallback'));

        $cache->set('greeting', 'Bonjour', 60);
        $this->assertTrue($cache->has('greeting'));
        $this->assertSame('Bonjour', $cache->get('greeting'));

        // Advance time past TTL
        $now += 61;
        $this->assertFalse($cache->has('greeting'));
        $this->assertNull($cache->get('greeting'));

        // Remember computes only once
        $calls = 0;
        $first = $cache->remember('computed', 120, static function () use (&$calls): array {
            $calls++;

            return ['ok' => true];
        });
        $second = $cache->remember('computed', 120, static function () use (&$calls): array {
            $calls++;

            return ['ok' => false];
        });
        $this->assertSame(['ok' => true], $first);
        $this->assertSame(['ok' => true], $second);
        $this->assertSame(1, $calls);

        // Increment
        $this->assertSame(1, $cache->increment('hits'));
        $this->assertSame(6, $cache->increment('hits', 5));

        $cache->delete('hits');
        $this->assertFalse($cache->has('hits'));

        $cache->flush();
        $this->assertFalse($cache->has('computed'));
    }

    public function testFileCacheWritesAtomicallyWithHashedNonExecutablePathsAndPrunesExpired(): void
    {
        $now = 1_700_000_000;
        $cache = new FileCache($this->tempDir . '/data', static function () use (&$now): int {
            return $now;
        });

        $cache->set('../../../etc/passwd', ['safe' => true], 30);
        $cache->set('permanent', 'value', null);

        $path = $cache->pathFor('../../../etc/passwd');
        $this->assertStringStartsWith($this->tempDir . '/data/', $path);
        $this->assertStringEndsWith('.cache', $path);
        $this->assertFileExists($path);
        $this->assertFileExists($this->tempDir . '/data/.htaccess');
        $this->assertFileExists($this->tempDir . '/data/index.html');
        $this->assertSame(['safe' => true], $cache->get('../../../etc/passwd'));

        // Verify no leftover temp files exist after atomic rename
        $this->assertSame([], glob($this->tempDir . '/data/tmp_*') ?: []);

        // Advance clock past TTL and prune
        $now += 35;
        $pruned = $cache->pruneExpired();
        $this->assertSame(1, $pruned);
        $this->assertFalse($cache->has('../../../etc/passwd'));
        $this->assertSame('value', $cache->get('permanent'));

        $stats = $cache->stats();
        $this->assertSame(1, $stats['entries']);
        $this->assertGreaterThan(0, $stats['bytes']);
    }

    public function testNamespacedCacheFlushesInConstantTimeWithoutAffectingOtherNamespaces(): void
    {
        $cache = new FileCache($this->tempDir . '/ns');
        $menus = $cache->namespace('menus');
        $zones = $cache->namespace('zones');

        $menus->set('primary', '<nav>Menu</nav>', 300);
        $zones->set('footer', '<section>Footer</section>', 300);

        $this->assertSame('<nav>Menu</nav>', $menus->get('primary'));
        $this->assertSame('<section>Footer</section>', $zones->get('footer'));
        $this->assertSame(1, $menus->version());

        // O(1) namespace flush bumps only the version counter of `menus`
        $menus->flush();
        $this->assertSame(2, $menus->version());
        $this->assertNull($menus->get('primary'));
        $this->assertSame('<section>Footer</section>', $zones->get('footer'));
    }

    public function testApcuAvailabilityCheckReturnsBoolean(): void
    {
        $available = ApcuCache::isAvailable();
        $this->assertIsBool($available);

        if ($available) {
            $apcu = new ApcuCache('prime_unit_test:');
            $apcu->set('k', 'v', 30);
            $this->assertSame('v', $apcu->get('k'));
            $apcu->flush();
            $this->assertNull($apcu->get('k'));
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $dir . '/' . $item;
            if (is_dir($full)) {
                $this->removeDirectory($full);
            } else {
                @unlink($full);
            }
        }

        @rmdir($dir);
    }
}
