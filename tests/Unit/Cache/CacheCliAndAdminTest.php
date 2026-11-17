<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Prime\Cache\ArrayCache;
use Prime\Cache\CacheManager;
use Prime\Cache\PageCache;
use Prime\Console\CommandInterface;
use Prime\Console\Commands\CacheClearCommand;
use Prime\Console\ConsoleOutput;
use Prime\Http\Request;
use Prime\Http\Response;

/**
 * Behaviour of `bin/prime cache:clear` and of the page-cache purge marker shown on the
 * « Performance » screen.
 */
final class CacheCliAndAdminTest extends TestCase
{
    private string $tempDir;

    private PageCache $pages;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/prime_cache_cli_' . uniqid('', true);
        mkdir($this->tempDir, 0750, true);
        $this->pages = new PageCache($this->tempDir, true, 300);
    }

    protected function tearDown(): void
    {
        $entries = scandir($this->tempDir);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $this->tempDir . '/' . $entry;
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
        @rmdir($this->tempDir);
    }

    private function executeClear(array $args): string
    {
        $output = new ConsoleOutput(buffered: true);
        $command = new CacheClearCommand(new CacheManager(new ArrayCache(), $this->pages, 'array'));
        $exit = $command->execute($args, $output);

        $this->assertSame(CommandInterface::SUCCESS, $exit);

        return $output->stdout();
    }

    public function testClearWithoutFlagsEmptiesBothTheDataAndThePageCache(): void
    {
        $this->pages->store(Request::create('GET', '/'), Response::html('<h1>Accueil</h1>'));

        $output = $this->executeClear([]);

        $this->assertStringContainsString('Cache de pages vidé (1 page(s) supprimée(s))', $output);
        $this->assertStringContainsString('Cache de données vidé', $output);
        $this->assertNull($this->pages->lookup(Request::create('GET', '/')));
    }

    public function testPagesFlagOnlyDropsThePageCacheAndLeavesCachedDataUntouched(): void
    {
        $data = new ArrayCache();
        $data->set('settings:all', ['site_name' => 'Prime'], 300);
        $this->pages->store(Request::create('GET', '/blog'), Response::html('<h1>Blog</h1>'));

        $output = new ConsoleOutput(buffered: true);
        (new CacheClearCommand(new CacheManager($data, $this->pages, 'array')))->execute(['--pages'], $output);

        $this->assertNull($this->pages->lookup(Request::create('GET', '/blog')));
        $this->assertSame(['site_name' => 'Prime'], $data->get('settings:all'));
        $this->assertStringNotContainsString('Cache de données vidé', $output->stdout());
    }

    public function testPruneFlagRemovesOnlyExpiredEntries(): void
    {
        $now = 1_700_000_000;
        $pages = new PageCache($this->tempDir, true, 10, null, static function () use (&$now): int {
            return $now;
        });
        $pages->store(Request::create('GET', '/'), Response::html('<h1>Vieux</h1>'), 10);

        $now += 3600;
        $pages->store(Request::create('GET', '/recent'), Response::html('<h1>Récent</h1>'), 600);

        $output = new ConsoleOutput(buffered: true);
        (new CacheClearCommand(new CacheManager(new ArrayCache(), $pages, 'array')))->execute(['--prune'], $output);

        $this->assertStringContainsString('Entrées expirées supprimées', $output->stdout());
        $this->assertStringContainsString('1 (pages)', $output->stdout());
        $this->assertNotNull($pages->lookup(Request::create('GET', '/recent')));
    }

    public function testStatisticsExposeNullLastPurgeUntilTheCacheIsActuallyFlushed(): void
    {
        $this->assertNull($this->pages->lastPurgedAt());

        $this->pages->store(Request::create('GET', '/'), Response::html('<h1>Accueil</h1>'));
        $this->pages->purgeAll();

        $stats = $this->pages->stats();

        $this->assertSame(0, $stats['entries']);
        $this->assertIsInt($stats['last_purged_at']);
        $this->assertSame($this->pages->lastPurgedAt(), $stats['last_purged_at']);
    }
}
