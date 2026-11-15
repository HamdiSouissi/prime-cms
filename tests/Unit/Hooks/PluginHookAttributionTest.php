<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Hooks;

use PHPUnit\Framework\TestCase;
use Prime\Hooks\Hooks;
use RuntimeException;
use Throwable;

final class PluginHookAttributionTest extends TestCase
{
    public function testAttributesListenersAndFiltersRegisteredInsideWithOwner(): void
    {
        $hooks = new Hooks();

        $hooks->on('content.saved', static function (): void {});
        $hooks->withOwner('hello-prime', static function () use ($hooks): void {
            $hooks->on('content.saved', static function (): void {}, priority: 5);
            $hooks->filter('content.title', static fn (string $v): string => $v . '!', priority: 15);
        });

        $saved = $hooks->inspect('content.saved');
        $this->assertCount(2, $saved);
        $this->assertNull($saved[0]['owner']);
        $this->assertSame('hello-prime', $saved[1]['owner']);

        $title = $hooks->inspect('content.title');
        $this->assertCount(1, $title);
        $this->assertSame('hello-prime', $title[0]['owner']);
    }

    public function testPluginListenerExceptionIsIsolatedWhileOtherListenersContinue(): void
    {
        $hooks = new Hooks();
        $failures = [];
        $called = [];

        $hooks->setOwnerErrorHandler(static function (string $owner, string $hookName, Throwable $e) use (&$failures): void {
            $failures[] = [$owner, $hookName, $e->getMessage()];
        });

        $hooks->withOwner('broken-plugin', static function () use ($hooks): void {
            $hooks->on('app.ready', static function (): void {
                throw new RuntimeException('Plugin boom');
            }, priority: 5);
            // Second callback from same plugin should be detached after first failure
            $hooks->on('app.other', static function (): void {}, priority: 10);
        });

        $hooks->withOwner('healthy-plugin', static function () use ($hooks, &$called): void {
            $hooks->on('app.ready', static function () use (&$called): void {
                $called[] = 'healthy';
            }, priority: 10);
        });

        $hooks->on('app.ready', static function () use (&$called): void {
            $called[] = 'core';
        }, priority: 20);

        $hooks->emit('app.ready');

        $this->assertSame([['broken-plugin', 'app.ready', 'Plugin boom']], $failures);
        $this->assertSame(['healthy', 'core'], $called);
        $this->assertFalse($hooks->has('app.other'));
    }

    public function testPluginFilterExceptionPreservesValueAndCoreListenerStillThrows(): void
    {
        $hooks = new Hooks();
        $caughtOwner = null;

        $hooks->setOwnerErrorHandler(static function (string $owner) use (&$caughtOwner): void {
            $caughtOwner = $owner;
        });

        $hooks->filter('page.title', static fn (string $v): string => $v . ' [core1]', priority: 5);
        $hooks->withOwner('faulty-filter', static function () use ($hooks): void {
            $hooks->filter('page.title', static function (): string {
                throw new RuntimeException('Filter crash');
            }, priority: 10);
        });
        $hooks->filter('page.title', static fn (string $v): string => $v . ' [core2]', priority: 20);

        $result = $hooks->apply('page.title', 'Accueil');

        $this->assertSame('Accueil [core1] [core2]', $result);
        $this->assertSame('faulty-filter', $caughtOwner);

        // Core listeners MUST still propagate exceptions normally
        $hooks->on('core.boom', static function (): void {
            throw new RuntimeException('Core failure');
        });
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Core failure');
        $hooks->emit('core.boom');
    }
}
