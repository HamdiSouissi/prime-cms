<?php

declare(strict_types=1);

namespace Prime\Jobs;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Prime\Database\Connection;

/**
 * Storage for queued work (`pc_jobs`).
 *
 * A claim is a two-step optimistic reservation instead of `UPDATE … LIMIT 1`: the candidate id is
 * read first, then a guarded `UPDATE` (still holding no reservation) decides the winner. That is
 * atomic on InnoDB, portable to MariaDB, and lets several `queue:work` processes share one queue
 * without ever running the same job twice.
 */
final class JobRepository
{
    /**
     * How many candidates a single claim walks before giving up (busy queues, lost races).
     */
    private const CLAIM_ATTEMPTS = 8;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Queue a unit of work. `$delaySeconds` pushes the first try into the future.
     *
     * @param array<string, mixed> $payload
     */
    public function push(
        string $handler,
        array $payload = [],
        string $queue = 'default',
        int $delaySeconds = 0,
        ?int $maxAttempts = null,
    ): int {
        $now = time();
        $availableAt = self::stamp($now + max(0, $delaySeconds));

        return $this->db->insert(
            sprintf(
                'INSERT INTO `%s` (`queue`, `handler`, `payload`, `attempts`, `max_attempts`, `available_at`, `created_at`)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?)',
                $this->table(),
            ),
            [
                $queue === '' ? 'default' : $queue,
                $handler,
                self::encode($payload),
                0,
                max(1, (int) $maxAttempts),
                $availableAt,
                self::stamp($now),
            ],
        );
    }

    /**
     * Reserve the next runnable job for `$worker`, or `null` when the queue is empty.
     */
    public function claim(string $worker, ?string $queue = null, ?int $now = null): ?Job
    {
        $now ??= time();
        $table = $this->table();
        $due = self::stamp($now);
        $filters = '`failed_at` IS NULL AND `reserved_at` IS NULL AND `available_at` <= ?';
        $bindings = [$due];

        if ($queue !== null && $queue !== '' && $queue !== '*') {
            $filters .= ' AND `queue` = ?';
            $bindings[] = $queue;
        }

        for ($attempt = 0; $attempt < self::CLAIM_ATTEMPTS; ++$attempt) {
            $offset = $attempt;
            $candidate = $this->db->selectOne(
                sprintf(
                    'SELECT `id` FROM `%s` WHERE %s ORDER BY `available_at` ASC, `id` ASC LIMIT %d, 1',
                    $table,
                    $filters,
                    $offset,
                ),
                $bindings,
            );

            if ($candidate === null) {
                return null;
            }

            $id = (int) $candidate['id'];
            $claimed = $this->db->update(
                sprintf(
                    'UPDATE `%s` SET `reserved_at` = ?, `attempts` = `attempts` + 1 WHERE `id` = ? AND `reserved_at` IS NULL',
                    $table,
                ),
                [self::stamp($now), $id],
            );

            if ($claimed !== 1) {
                // Another worker won the race: look at the next candidate.
                continue;
            }

            $row = $this->db->selectOne(sprintf('SELECT * FROM `%s` WHERE `id` = ? LIMIT 1', $table), [$id]);

            if ($row !== null) {
                $job = Job::fromRow($row);

                return $job;
            }

            return null;
        }

        return null;
    }

    /**
     * Drop a successfully handled job. Finished jobs are deleted rather than kept: failures are the
     * only state an operator needs, and they stay in `pc_jobs` with `failed_at` set.
     */
    public function complete(int $jobId): void
    {
        $this->db->delete(sprintf('DELETE FROM `%s` WHERE `id` = ?', $this->table()), [$jobId]);
    }

    /**
     * Give a job back to the queue — the retry path. `$delaySeconds` is the backoff.
     */
    public function release(int $jobId, string $error, int $delaySeconds, ?int $now = null): void
    {
        $now ??= time();

        $this->db->update(
            sprintf(
                'UPDATE `%s` SET `reserved_at` = NULL, `available_at` = ?, `last_error` = ? WHERE `id` = ?',
                $this->table(),
            ),
            [self::stamp($now + max(0, $delaySeconds)), self::clip($error), $jobId],
        );
    }

    /**
     * Mark a job as permanently failed (its attempts are exhausted).
     */
    public function markFailed(int $jobId, string $error = '', ?int $now = null): void
    {
        $now ??= time();

        $this->db->update(
            sprintf(
                'UPDATE `%s` SET `reserved_at` = NULL, `failed_at` = ?, `last_error` = ? WHERE `id` = ?',
                $this->table(),
            ),
            [self::stamp($now), self::clip($error) === '' ? null : self::clip($error), $jobId],
        );
    }

    /**
     * Free jobs whose worker died mid-flight. Reservations older than `$olderThanSeconds` are handed
     * back so the next worker can pick them up.
     */
    public function releaseStuck(int $olderThanSeconds = 600, ?int $now = null): int
    {
        $now ??= time();
        $cutoff = self::stamp(max(0, $now - max(1, $olderThanSeconds)));

        return $this->db->update(
            sprintf(
                'UPDATE `%s` SET `reserved_at` = NULL, `last_error` = ? WHERE `reserved_at` IS NOT NULL AND `reserved_at` < ?',
                $this->table(),
            ),
            ['Réservation expirée (worker interrompu).', $cutoff],
        );
    }

