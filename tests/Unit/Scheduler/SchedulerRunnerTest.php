<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Prime\Hooks\Hooks;
use Prime\Scheduler\Schedule;
use Prime\Scheduler\SchedulerRunner;
use RuntimeException;

/**
 * What the runner owes an installation: a task runs once per cron minute, a window missed while the
 * runner was down is caught up exactly once, a failing task records its error without being retried
 * every minute, and two overlapping runners never duplicate the work.
 */
final class SchedulerRunnerTest extends TestCase
{
    private const TUESDAY_0803 = '2026-11-17 08:03:00';

    private string $stampDirectory = '';

    private Hooks $hooks;

    /**
     * @var list<string>
     */
    private array $runs = [];

    protected function setUp(): void
    {
        $this->runs = [];
        $this->hooks = new Hooks();
        $this->stampDirectory = sys_get_temp_dir() . '/prime_schedule_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->stampDirectory);
    }

    public function testADueTaskRunsOncePerMatchedMinute(): void
    {
        $schedule = new Schedule();
        $schedule->call('heartbeat', function (): void {
            $this->runs[] = 'heartbeat';
        }, '* * * * *');

        $runner = $this->runner($schedule);
        $now = $this->timestamp(self::TUESDAY_0803);

        $results = $runner->run($now);

        $this->assertCount(1, $results);
        $this->assertSame('ran', $results[0]['status']);
        $this->assertSame('2026-11-17 08:03', $results[0]['tick']);
        $this->assertNull($results[0]['error']);
        $this->assertSame(['heartbeat'], $this->runs);

        // Same minute, second tick (the docker service and a visitor request overlapping): nothing runs.
        $this->assertSame([], $runner->run($now + 40));
        $this->assertSame(['heartbeat'], $this->runs);

        // Next minute, next tick.
        $this->assertCount(1, $runner->run($now + 60));
        $this->assertSame(['heartbeat', 'heartbeat'], $this->runs);
    }

    public function testAWindowMissedWhileTheRunnerWasDownIsCaughtUpOnce(): void
    {
        $schedule = new Schedule();
        $schedule->call('nightly', function (): void {
            $this->runs[] = 'nightly';
        }, '30 3 * * *');

        $runner = $this->runner($schedule);

        // The 16th is processed normally: its window had already opened when the runner ticked.
        $this->assertSame(['nightly' => '2026-11-16 03:30'], $this->ticks($runner->run($this->timestamp('2026-11-16 04:00:00'))));

        // The runner is then stopped for a day; the 17th window is still owed and runs late, once.
        $results = $runner->run($this->timestamp('2026-11-17 07:10:00'));
        $this->assertSame(['nightly' => '2026-11-17 03:30'], $this->ticks($results));
        $this->assertSame(['nightly', 'nightly'], $this->runs);

        // …and only once: ten minutes later the task is up to date again.
        $this->assertSame([], $runner->run($this->timestamp('2026-11-17 07:20:00')));
        $this->assertSame(['nightly', 'nightly'], $this->runs);
    }

    public function testAFailingTaskRecordsItsErrorAndKeepsTheTick(): void
    {
        $schedule = new Schedule();
        $schedule->call('broken', static function (): void {
            throw new RuntimeException('Base injoignable');
        }, '* * * * *');

        $runner = $this->runner($schedule);
        $results = $runner->run($this->timestamp(self::TUESDAY_0803));

        $this->assertSame('error', $results[0]['status']);
        $this->assertSame('Base injoignable', $results[0]['error']);
        $this->assertFileExists($this->stampDirectory . '/broken.error');
        $this->assertStringContainsString('Base injoignable', (string) file_get_contents($this->stampDirectory . '/broken.error'));

        // The tick is stamped even on failure: no retry storm every minute.
        $this->assertSame(['broken' => '2026-11-17 08:03'], $runner->lastRuns());
        $this->assertSame(['broken' => 'Base injoignable'], $this->messages($runner->lastErrors()));
    }

    public function testASuccessfulRunClearsTheStaleErrorFile(): void
    {
        $schedule = new Schedule();
        $fail = true;
        $schedule->call('flaky', function () use (&$fail): void {
            if ($fail) {
                $fail = false;

                throw new RuntimeException('Temporaire');
            }

            $this->runs[] = 'flaky';
        }, '* * * * *');

        $runner = $this->runner($schedule);
        $now = $this->timestamp(self::TUESDAY_0803);

        $this->assertSame('error', $runner->run($now)[0]['status']);
        $this->assertFileExists($this->stampDirectory . '/flaky.error');

        $this->assertSame('ran', $runner->run($now + 60)[0]['status']);
        $this->assertFileDoesNotExist($this->stampDirectory . '/flaky.error');
        $this->assertSame([], $runner->lastErrors());
        $this->assertSame(['flaky'], $this->runs);
    }

