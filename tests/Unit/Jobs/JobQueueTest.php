<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Jobs;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\Jobs\Job;
use Prime\Jobs\JobHandlerRegistry;
use Prime\Jobs\JobQueue;
use Prime\Jobs\JobRepository;
use Prime\Tests\Support\MysqlEmulatingPdo;
use RuntimeException;

/**
 * What a worker guarantees: a handler is never run twice for the same reservation, a throw retries
 * with a growing delay, and the last attempt parks the job instead of losing it.
 */
final class JobQueueTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $runs = [];

    private PDO $pdo;

    private Hooks $hooks;

    private JobRepository $repository;

    private JobHandlerRegistry $handlers;

    protected function setUp(): void
    {
        $this->runs = [];
        $this->pdo = new MysqlEmulatingPdo('id');
        $this->pdo->exec(
            'CREATE TABLE `pc_jobs` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `queue` TEXT NOT NULL DEFAULT \'default\','
            . ' `handler` TEXT NOT NULL, `payload` TEXT NOT NULL, `attempts` INTEGER NOT NULL DEFAULT 0,'
            . ' `max_attempts` INTEGER NOT NULL DEFAULT 3, `available_at` TEXT NOT NULL, `reserved_at` TEXT NULL,'
            . ' `failed_at` TEXT NULL, `last_error` TEXT NULL, `created_at` TEXT NOT NULL)',
        );

        $this->hooks = new Hooks();
        $this->repository = new JobRepository(new Connection(['prefix' => 'pc_'], hooks: $this->hooks, debug: false, pdo: $this->pdo));
        $this->handlers = new JobHandlerRegistry();
    }

    public function testDispatchRejectsAHandlerNobodyImplements(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->queue()->dispatch('ghost.handler');
    }

    public function testASuccessfulJobIsRunOnceAndForgotten(): void
    {
        $this->handlers->register('notify', function (array $payload, Job $job): void {
            $this->runs[] = sprintf('%s:%s:%d', $payload['to'], $job->queue, $job->attempts);
        });

        $queue = $this->queue();
        $queue->dispatch('notify', ['to' => 'admin@example.com']);

        $result = $queue->runNext('worker-1');
        $this->assertNotNull($result);
        $this->assertSame('completed', $result['status']);
        $this->assertSame(['admin@example.com:default:1'], $this->runs);
        $this->assertSame(['pending' => 0, 'reserved' => 0, 'failed' => 0], array_slice($this->repository->stats(), 0, 3, true));

        $this->assertNull($queue->runNext('worker-1'));
        $this->assertSame(['admin@example.com:default:1'], $this->runs, 'a completed job is never replayed');
    }

    public function testIsQueuedAnswersWhetherTheWorkIsAlreadyComing(): void
    {
        $this->handlers->register('content.publish_scheduled', function (): void {
            $this->runs[] = 'publish';
        });

        $queue = $this->queue();
        $this->assertFalse($queue->isQueued('content.publish_scheduled'));

        $queue->dispatch('content.publish_scheduled');
        $this->assertTrue($queue->isQueued('content.publish_scheduled'), 'a waiting job makes the next request a no-op');

        $this->assertNotNull($queue->runNext('worker-1'));
        $this->assertFalse($queue->isQueued('content.publish_scheduled'), 'a completed job frees the slot again');
        $this->assertSame(['publish'], $this->runs);
    }

    public function testFailuresRetryWithAnExponentialBackoffThenPark(): void
    {
        $this->handlers->register('flaky', function (array $payload, Job $job): void {
            $this->runs[] = $job->attempts;

            throw new RuntimeException('Panne temporaire');
        });

        $queue = $this->queue(baseBackoff: 10);
        $id = $queue->dispatch('flaky', [], 'default', 0, 3);

        $first = $queue->runNext('worker-1');
        $this->assertNotNull($first);
        $this->assertSame('released', $first['status']);
        $this->assertSame(10, $first['delay']);
        $this->assertSame('Panne temporaire', $first['error']);

        // Still inside the backoff window: nothing is due.
        $this->assertNull($queue->runNext('worker-1'));

        $second = $queue->runNext('worker-1', null, time() + 11);
        $this->assertNotNull($second);
        $this->assertSame('released', $second['status']);
        $this->assertSame(20, $second['delay']);

        $third = $queue->runNext('worker-1', null, time() + 21);
        $this->assertNotNull($third);
        $this->assertSame('failed', $third['status'], 'the third try is the last one allowed');
        $this->assertSame([1, 2, 3], $this->runs);

        $failed = $this->repository->failed();
        $this->assertCount(1, $failed);
        $this->assertSame($id, $failed[0]->id);
        $this->assertSame('Panne temporaire', $failed[0]->lastError);
        $this->assertNull($queue->runNext('worker-1', null, time() + 3600));
    }

    public function testHooksObserveTheWholeLifeOfAJob(): void
    {
        $seen = [];
        $this->hooks->on('job.pushed', static function (string $handler, int $id, string $queue) use (&$seen): void {
            $seen[] = "pushed:{$handler}:{$queue}";
        });
        $this->hooks->on('job.started', static function (Job $job) use (&$seen): void {
            $seen[] = "started:{$job->id}";
        });
        $this->hooks->on('job.completed', static function (Job $job) use (&$seen): void {
            $seen[] = "completed:{$job->id}";
        });

        $this->handlers->register('quiet', static function (): void {
            // Nothing to do; the hooks are the subject of this test.
        });

        $queue = $this->queue();
        $id = $queue->dispatch('quiet');
        $queue->runNext('worker-1');

        $this->assertSame(["pushed:quiet:default", "started:{$id}", "completed:{$id}"], $seen);
    }

    public function testJobReleasedByAFailingHandlerEmitsTheDelay(): void
    {
        $delays = [];
        $this->hooks->on('job.released', static function (Job $job, int $delay) use (&$delays): void {
            $delays[$job->id] = $delay;
        });

        $this->handlers->register('boom', static function (): void {
            throw new RuntimeException('nope');
        });

        $queue = $this->queue();
        $id = $queue->dispatch('boom', [], 'default', 0, 5);
        $queue->runNext('worker-1');

        $this->assertSame([$id => 5], $delays);
    }

    public function testDrainReportsTheSummaryOfOneTick(): void
    {
        $this->handlers->register('ok', static function (): void {
        });
        $this->handlers->register('bad', static function (): void {
            throw new RuntimeException('nope');
        });

        $queue = $this->queue();
        $queue->dispatch('ok');
        $queue->dispatch('ok');
        $queue->dispatch('bad', [], 'default', 0, 1);

        $summary = $queue->drain(10, null, 'worker-1');

        $this->assertSame(2, $summary['processed']);
        $this->assertSame(1, $summary['failed']);
        $this->assertSame(0, $summary['released']);
        $this->assertTrue($summary['idle'], 'the queue ran dry before the job limit');
    }

    public function testBackoffGrowsAndIsCapped(): void
    {
        $queue = $this->queue(baseBackoff: 30, maxBackoff: 240);

        $this->assertSame(30, $queue->backoffFor(1));
        $this->assertSame(60, $queue->backoffFor(2));
        $this->assertSame(120, $queue->backoffFor(3));
        $this->assertSame(240, $queue->backoffFor(4));
        $this->assertSame(240, $queue->backoffFor(9));
    }

    public function testAJobWithoutADroppedHandlerIsParkedInsteadOfLooping(): void
    {
        $queue = $this->queue();
        $id = $this->repository->push('deleted.extension');

        $result = $queue->runNext('worker-1');

        $this->assertNotNull($result);
        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('deleted.extension', (string) $result['error']);
        $this->assertSame([$id], array_map(static fn (Job $job): int => $job->id, $this->repository->failed()));
    }

    private function queue(int $baseBackoff = 5, int $maxBackoff = 900): JobQueue
    {
        return new JobQueue($this->repository, $this->handlers, $this->hooks, null, 3, $baseBackoff, $maxBackoff);
    }
}
