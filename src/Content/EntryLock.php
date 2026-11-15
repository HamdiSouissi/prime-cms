<?php

declare(strict_types=1);

namespace Prime\Content;

/**
 * Immutable active editorial lock record from `pc_entry_locks`.
 */
final readonly class EntryLock
{
    public function __construct(
        public int $entryId,
        public int $userId,
        public string $acquiredAt,
        public string $heartbeatAt,
        public string $userName = '',
        public string $userEmail = '',
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            entryId: (int) ($row['entry_id'] ?? 0),
            userId: (int) ($row['user_id'] ?? 0),
            acquiredAt: (string) ($row['acquired_at'] ?? ''),
            heartbeatAt: (string) ($row['heartbeat_at'] ?? ''),
            userName: (string) ($row['user_name'] ?? $row['display_name'] ?? ''),
            userEmail: (string) ($row['user_email'] ?? $row['email'] ?? ''),
        );
    }
}
