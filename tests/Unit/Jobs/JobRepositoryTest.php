<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Jobs;

use PDO;
use PHPUnit\Framework\TestCase;
use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\Jobs\JobRepository;
use Prime\Tests\Support\MysqlEmulatingPdo;

/**
 * Reservation semantics of `pc_jobs`: one claim per job, retries that stay visible, and stuck
 * reservations that come back to the queue instead of blocking a job forever.
 */
final class JobRepositoryTest extends TestCase
{
    private PDO $pdo;

    private JobRepository $jobs;

    protected function setUp(): void
    {
        $this->pdo = new MysqlEmulatingPdo('id');
        $this->pdo->exec(
            'CREATE TABLE `pc_jobs` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `queue` TEXT NOT NULL DEFAULT \'default\','
            . ' `handler` TEXT NOT NULL, `payload` TEXT NOT NULL, `attempts` INTEGER NOT NULL DEFAULT 0,'
            . ' `max_attempts` INTEGER NOT NULL DEFAULT 3, `available_at` TEXT NOT NULL, `reserved_at` TEXT NULL,'
            . ' `failed_at` TEXT NULL, `last_error` TEXT NULL, `created_at` TEXT NOT NULL)',
        );

        $this->jobs = new JobRepository(new Connection(['prefix' => 'pc_'], hooks: new Hooks(), debug: false, pdo: $this->pdo));
    }

    public function testAPushedJobIsClaimedOnceAndDeletedOnCompletion(): void
    {
        $id = $this->jobs->push('content.publish_scheduled', ['reason' => 'cron']);

        $job = $this->jobs->claim('worker-1');

        $this->assertNotNull($job);
        $this->assertSame($id, $job->id);
        $this->assertSame('content.publish_scheduled', $job->handler);
        $this->assertSame(['reason' => 'cron'], $job->payload);
        $this->assertSame(1, $job->attempts, 'claiming already counts as an attempt');
        $this->assertTrue($job->isReserved());

        $this->assertNull($this->jobs->claim('worker-2'), 'a reserved job must not be handed to a second worker');

        $this->jobs->complete($id);
        $this->assertNull($this->jobs->claim('worker-3'));
    }

    public function testQueueNamesAndDelaysAreRespected(): void
    {
        $this->jobs->push('a.send', [], 'emails', 600);
        $this->jobs->push('b.send', [], 'default');

        $job = $this->jobs->claim('worker-1', 'emails');
        $this->assertNull($job, 'a delayed job is not due yet');

        $job = $this->jobs->claim('worker-1');
        $this->assertNotNull($job);
        $this->assertSame('b.send', $job->handler);

        // Twelve minutes later the delayed job is due, but only on its own queue.
        $later = time() + 720;
        $this->assertNull($this->jobs->claim('worker-1', 'other', $later));
        $job = $this->jobs->claim('worker-1', 'emails', $later);
        $this->assertNotNull($job);
        $this->assertSame('a.send', $job->handler);
        $this->assertSame('emails', $job->queue);
    }

    public function testReleasePutsTheJobBackWithTheRequestedDelay(): void
    {
        $id = $this->jobs->push('http.fetch', ['url' => 'https://example.com']);
        $job = $this->jobs->claim('worker-1');
        $this->assertNotNull($job);

        $this->jobs->release($id, 'Timeout', 30);

        $this->assertNull($this->jobs->claim('worker-1'), 'the backoff window must be honoured');
        $retry = $this->jobs->claim('worker-1', null, time() + 31);
        $this->assertNotNull($retry);
        $this->assertSame(2, $retry->attempts);
        $this->assertSame('Timeout', $retry->lastError);
        $this->assertFalse($retry->hasFailed());
    }

    public function testFailedJobsAreParkedAndCanBeRetried(): void
    {
        $id = $this->jobs->push('http.fetch', [], 'default', 0, 2);

        $this->jobs->claim('worker-1');
        $this->jobs->markFailed($id, 'Service unavailable');

        $this->assertNull($this->jobs->claim('worker-1'), 'a failed job leaves the claimable set');

        $failed = $this->jobs->failed();
        $this->assertCount(1, $failed);
        $this->assertSame('Service unavailable', $failed[0]->lastError);
        $this->assertSame(1, $failed[0]->attempts);
        $this->assertSame(2, $failed[0]->maxAttempts);
        $this->assertFalse($failed[0]->exhausted(), 'one try of two is not the last one');

        $this->assertTrue($this->jobs->retry($id, 5));
        $this->assertFalse($this->jobs->retry($id), 'a pending job has nothing to retry');

        $due = $this->jobs->claim('worker-1', null, time() + 6);
        $this->assertNotNull($due);
        $this->assertSame(1, $due->attempts, 'a manual retry restarts the attempt counter');
        $this->assertNull($due->lastError);
    }