    /**
     * Delete failed jobs older than `$minutes` — the TTL behind `scheduler.queue_ttl_minutes`.
     */
    public function pruneFailed(int $minutes, ?int $now = null): int
    {
        $now ??= time();
        $cutoff = self::stamp(max(0, $now - max(1, $minutes) * 60));

        return $this->db->delete(
            sprintf('DELETE FROM `%s` WHERE `failed_at` IS NOT NULL AND `failed_at` < ?', $this->table()),
            [$cutoff],
        );
    }

    /**
     * Put one failed job back into the queue, attempts counter reset.
     */
    public function retry(int $jobId, int $delaySeconds = 0, ?int $now = null): bool
    {
        $now ??= time();

        return $this->db->update(
            sprintf(
                'UPDATE `%s` SET `failed_at` = NULL, `reserved_at` = NULL, `attempts` = 0, `available_at` = ?,'
                . ' `last_error` = NULL WHERE `id` = ? AND `failed_at` IS NOT NULL',
                $this->table(),
            ),
            [self::stamp($now + max(0, $delaySeconds)), $jobId],
        ) === 1;
    }

    /**
     * Retry every failed job.
     */
    public function retryAll(int $delaySeconds = 0, ?int $now = null): int
    {
        $now ??= time();

        return $this->db->update(
            sprintf(
                'UPDATE `%s` SET `failed_at` = NULL, `reserved_at` = NULL, `attempts` = 0, `available_at` = ?,'
                . ' `last_error` = NULL WHERE `failed_at` IS NOT NULL',
                $this->table(),
            ),
            [self::stamp($now + max(0, $delaySeconds))],
        );
    }

    /**
     * Forget pending and reserved jobs (optionally on one queue). Failed jobs are never touched —
     * they are the audit trail.
     */
    public function purge(?string $queue = null): int
    {
        if ($queue === null || $queue === '' || $queue === '*') {
            return $this->db->delete(sprintf('DELETE FROM `%s` WHERE `failed_at` IS NULL', $this->table()));
        }

        return $this->db->delete(
            sprintf('DELETE FROM `%s` WHERE `failed_at` IS NULL AND `queue` = ?', $this->table()),
            [$queue],
        );
    }

    /**
     * @return array{pending: int, reserved: int, failed: int, delayed: int, oldest_pending: ?string}
     */
    public function stats(?int $now = null): array
    {
        $now ??= time();
        $stamp = self::stamp($now);
        $table = $this->table();

        $row = $this->db->selectOne(
            sprintf(
                'SELECT SUM(CASE WHEN `failed_at` IS NULL AND `reserved_at` IS NULL AND `available_at` <= ? THEN 1 ELSE 0 END) AS pending,'
                . ' SUM(CASE WHEN `reserved_at` IS NOT NULL AND `failed_at` IS NULL THEN 1 ELSE 0 END) AS reserved,'
                . ' SUM(CASE WHEN `failed_at` IS NOT NULL THEN 1 ELSE 0 END) AS failed,'
                . ' SUM(CASE WHEN `failed_at` IS NULL AND `available_at` > ? THEN 1 ELSE 0 END) AS delayed,'
                . ' MIN(CASE WHEN `failed_at` IS NULL AND `reserved_at` IS NULL THEN `available_at` ELSE NULL END) AS oldest'
                . ' FROM `%s`',
                $table,
            ),
            [$stamp, $stamp],
        ) ?? [];

        return [
            'pending' => (int) ($row['pending'] ?? 0),
            'reserved' => (int) ($row['reserved'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'delayed' => (int) ($row['delayed'] ?? 0),
            'oldest_pending' => is_string($row['oldest'] ?? null) && $row['oldest'] !== '' ? (string) $row['oldest'] : null,
        ];
    }

    /**
     * Most recent failures, newest first.
     *
     * @return list<Job>
     */
    public function failed(int $limit = 20): array
    {
        $rows = $this->db->select(
            sprintf(
                'SELECT * FROM `%s` WHERE `failed_at` IS NOT NULL ORDER BY `failed_at` DESC, `id` DESC LIMIT %d',
                $this->table(),
                max(1, $limit),
            ),
        );

        return array_map(static fn (array $row): Job => Job::fromRow($row), $rows);
    }

    /**
     * Distinct queues that currently hold work.
     *
     * @return list<string>
     */
    public function queues(): array
    {
        $rows = $this->db->select(sprintf('SELECT DISTINCT `queue` FROM `%s` ORDER BY `queue` ASC', $this->table()));

        return array_map(static fn (array $row): string => (string) $row['queue'], $rows);
    }

    /**
     * How many attempts are still ahead for `$handler` — queued, delayed or being run.
     *
     * Recurring work (publishing scheduled content, pruning) is dispatched from request paths, where
     * ten page views in a minute must not become ten identical jobs; callers push only when this
     * returns `0` and let the queued attempt cover the whole burst.
     */
    public function queuedCount(string $handler): int
    {
        $row = $this->db->selectOne(
            sprintf(
                'SELECT COUNT(*) AS total FROM `%s` WHERE `handler` = ? AND `failed_at` IS NULL',
                $this->table(),
            ),
            [$handler],
        ) ?? [];

        return (int) ($row['total'] ?? 0);
    }

    private function table(): string
    {
        return $this->db->prefix() . 'jobs';
    }

    private static function stamp(int $unix): string
    {
        return (new DateTimeImmutable('@' . $unix))->setTimezone(new DateTimeZone('UTC'))->format(Job::FORMAT);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '{}';
        }
    }

    /**
     * `last_error` is a TEXT column: keep the message readable and bounded.
     */
    private static function clip(string $error): string
    {
        $error = trim($error);

        return mb_strlen($error) > 900 ? mb_substr($error, 0, 897) . '…' : $error;
    }
}