    public function testAnOverlappingRunIsSkippedInsteadOfDuplicated(): void
    {
        $schedule = new Schedule();
        $schedule->call('long', function (): void {
            $this->runs[] = 'long';
        }, '* * * * *');

        $runner = $this->runner($schedule);
        $now = $this->timestamp(self::TUESDAY_0803);

        @mkdir($this->stampDirectory, 0775, true);
        $held = fopen($this->stampDirectory . '/long.lock', 'c');
        $this->assertIsResource($held);
        $this->assertTrue(flock($held, LOCK_EX | LOCK_NB));

        try {
            $results = $runner->run($now);
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }

        $this->assertSame([], $this->runs);
        $this->assertCount(1, $results);
        $this->assertSame('skipped', $results[0]['status']);
        $this->assertStringContainsString('Exécution déjà en cours', (string) $results[0]['error']);

        // A skipped tick is not stamped, so the next tick still runs the work.
        $this->assertSame([], $runner->lastRuns());
        $this->assertSame('ran', $runner->run($now + 60)[0]['status']);
        $this->assertSame(['long'], $this->runs);
    }

    public function testATaskThatOptedOutOfTheLockStillRunsWithAHolderBusy(): void
    {
        $schedule = new Schedule();
        $schedule->call('rapid', function (): void {
            $this->runs[] = 'rapid';
        }, '* * * * *', null, 'core', false);

        $runner = $this->runner($schedule);
        @mkdir($this->stampDirectory, 0775, true);
        $held = fopen($this->stampDirectory . '/rapid.lock', 'c');
        $this->assertIsResource($held);
        $this->assertTrue(flock($held, LOCK_EX | LOCK_NB));

        try {
            $results = $runner->run($this->timestamp(self::TUESDAY_0803));
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }

        // `withoutOverlapping` off means "never report a skip", even against a stale lock holder.
        $this->assertSame('ran', $results[0]['status']);
        $this->assertSame(['rapid'], $this->runs);
    }

    public function testForceRunsEveryTaskOnceWhateverTheClockSays(): void
    {
        $schedule = new Schedule();
        $schedule->call('heartbeat', function (): void {
            $this->runs[] = 'heartbeat';
        }, '* * * * *');
        $schedule->call('nightly', function (): void {
            $this->runs[] = 'nightly';
        }, '30 3 * * *');
        $schedule->call('quiet', function (): void {
            $this->runs[] = 'quiet';
        }, '30 3 1 1 *');

        $runner = $this->runner($schedule);
        $now = $this->timestamp('2026-11-17 14:05:00');

        // First tick: every task owes its last window, so the whole schedule runs.
        $this->assertSame(['heartbeat', 'nightly', 'quiet'], array_column($runner->run($now), 'name'));

        // An hour later only the per-minute task has a fresh window.
        $this->assertSame(['heartbeat'], array_column($runner->run($now + 3600), 'name'));
        $this->assertSame([], $runner->dueNames($now + 3630));
        $this->runs = [];

        // `--force` runs everything anyway, once, and stamps the minute it ran.
        $forced = array_column($runner->run($now + 3630, true), 'name');
        sort($forced);

        $this->assertSame(['heartbeat', 'nightly', 'quiet'], $forced);
        $this->assertSame(['heartbeat', 'nightly', 'quiet'], $this->runs);
        $this->assertSame([], $runner->run($now + 3630));
    }

    public function testCommandTasksAreHandedToTheCommandRunner(): void
    {
        $schedule = new Schedule();
        $schedule->command('publish', 'content:publish-scheduled --types=post', '* * * * *');

        $seen = [];
        $runner = new SchedulerRunner(
            $schedule,
            $this->stampDirectory,
            $this->hooks,
            null,
            static function (string $line) use (&$seen): void {
                $seen[] = $line;
            },
        );

        $results = $runner->run($this->timestamp(self::TUESDAY_0803));

        $this->assertSame(['content:publish-scheduled --types=post'], $seen);
        $this->assertSame('ran', $results[0]['status']);
    }

    public function testANonZeroCommandExitIsReportedAsAnError(): void
    {
        $schedule = new Schedule();
        $schedule->command('publish', 'content:publish-scheduled', '* * * * *');

        $runner = new SchedulerRunner(
            $schedule,
            $this->stampDirectory,
            null,
            null,
            static function (): void {
                throw new RuntimeException('La commande « content:publish-scheduled » a échoué (code 1).');
            },
        );

        $results = $runner->run($this->timestamp(self::TUESDAY_0803));

        $this->assertSame('error', $results[0]['status']);
        $this->assertStringContainsString('a échoué (code 1)', (string) $results[0]['error']);
        $this->assertSame(['publish' => '2026-11-17 08:03'], $runner->lastRuns());
    }

    public function testACommandTaskWithoutARunnerFailsLoudly(): void
    {
        $schedule = new Schedule();
        $schedule->command('publish', 'content:publish-scheduled', '* * * * *');

        $results = $this->runner($schedule)->run($this->timestamp(self::TUESDAY_0803));

        $this->assertSame('error', $results[0]['status']);
        $this->assertStringContainsString('exécuteur de commandes', (string) $results[0]['error']);
    }

