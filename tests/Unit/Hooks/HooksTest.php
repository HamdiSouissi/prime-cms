<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Hooks;

use PHPUnit\Framework\TestCase;
use Prime\Hooks\Exception\InvalidHookNameException;
use Prime\Hooks\Hooks;

final class HooksTest extends TestCase
{
    public function testRespectsPriorityAndInsertionOrderOnTies(): void
    {
        $hooks = new Hooks();
        $log = [];

        $hooks->on('app.booted', static function () use (&$log): void {
            $log[] = 'p10-first';
        }, priority: 10);

        $hooks->on('app.booted', static function () use (&$log): void {
            $log[] = 'p5-early';
        }, priority: 5);

        $hooks->on('app.booted', static function () use (&$log): void {
            $log[] = 'p10-second';
        }, priority: 10);

        $hooks->on('app.booted', static function () use (&$log): void {
            $log[] = 'p20-late';
        }, priority: 20);

        $hooks->emit('app.booted');

        $this->assertSame(['p5-early', 'p10-first', 'p10-second', 'p20-late'], $log);
    }

    public function testChainedFiltersPassExtraArgumentsAndTransformValue(): void
    {
        $hooks = new Hooks();

        $hooks->filter('content.title', static fn (string $title, string $suffix): string => $title . ' ' . $suffix, priority: 10);
        $hooks->filter('content.title', static fn (string $title): string => trim($title), priority: 5);
        $hooks->filter('content.title', static fn (string $title): string => strtoupper($title), priority: 20);

        $result = $hooks->apply('content.title', '  prime cms  ', 'v1');

        $this->assertSame('PRIME CMS V1', $result);
        $this->assertSame(1, $hooks->fired('content.title'));
    }

    public function testOffRemovesSpecificOrAllListeners(): void
    {
        $hooks = new Hooks();
        $calls = [];

        $first = static function () use (&$calls): void {
            $calls[] = 'first';
        };
        $second = static function () use (&$calls): void {
            $calls[] = 'second';
        };

        $hooks->on('content.saved', $first);
        $hooks->on('content.saved', $second);

        $this->assertTrue($hooks->has('content.saved'));

        $hooks->off('content.saved', $first);
        $hooks->emit('content.saved');
        $this->assertSame(['second'], $calls);

        $hooks->off('content.saved');
        $this->assertFalse($hooks->has('content.saved'));

        $hooks->emit('content.saved');
        $this->assertSame(['second'], $calls);
        $this->assertSame(2, $hooks->fired('content.saved'));
    }

    public function testAddingListenerDuringExecutionDoesNotBreakIteration(): void
    {
        $hooks = new Hooks();
        $order = [];

        $hooks->on('app.tick', static function () use ($hooks, &$order): void {
            $order[] = 'first';
            $hooks->on('app.tick', static function () use (&$order): void {
                $order[] = 'dynamic';
            }, priority: 1);
        }, priority: 10);

        $hooks->on('app.tick', static function () use (&$order): void {
            $order[] = 'second';
        }, priority: 20);

        $hooks->emit('app.tick');
        $this->assertSame(['first', 'second'], $order);

        $order = [];
        $hooks->emit('app.tick');
        $this->assertSame(['dynamic', 'first', 'second'], $order);
    }

    public function testCurrentTracksNestedHooksAndResetsAfterCompletion(): void
    {
        $hooks = new Hooks();
        $observed = [];

        $this->assertNull($hooks->current());

        $hooks->on('outer.event', static function () use ($hooks, &$observed): void {
            $observed[] = $hooks->current();
            $hooks->emit('inner.event');
            $observed[] = $hooks->current();
        });

        $hooks->on('inner.event', static function () use ($hooks, &$observed): void {
            $observed[] = $hooks->current();
        });

        $hooks->emit('outer.event');

        $this->assertSame(['outer.event', 'inner.event', 'outer.event'], $observed);
        $this->assertNull($hooks->current());
    }

    public function testThrowsOnInvalidHookNames(): void
    {
        $hooks = new Hooks();
        $invalidNames = ['', 'single', 'App.Booted', 'app..booted', '.app.booted', 'app.booted.', 'app booted', 'app-booted'];

        foreach ($invalidNames as $name) {
            try {
                $hooks->on($name, static function (): void {
                });
                $this->fail(sprintf('Expected InvalidHookNameException for hook name [%s].', $name));
            } catch (InvalidHookNameException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
