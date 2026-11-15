<?php

declare(strict_types=1);

namespace Prime\Content;

use DateTimeImmutable;
use DateTimeZone;
use Prime\Database\Connection;

/**
 * Persistence layer for entry revisions and per-user autosaves (`pc_revisions`).
 */
final class RevisionRepository
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function find(int $id): ?Revision
    {
        $row = $this->db->table('revisions')->find($id);
        if ($row === null) {
            return null;
        }

        $authorName = null;
        if (isset($row['author_id'])) {
            $userRow = $this->db->table('users')->select(['display_name'])->find((int) $row['author_id']);
            if ($userRow !== null) {
                $authorName = (string) $userRow['display_name'];
            }
        }

        return Revision::fromRow($row, $authorName);
    }

    /**
     * Record a new regular (non-autosave) revision and prune older ones beyond `$maxKeep`.
     *
     * @param array<string, mixed> $meta
     */
    public function create(
        int $entryId,
        ?int $authorId,
        string $title,
        ?string $excerpt,
        string $contentJson,
        array $meta,
        int $maxKeep = 25,
    ): ?Revision {
        if ($maxKeep <= 0) {
            return null;
        }

        $now = $this->nowUtc();
        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        $id = $this->db->table('revisions')->insertGetId([
            'entry_id' => $entryId,
            'author_id' => $authorId,
            'is_autosave' => 0,
            'title' => $title,
            'excerpt' => $excerpt,
            'content' => $contentJson,
            'meta' => $metaJson,
            'created_at' => $now,
        ]);

        $this->prune($entryId, $maxKeep);

        return new Revision(
            id: $id,
            entryId: $entryId,
            authorId: $authorId,
            isAutosave: false,
            title: $title,
            excerpt: $excerpt,
            content: $contentJson,
            meta: $meta,
            createdAt: $now,
        );
    }

    /**
     * Upsert the single autosave slot for `(entry_id, author_id)` (never accumulates rows).
     *
     * @param array<string, mixed> $meta
     */
    public function upsertAutosave(
        int $entryId,
        int $authorId,
        string $title,
        ?string $excerpt,
        string $contentJson,
        array $meta = [],
    ): Revision {
        $now = $this->nowUtc();
        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        $existing = $this->db->table('revisions')
            ->where('entry_id', '=', $entryId)
            ->where('author_id', '=', $authorId)
            ->where('is_autosave', '=', 1)
            ->first();

        if ($existing !== null) {
            $id = (int) $existing['id'];
            $this->db->table('revisions')
                ->where('id', '=', $id)
                ->update([
                    'title' => $title,
                    'excerpt' => $excerpt,
                    'content' => $contentJson,
                    'meta' => $metaJson,
                    'created_at' => $now,
                ]);
        } else {
            $id = $this->db->table('revisions')->insertGetId([
                'entry_id' => $entryId,
                'author_id' => $authorId,
                'is_autosave' => 1,
                'title' => $title,
                'excerpt' => $excerpt,
                'content' => $contentJson,
                'meta' => $metaJson,
                'created_at' => $now,
            ]);
        }

        return new Revision(
            id: $id,
            entryId: $entryId,
            authorId: $authorId,
            isAutosave: true,
            title: $title,
            excerpt: $excerpt,
            content: $contentJson,
            meta: $meta,
            createdAt: $now,
        );
    }

    public function latestAutosave(int $entryId, int $userId): ?Revision
    {
        $row = $this->db->table('revisions')
            ->where('entry_id', '=', $entryId)
            ->where('author_id', '=', $userId)
            ->where('is_autosave', '=', 1)
            ->orderBy('created_at', 'DESC')
            ->first();

        return $row !== null ? Revision::fromRow($row) : null;
    }

    public function clearAutosave(int $entryId, int $userId): void
    {
        $this->db->table('revisions')
            ->where('entry_id', '=', $entryId)
            ->where('author_id', '=', $userId)
            ->where('is_autosave', '=', 1)
            ->delete();
    }

    /**
     * List regular revisions for an entry, newest first, with author display names hydrated in 1 query.
     *
     * @return list<Revision>
     */
    public function forEntry(int $entryId, bool $includeAutosaves = false): array
    {
        $qb = $this->db->table('revisions')
            ->where('entry_id', '=', $entryId)
            ->orderBy('id', 'DESC');

        if (!$includeAutosaves) {
            $qb->where('is_autosave', '=', 0);
        }

        $rows = $qb->get();
        if ($rows === []) {
            return [];
        }

        $authorIds = [];
        foreach ($rows as $row) {
            if (isset($row['author_id'])) {
                $authorIds[] = (int) $row['author_id'];
            }
        }
        $authorIds = array_values(array_unique($authorIds));

        $names = [];
        if ($authorIds !== []) {
            $users = $this->db->table('users')
                ->select(['id', 'display_name'])
                ->whereIn('id', $authorIds)
                ->get();
            foreach ($users as $u) {
                $names[(int) $u['id']] = (string) $u['display_name'];
            }
        }

        return array_map(
            static function (array $row) use ($names): Revision {
                $aid = isset($row['author_id']) ? (int) $row['author_id'] : null;

                return Revision::fromRow($row, $aid !== null ? ($names[$aid] ?? null) : null);
            },
            $rows,
        );
    }

    /**
     * Prune oldest non-autosave revisions exceeding `$maxKeep`.
     */
    public function prune(int $entryId, int $maxKeep): int
    {
        if ($maxKeep < 0) {
            return 0;
        }

        $rows = $this->db->table('revisions')
            ->select(['id'])
            ->where('entry_id', '=', $entryId)
            ->where('is_autosave', '=', 0)
            ->orderBy('id', 'DESC')
            ->get();

        if (count($rows) <= $maxKeep) {
            return 0;
        }

        $toDelete = array_map(
            static fn (array $r): int => (int) $r['id'],
            array_slice($rows, $maxKeep),
        );

        return $this->db->table('revisions')->whereIn('id', $toDelete)->delete();
    }

    private function nowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