    public function testHooksReportTheTasksAndTheBatches(): void
    {
        $schedule = new Schedule();
        $schedule->call('heartbeat', static fn (): string => 'ok', '* * * * *');

        $taskTicks = [];
        $batches = [];
        $this->hooks->on('scheduler.task.ran', static function (string $name, string $tick) use (&$taskTicks): void {
            $taskTicks[$name] = $tick;
        });
        $this->hooks->on('scheduler.ran', static function (array $results, bool $force) use (&$batches): void {
            $batches[] = [count($results), $force];
        });

        $runner = $this->runner($schedule);
        $now = $this->timestamp(self::TUESDAY_0803);

        $runner->run($now);
        $runner->run($now + 60, true);

        $this->assertSame(['heartbeat' => '2026-11-17 08:04'], $taskTicks);
        $this->assertSame([[1, false], [1, true]], $batches);
    }

    public function testATaskThatIsNeverDueLeavesNoTrace(): void
    {
        $schedule = new Schedule();
        $schedule->call('leap', static fn () => null, '0 0 31 2 *');

        $runner = $this->runner($schedule);

        // February 31st does not exist: the task has no window to catch up, and no stamp is written.
        $this->assertSame([], $runner->run($this->timestamp(self::TUESDAY_0803)));
        $this->assertSame([], $runner->lastRuns());
        $this->assertSame([], $runner->dueNames($this->timestamp(self::TUESDAY_0803)));
        // Only the scheduler heartbeat remains: the pass happened, no task left a stamp.
        $this->assertSame([$this->stampDirectory . '/heartbeat'], glob($this->stampDirectory . '/*') ?: []);
        $this->assertSame($this->timestamp(self::TUESDAY_0803), $runner->lastHeartbeat());
    }

    public function testRunOneExecutesATaskImmediatelyAndUnknownNamesReturnNull(): void
    {
        $ran = 0;
        $schedule = new Schedule();
        $schedule->call('leap', static function () use (&$ran): void {
            ++$ran;
        }, '0 0 31 2 *');

        $runner = $this->runner($schedule);

        $this->assertNull($runner->runOne('missing'));
        $result = $runner->runOne('leap', $this->timestamp(self::TUESDAY_0803));
        $this->assertNotNull($result);
        $this->assertSame('ran', $result['status']);
        $this->assertSame(1, $ran);
        $this->assertArrayHasKey('leap', $runner->lastRuns());
    }

    public function testDueNamesAndLastRunsDescribeTheSchedule(): void
    {
        $schedule = new Schedule();
        $schedule->call('heartbeat', static fn () => null, '* * * * *');
        $schedule->call('nightly', static fn () => null, '0 3 * * *');
        $schedule->call('mondays', static fn () => null, '0 4 * * 1');

        $runner = $this->runner($schedule);
        $tuesday = $this->timestamp(self::TUESDAY_0803);

        // Nothing has ever run: each task owes its most recent window.
        $this->assertSame(['heartbeat', 'mondays', 'nightly'], $runner->dueNames($tuesday));

        // `tasks()` is ordered by name, so every report below follows that order.
        $this->assertSame(['heartbeat' => '2026-11-17 08:03', 'mondays' => '2026-11-16 04:00', 'nightly' => '2026-11-17 03:00'], $this->ticks($runner->run($tuesday)));
        $this->assertSame([
            'heartbeat' => '2026-11-17 08:03',
            'mondays' => '2026-11-16 04:00',
            'nightly' => '2026-11-17 03:00',
        ], $runner->lastRuns());
        $this->assertSame([], $runner->lastErrors());

        // One minute later the heartbeat is the only one with a window again…
        $this->assertSame(['heartbeat'], $runner->dueNames($tuesday + 60));

        // …and on the following Monday at 04:30 the weekly task owes its tick too (names are sorted).
        $this->assertSame(['heartbeat', 'mondays', 'nightly'], $runner->dueNames($this->timestamp('2026-11-23 04:30:00')));
    }

    /**
     * @param list<array{name: string, status: string, tick: ?string, duration_ms: int, error: ?string}> $results
     *
     * @return array<string, string|null>
     */
    private function ticks(array $results): array
    {
        $ticks = [];

        foreach ($results as $result) {
            $ticks[$result['name']] = $result['tick'];
        }

        return $ticks;
    }

    /**
     * The `[Y-m-d H:i] ` prefix written by the runner is a timestamp, not part of the message.
     *
     * @param array<string, string> $errors
     *
     * @return array<string, string>
     */
    private function messages(array $errors): array
    {
        return array_map(static fn (string $line): string => substr($line, (int) strpos($line, '] ') + 2), $errors);
    }

    private function runner(Schedule $schedule): SchedulerRunner
    {
        return new SchedulerRunner($schedule, $this->stampDirectory, $this->hooks);
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
