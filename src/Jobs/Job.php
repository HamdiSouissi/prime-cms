<?php

declare(strict_types=1);

namespace Prime\Jobs;

use JsonException;

/**
 * One queued unit of work as stored in `pc_jobs`.
 *
 * Timestamps are UTC strings in `Job::FORMAT`, the same representation the column uses, so the
 * comparison `available_at <= ?` stays index-friendly on MySQL and MariaDB alike.
 */
final class Job
{
    public const FORMAT = 'Y-m-d H:i:s';

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly int $id,
        public readonly string $queue,
        public readonly string $handler,
        public readonly array $payload = [],
        public readonly int $attempts = 0,
        public readonly int $maxAttempts = 3,
        public readonly string $availableAt = '',
        public readonly ?string $reservedAt = null,
        public readonly ?string $failedAt = null,
        public readonly ?string $lastError = null,
        public readonly string $createdAt = '',
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $payload = $row['payload'] ?? '{}';
        $decoded = null;

        if (is_array($payload)) {
            $decoded = $payload;
        } elseif (is_string($payload) && $payload !== '') {
            try {
                $candidate = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
                $decoded = is_array($candidate) ? $candidate : [];
            } catch (JsonException) {
                $decoded = [];
            }
        }

        return new self(
            id: (int) ($row['id'] ?? 0),
            queue: (string) ($row['queue'] ?? 'default'),
            handler: (string) ($row['handler'] ?? ''),
            payload: $decoded ?? [],
            attempts: (int) ($row['attempts'] ?? 0),
            maxAttempts: max(1, (int) ($row['max_attempts'] ?? 3)),
            availableAt: (string) ($row['available_at'] ?? ''),
            reservedAt: isset($row['reserved_at']) ? (string) $row['reserved_at'] : null,
            failedAt: isset($row['failed_at']) ? (string) $row['failed_at'] : null,
            lastError: isset($row['last_error']) ? (string) $row['last_error'] : null,
            createdAt: (string) ($row['created_at'] ?? ''),
        );
    }

    public function isReserved(): bool
    {
        return $this->reservedAt !== null;
    }

    public function hasFailed(): bool
    {
        return $this->failedAt !== null;
    }

    /**
     * Whether this was the last attempt the job is allowed to make.
     */
    public function exhausted(): bool
    {
        return $this->attempts >= $this->maxAttempts;
    }
}
