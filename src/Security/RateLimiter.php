<?php

declare(strict_types=1);

namespace Prime\Security;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Prime\Database\Connection;

/**
 * Database-backed rate limiter (`pc_rate_limits`) with SHA-256 hashed keys
 * and an injectable clock for deterministic unit tests.
 */
final class RateLimiter
{
    /**
     * @var Closure(): int
     */
    private readonly Closure $clock;

    /**
     * @param (Closure(): int)|null $clock Unix timestamp supplier (defaults to `time()`)
     */
    public function __construct(
        private readonly Connection $connection,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Record a hit for the given logical key and return the updated hit count.
     */
    public function hit(string $key, int $decaySeconds = 60): int
    {
        $hashed = $this->hashKey($key);
        $now = $this->now();
        $resetTimestamp = $now + max(1, $decaySeconds);
        $resetAt = $this->formatUtc($resetTimestamp);

        $existing = $this->connection->table('rate_limits')
            ->where('key', '=', $hashed)
            ->first();

        if ($existing === null) {
            $this->connection->table('rate_limits')->insert([
                'key' => $hashed,
                'hits' => 1,
                'reset_at' => $resetAt,
            ]);

            return 1;
        }

        $existingReset = $this->parseUtc((string) $existing['reset_at']);
        if ($existingReset <= $now) {
            $this->connection->table('rate_limits')
                ->where('key', '=', $hashed)
                ->update([
                    'hits' => 1,
                    'reset_at' => $resetAt,
                ]);

            return 1;
        }

        $hits = ((int) $existing['hits']) + 1;
        $this->connection->table('rate_limits')
            ->where('key', '=', $hashed)
            ->update([
                'hits' => $hits,
            ]);

        return $hits;
    }

    /**
     * Return true when the active hit count has reached or exceeded `$maxAttempts`.
     */
    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return $this->attempts($key) >= $maxAttempts;
    }

    /**
     * Return the active hit count for a key (0 if absent or window expired).
     */
    public function attempts(string $key): int
    {
        $row = $this->activeRow($key);

        return $row === null ? 0 : (int) $row['hits'];
    }

    /**
     * Return seconds remaining until the current rate-limit window resets (0 if not limited).
     */
    public function availableIn(string $key): int
    {
        $row = $this->activeRow($key);
        if ($row === null) {
            return 0;
        }

        $resetAt = $this->parseUtc((string) $row['reset_at']);

        return max(0, $resetAt - $this->now());
    }

    /**
     * Remove any recorded hits for a logical key.
     */
    public function clear(string $key): void
    {
        $this->connection->table('rate_limits')
            ->where('key', '=', $this->hashKey($key))
            ->delete();
    }

    /**
     * Purge all expired rate-limit entries from the database.
     */
    public function purgeExpired(): int
    {
        return $this->connection->table('rate_limits')
            ->where('reset_at', '<=', $this->formatUtc($this->now()))
            ->delete();
    }

    /**
     * Hash a logical rate-limit key to a fixed 64-character SHA-256 hex digest.
     */
    public function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activeRow(string $key): ?array
    {
        $hashed = $this->hashKey($key);
        $row = $this->connection->table('rate_limits')
            ->where('key', '=', $hashed)
            ->first();

        if ($row === null) {
            return null;
        }

        if ($this->parseUtc((string) $row['reset_at']) <= $this->now()) {
            $this->connection->table('rate_limits')
                ->where('key', '=', $hashed)
                ->delete();

            return null;
        }

        return $row;
    }

    private function now(): int
    {
        return ($this->clock)();
    }

    private function formatUtc(int $timestamp): string
    {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    private function parseUtc(string $value): int
    {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if ($dt === false) {
            $fallback = strtotime($value . ' UTC');

            return $fallback === false ? 0 : $fallback;
        }

        return $dt->getTimestamp();
    }
}
