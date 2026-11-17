<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\Scheduler\Schedule;
use Prime\Scheduler\SchedulerFallback;
use Prime\Scheduler\SchedulerRunner;
use Prime\Settings\Settings;
use Prime\Tests\Support\MysqlEmulatingPdo;
use RuntimeException;

/**
 * The visitor-time tick: the cost of running the schedule without cron falls on one request per
 * interval, it never turns a 500 for a visitor, and it disappears entirely when the settings say so.
 */
final class SchedulerFallbackTest extends TestCase
{
    private const TUESDAY_0803 = '2026-11-17 08:03:00';

    private string $stampDirectory = '';

    private Hooks $hooks;

    private Settings $settings;

    /**
     * @var list<string>
     */
    private array $runs = [];

    protected function setUp(): void
    {
        $this->runs = [];
        $this->hooks = new Hooks();
        $this->stampDirectory = sys_get_temp_dir() . '/prime_schedule_fallback_' . uniqid('', true);
        $this->settings = $this->makeSettings();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->stampDirectory);
    }

    public function testOneRequestPerIntervalPaysForTheTick(): void
    {
        $fallback = $this->fallback($this->schedule());
        $now = $this->timestamp(self::TUESDAY_0803);

        $this->assertSame(1, $fallback->maybeRun($now));
        $this->assertSame(['heartbeat'], $this->runs);

        // Four more visitors in the same minute: none of them runs anything.
        $this->assertSame(0, $fallback->maybeRun($now + 5));
        $this->assertSame(0, $fallback->maybeRun($now + 20));
        $this->assertSame(0, $fallback->maybeRun($now + 59));
        $this->assertSame(['heartbeat'], $this->runs);

        // The interval has elapsed, the next request picks the tick up.
        $this->assertSame(1, $fallback->maybeRun($now + 61));
        $this->assertSame(['heartbeat', 'heartbeat'], $this->runs);
    }

    public function testAFirstTickCreatesItsOwnDirectory(): void
    {
        // On a fresh install `storage/cache/scheduler` does not exist yet, and this tick is what
        // creates it — losing the stamp would silently disable the whole fallback.
        $this->assertDirectoryDoesNotExist($this->stampDirectory);

        $this->assertSame(1, $this->fallback($this->schedule())->maybeRun($this->timestamp(self::TUESDAY_0803)));

        $this->assertDirectoryExists($this->stampDirectory);
        $this->assertFileExists($this->stampDirectory . '/heartbeat.tick');
        $this->assertFileExists($this->stampPath());
    }

    public function testTheIntervalComesFromTheSettings(): void
    {
        $this->settings->set('scheduler.interval', '600');
        $fallback = $this->fallback($this->schedule());
        $now = $this->timestamp(self::TUESDAY_0803);

        $this->assertSame(600, $fallback->intervalSeconds());
        $this->assertSame(1, $fallback->maybeRun($now));
        $this->assertSame(0, $fallback->maybeRun($now + 300));
        $this->assertSame(1, $fallback->maybeRun($now + 601));
    }

    public function testAnAbsurdIntervalIsClampedInsteadOfHonoured(): void
    {
        // A `0` second interval would turn every page view into a scheduler tick.
        $this->settings->set('scheduler.interval', '1');

        $this->assertSame(10, $this->fallback($this->schedule())->intervalSeconds());

        $this->settings->set('scheduler.interval', '999999');
        $this->assertSame(3600, $this->fallback($this->schedule())->intervalSeconds());
    }

    public function testTheTickIsSilentWithoutSettingsToRead(): void
    {
        $schedule = $this->schedule();
        $runner = new SchedulerRunner($schedule, $this->stampDirectory, $this->hooks);
        $fallback = new SchedulerFallback($runner, $this->stampPath());

        $this->assertFalse($fallback->enabled());
        $this->assertSame(0, $fallback->maybeRun($this->timestamp(self::TUESDAY_0803)));
        $this->assertSame([], $this->runs);
    }

    public function testADisabledSchedulerLeavesTheStampAlone(): void
    {
        $this->settings->set('scheduler.enabled', '0');

        $fallback = $this->fallback($this->schedule());
        $this->assertFalse($fallback->enabled());
        $this->assertSame(0, $fallback->maybeRun($this->timestamp(self::TUESDAY_0803)));

        $this->assertSame([], $this->runs);
        $this->assertFileDoesNotExist($this->stampPath());
    }

    public function testAVisitorlessInstallCanStillUseCronOnly(): void
    {
        $this->settings->set('scheduler.fallback', '0');
        $this->settings->set('scheduler.enabled', '1');

        $fallback = $this->fallback($this->schedule());
        $this->assertFalse($fallback->enabled());
        $this->assertSame(0, $fallback->maybeRun($this->timestamp(self::TUESDAY_0803)));
        $this->assertSame([], $this->runs);
    }

    public function testAConcurrentRequestDoesNotQueueASecondTick(): void
    {
        $schedule = $this->schedule();
        $runner = new SchedulerRunner($schedule, $this->stampDirectory, $this->hooks);
        $fallback = new SchedulerFallback($runner, $this->stampPath(), $this->settings);

        @mkdir($this->stampDirectory, 0775, true);
        $held = fopen($this->stampPath(), 'c+');
        $this->assertIsResource($held);
        $this->assertTrue(flock($held, LOCK_EX | LOCK_NB));

        try {
            $this->assertSame(0, $fallback->maybeRun($this->timestamp(self::TUESDAY_0803)));
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }

        // Whoever holds the stamp is the one that runs the schedule; nobody else pays for it.
        $this->assertSame([], $this->runs);
    }

    public function testAFailingTickNeverBecomesAnErrorForTheVisitor(): void
    {
        $schedule = $this->schedule();
        $this->hooks->on('scheduler.ran', static function (): void {
            throw new RuntimeException('Un extension casse le tick');
        });

        $runner = new SchedulerRunner($schedule, $this->stampDirectory, $this->hooks);
        $fallback = new SchedulerFallback($runner, $this->stampPath(), $this->settings);

        $this->assertSame(0, $fallback->maybeRun($this->timestamp(self::TUESDAY_0803)));
        $this->assertSame(['heartbeat'], $this->runs);
    }

    public function testTheStampHoldsTheTimeOfTheLastTick(): void
    {
        $fallback = $this->fallback($this->schedule());
        $now = $this->timestamp(self::TUESDAY_0803);

        $fallback->maybeRun($now);

        $this->assertSame((string) $now, trim((string) file_get_contents($this->stampPath())));
    }

    private function schedule(): Schedule
    {
        $schedule = new Schedule();
        $schedule->call('heartbeat', function (): void {
            $this->runs[] = 'heartbeat';
        }, '* * * * *');

        return $schedule;
    }

    private function fallback(Schedule $schedule): SchedulerFallback
    {
        $runner = new SchedulerRunner($schedule, $this->stampDirectory, $this->hooks);

        return new SchedulerFallback($runner, $this->stampPath(), $this->settings);
    }

    private function stampPath(): string
    {
        return $this->stampDirectory . '/fallback.tick';
    }

    private function makeSettings(): Settings
    {
        $pdo = new MysqlEmulatingPdo('name');
        $pdo->exec(
            'CREATE TABLE `pc_settings` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `name` TEXT UNIQUE NOT NULL,'
            . ' `value` TEXT NULL, `autoload` INTEGER NOT NULL DEFAULT 1, `created_at` TEXT NOT NULL, `updated_at` TEXT NOT NULL)',
        );

        $connection = new Connection(['prefix' => 'pc_'], hooks: new Hooks(), debug: false, pdo: $pdo);

        return new Settings($connection);
    }

    private function timestamp(string $moment): int
    {
        return (new DateTimeImmutable($moment, new DateTimeZone('UTC')))->getTimestamp();
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}
