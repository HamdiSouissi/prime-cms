<?php

declare(strict_types=1);

namespace Prime\Content;

use DateTimeImmutable;
use DateTimeZone;
use Prime\Database\Connection;

/**
 * Manages concurrent editorial locks (`pc_entry_locks`) with heartbeat expiry (2 min default).
 */
final class EntryLockRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly int $ttlSeconds = 120,
    ) {
    }

    /**
     * Return the active lock for `$entryId`, or null if unlocked or expired.
     */
    public function current(int $entryId): ?EntryLock
    {
        $cutoff = $this->cutoffUtc();
        $locksTable = $this->db->prefix() . 'entry_locks';
        $usersTable = $this->db->prefix() . 'users';

        $rows = $this->db->select(
            sprintf(
                'SELECT l.entry_id, l.user_id, l.acquired_at, l.heartbeat_at, '
                . 'u.display_name AS user_name, u.email AS user_email '
                . 'FROM `%s` l LEFT JOIN `%s` u ON u.id = l.user_id '
                . 'WHERE l.entry_id = ? LIMIT 1',
                $locksTable,
                $usersTable,
            ),
            [$entryId],
        );

        if ($rows === []) {
            return null;
        }

        $lock = EntryLock::fromRow($rows[0]);
        if ($lock->heartbeatAt < $cutoff) {
            $this->db->table('entry_locks')->where('entry_id', '=', $entryId)->delete();

            return null;
        }

        return $lock;
    }

    /**
     * Acquire or refresh a lock for `$userId`.
     *
     * Returns `['acquired' => true, 'lock' => $lock]` if granted, or
     * `['acquired' => false, 'lock' => $otherLock]` if held by another user and `$force` is false.
     *
     * @return array{acquired: bool, lock: EntryLock}
     */
    public function acquire(int $entryId, int $userId, bool $force = false): array
    {
        $existing = $this->current($entryId);

        if ($existing !== null && $existing->userId !== $userId && !$force) {
            return ['acquired' => false, 'lock' => $existing];
        }

        $now = $this->nowUtc();
        if ($existing !== null) {
            $acquiredAt = $existing->userId === $userId ? $existing->acquiredAt : $now;
            $this->db->table('entry_locks')
                ->where('entry_id', '=', $entryId)
                ->update([
                    'user_id' => $userId,
                    'acquired_at' => $acquiredAt,
                    'heartbeat_at' => $now,
                ]);
        } else {
            $this->db->table('entry_locks')->insert([
                'entry_id' => $entryId,
                'user_id' => $userId,
                'acquired_at' => $now,
                'heartbeat_at' => $now,
            ]);
        }

        $fresh = $this->current($entryId);

        return [
            'acquired' => true,
            'lock' => $fresh ?? new EntryLock($entryId, $userId, $now, $now),
        ];
    }

    /**
     * Refresh heartbeat if `$userId` still holds the lock, or signal `'lost'` if displaced.
     *
     * @return array{status: 'ok'|'lost', lock: ?EntryLock}
     */
    public function heartbeat(int $entryId, int $userId): array
    {
        $existing = $this->current($entryId);

        if ($existing !== null && $existing->userId !== $userId) {
            return ['status' => 'lost', 'lock' => $existing];
        }

        $result = $this->acquire($entryId, $userId, false);

        return ['status' => 'ok', 'lock' => $result['lock']];
    }

    /**
     * Release the lock if held by `$userId` (or unconditionally when `$userId` is null).
     */
    public function release(int $entryId, ?int $userId = null): void
    {
        $qb = $this->db->table('entry_locks')->where('entry_id', '=', $entryId);
        if ($userId !== null) {
            $qb->where('user_id', '=', $userId);
        }
        $qb->delete();
    }

    /**
     * Check whether `$entryId` is actively locked by someone other than `$userId`.
     */
    public function lockedByOther(int $entryId, int $userId): ?EntryLock
    {
        $lock = $this->current($entryId);

        return ($lock !== null && $lock->userId !== $userId) ? $lock : null;
    }

    /**
     * Batch-load active locks for a list of entry IDs in 1 single SQL query.
     *
     * @param list<int> $entryIds
     * @return array<int, EntryLock>
     */
    public function activeForEntries(array $entryIds): array
    {
        $ids = array_values(array_unique(array_filter($entryIds, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $cutoff = $this->cutoffUtc();
        $locksTable = $this->db->prefix() . 'entry_locks';
        $usersTable = $this->db->prefix() . 'users';
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        $rows = $this->db->select(
            sprintf(
                'SELECT l.entry_id, l.user_id, l.acquired_at, l.heartbeat_at, '
                . 'u.display_name AS user_name, u.email AS user_email '
                . 'FROM `%s` l LEFT JOIN `%s` u ON u.id = l.user_id '
                . 'WHERE l.entry_id IN (%s) AND l.heartbeat_at >= ?',
                $locksTable,
                $usersTable,
                $placeholders,
            ),
            [...$ids, $cutoff],
        );

        $map = [];
        foreach ($rows as $row) {
            $lock = EntryLock::fromRow($row);
            $map[$lock->entryId] = $lock;
        }

        return $map;
    }

    /**
     * Purge all expired locks globally.
     */
    public function purgeExpired(): int
    {
        return $this->db->table('entry_locks')
            ->where('heartbeat_at', '<', $this->cutoffUtc())
            ->delete();
    }

    private function nowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function cutoffUtc(): string
    {
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify(sprintf('-%d seconds', max(10, $this->ttlSeconds)));

        return $cutoff->format('Y-m-d H:i:s');
    }
}