    public function testStuckReservationsAreReleasedWithoutTouchingAttempts(): void
    {
        $this->jobs->push('slow.report');
        $this->assertNotNull($this->jobs->claim('worker-1'));

        $this->assertSame(0, $this->jobs->releaseStuck(600), 'the reservation is fresh');
        $this->assertSame(1, $this->jobs->releaseStuck(600, time() + 601));

        $job = $this->jobs->claim('worker-2', null, time() + 601);
        $this->assertNotNull($job);
        $this->assertSame(2, $job->attempts);
        $this->assertStringContainsString('expirée', (string) $job->lastError);
    }

    public function testPruningOnlyRemovesOldFailures(): void
    {
        $kept = $this->jobs->push('a.job');
        $this->jobs->claim('worker-1');
        $this->jobs->markFailed($kept, 'boom');

        $this->assertSame(0, $this->jobs->pruneFailed(60), 'the failure is younger than the TTL');

        $gone = $this->jobs->push('b.job');
        $this->jobs->claim('worker-1');
        $this->jobs->markFailed($gone, 'boom', time() - 7200);

        $this->assertSame(1, $this->jobs->pruneFailed(60));
        $this->assertSame([$kept], array_map(static fn (object $job): int => $job->id, $this->jobs->failed()));
    }

    public function testStatsSeparatePendingRunningDelayedAndFailed(): void
    {
        $this->jobs->push('one');
        $this->jobs->push('two');
        $this->jobs->push('three', [], 'default', 600);
        $this->jobs->claim('worker-1');
        $this->jobs->claim('worker-1');
        $broken = $this->jobs->push('four');
        $this->jobs->claim('worker-1');
        $this->jobs->markFailed($broken, 'nope');

        $stats = $this->jobs->stats();

        $this->assertSame(0, $stats['pending']);
        $this->assertSame(2, $stats['reserved']);
        $this->assertSame(1, $stats['delayed']);
        $this->assertSame(1, $stats['failed']);
        $this->assertNotNull($stats['oldest_pending']);
        $this->assertSame(['default'], $this->jobs->queues());
    }

    public function testPurgeKeepsTheFailureHistory(): void
    {
        $this->jobs->push('a');
        $failed = $this->jobs->push('b');
        $this->jobs->markFailed($failed, 'kept');

        $this->assertSame(1, $this->jobs->purge());
        $this->assertCount(1, $this->jobs->failed());

        $this->assertSame(0, $this->jobs->purge());
    }

    public function testQueuedCountCoversWhateverStillHasAChanceToRun(): void
    {
        $this->assertSame(0, $this->jobs->queuedCount('content.publish_scheduled'));

        $mine = $this->jobs->push('content.publish_scheduled', ['reason' => 'admin']);
        $this->jobs->push('content.publish_scheduled', [], 'default', 600);
        $this->jobs->push('mail.send');

        // Pending and delayed both count: either way, the work is coming.
        $this->assertSame(2, $this->jobs->queuedCount('content.publish_scheduled'));

        // A worker holding the job counts too — that is what keeps a request burst from queueing twins.
        $this->assertNotNull($this->jobs->claim('worker-1'));
        $this->assertSame(2, $this->jobs->queuedCount('content.publish_scheduled'));

        // A parked failure does not: retrying it is an administrator's decision, not an automatic twin.
        $this->jobs->markFailed($mine, 'boom');
        $this->assertSame(1, $this->jobs->queuedCount('content.publish_scheduled'));
    }

    public function testPayloadSurvivesUnicodeAndNesting(): void
    {
        $payload = ['title' => 'Réglages « sécurité »', 'nested' => ['ids' => [3, 7]], 'empty' => []];
        $this->jobs->push('unicode.job', $payload);

        $job = $this->jobs->claim('worker-1');
        $this->assertNotNull($job);
        $this->assertSame($payload, $job->payload);
    }
}
