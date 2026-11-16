<?php

declare(strict_types=1);

namespace Prime\Content;

use DateTimeImmutable;
use DateTimeZone;
use Prime\Database\Connection;

/**
 * Low-level database repository for `pc_entries`, `pc_entry_meta`, and `pc_entry_terms`.
 *
 * All write business rules (validation, permissions, slugs, revisions, hooks) live in `EntryService`.
 */
final class EntryRepository
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function find(int $id, bool $withRelations = true): ?Entry
    {
        $row = $this->db->table('entries')->find($id);
        if ($row === null) {
            return null;
        }

        $entry = Entry::fromRow($row);
        if (!$withRelations) {
            return $entry;
        }

        return $this->hydrateEntries([$entry])[0];
    }

    public function findBySlug(string $type, string $slug, int $parentId = 0, bool $withRelations = true): ?Entry
    {
        $row = $this->db->table('entries')
            ->where('type', '=', $type)
            ->where('parent_id', '=', $parentId)
            ->where('slug', '=', $slug)
            ->first();

        if ($row === null) {
            return null;
        }

        $entry = Entry::fromRow($row);

        return $withRelations ? $this->hydrateEntries([$entry])[0] : $entry;
    }

    /**
     * Insert a new entry row and return its auto-increment ID.
     *
     * @param array<string, mixed> $columns
     */
    public function insert(array $columns): int
    {
        return $this->db->table('entries')->insertGetId($columns);
    }

    /**
     * Update an existing entry row.
     *
     * @param array<string, mixed> $columns
     */
    public function updateRow(int $id, array $columns): int
    {
        return $this->db->table('entries')->where('id', '=', $id)->update($columns);
    }

    /**
     * Permanently delete an entry (cascading meta, revisions, terms links, locks).
     */
    public function deletePermanent(int $id): int
    {
        return $this->db->transaction(function () use ($id): int {
            // Re-parent any child pages cleanly to root (0)
            $this->db->table('entries')->where('parent_id', '=', $id)->update(['parent_id' => 0]);

            // Explicit deletes complement ON DELETE CASCADE (needed for SQLite unit tests too)
            $this->db->table('entry_meta')->where('entry_id', '=', $id)->delete();
            $this->db->table('entry_terms')->where('entry_id', '=', $id)->delete();
            $this->db->table('revisions')->where('entry_id', '=', $id)->delete();
            $this->db->table('entry_locks')->where('entry_id', '=', $id)->delete();

            return $this->db->table('entries')->where('id', '=', $id)->delete();
        });
    }

    /**
     * Permanently delete all entries of a given content type (used by plugin `uninstall()`).
     */
    public function deleteByType(string $type): int
    {
        $rows = $this->db->table('entries')->select(['id'])->where('type', '=', $type)->get();
        if ($rows === []) {
            return 0;
        }

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);

        return $this->db->transaction(function () use ($ids, $type): int {
            $this->db->table('entry_meta')->whereIn('entry_id', $ids)->delete();
            $this->db->table('entry_terms')->whereIn('entry_id', $ids)->delete();
            $this->db->table('revisions')->whereIn('entry_id', $ids)->delete();
            $this->db->table('entry_locks')->whereIn('entry_id', $ids)->delete();

            return $this->db->table('entries')->where('type', '=', $type)->delete();
        });
    }

    public function slugExists(string $type, int $parentId, string $slug, ?int $ignoreId = null): bool
    {
        $qb = $this->db->table('entries')
            ->where('type', '=', $type)
            ->where('parent_id', '=', $parentId)
            ->where('slug', '=', $slug);

        if ($ignoreId !== null) {
            $qb->where('id', '!=', $ignoreId);
        }

        return $qb->exists();
    }

    /**
     * Read all metadata for a single entry.
     *
     * @return array<string, mixed>
     */
    public function getMeta(int $entryId): array
    {
        return $this->batchMeta([$entryId])[$entryId] ?? [];
    }

    /**
     * Upsert metadata key/value pairs for an entry (removes keys set to null).
     *
     * @param array<string, mixed> $values
     */
    public function setMeta(int $entryId, array $values): void
    {
        foreach ($values as $key => $value) {
            if ($value === null) {
                $this->deleteMeta($entryId, $key);
                continue;
            }

            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) {
                continue;
            }

            $exists = $this->db->table('entry_meta')
                ->where('entry_id', '=', $entryId)
                ->where('meta_key', '=', $key)
                ->exists();

            if ($exists) {
                $this->db->table('entry_meta')
                    ->where('entry_id', '=', $entryId)
                    ->where('meta_key', '=', $key)
                    ->update(['meta_value' => $encoded]);
            } else {
                $this->db->table('entry_meta')->insert([
                    'entry_id' => $entryId,
                    'meta_key' => $key,
                    'meta_value' => $encoded,
                ]);
            }
        }
    }

    public function deleteMeta(int $entryId, string $key): void
    {
        $this->db->table('entry_meta')
            ->where('entry_id', '=', $entryId)
            ->where('meta_key', '=', $key)
            ->delete();
    }

    /**
     * Return all terms attached to a single entry grouped by taxonomy.
     *
     * @return array<string, list<Term>>
     */
    public function getTerms(int $entryId): array
    {
        return $this->batchTerms([$entryId])[$entryId] ?? [];
    }

    /**
     * Replace all term associations for `$entryId` and return the union of previously
     * attached and newly attached term IDs so callers can recount them in one call.
     *
     * @param list<int> $termIds
     * @return list<int> Affected term IDs
     */
    public function syncTerms(int $entryId, array $termIds): array
    {
        $previous = array_map(
            static fn (array $r): int => (int) $r['term_id'],
            $this->db->table('entry_terms')->select(['term_id'])->where('entry_id', '=', $entryId)->get(),
        );

        $clean = array_values(array_unique(array_filter($termIds, static fn (int $id): bool => $id > 0)));

        $this->db->table('entry_terms')->where('entry_id', '=', $entryId)->delete();
        foreach ($clean as $pos => $termId) {
            $this->db->table('entry_terms')->insert([
                'entry_id' => $entryId,
                'term_id' => $termId,
                'position' => $pos,
            ]);
        }

        return array_values(array_unique([...$previous, ...$clean]));
    }

    /**
     * Eagerly hydrate meta, taxonomy terms, and authors for a batch of entries in
     * strictly constant query count (max 3 queries regardless of batch size).
     *
     * @param list<Entry> $entries
     * @return list<Entry>
     */
    public function hydrateEntries(
        array $entries,
        bool $loadMeta = true,
        bool $loadTerms = true,
        bool $loadAuthors = true,
    ): array {
        if ($entries === []) {
            return [];
        }

        $entryIds = array_map(static fn (Entry $e): int => $e->id, $entries);
        $metaMap = $loadMeta ? $this->batchMeta($entryIds) : [];
        $termsMap = $loadTerms ? $this->batchTerms($entryIds) : [];

        $authorMap = [];
        if ($loadAuthors) {
            $authorIds = [];
            foreach ($entries as $entry) {
                if ($entry->authorId !== null) {
                    $authorIds[] = $entry->authorId;
                }
            }
            $authorMap = $this->batchAuthors(array_values(array_unique($authorIds)));
        }

        $hydrated = [];
        foreach ($entries as $entry) {
            $author = $entry->authorId !== null ? ($authorMap[$entry->authorId] ?? null) : null;
            $hydrated[] = $entry->withRelations(
                meta: $metaMap[$entry->id] ?? $entry->meta,
                terms: $termsMap[$entry->id] ?? $entry->terms,
                authorName: $author !== null ? $author['display_name'] : $entry->authorName,
                authorEmail: $author !== null ? $author['email'] : $entry->authorEmail,
            );
        }

        return $hydrated;
    }

    /**
     * Count entries by status for a given content type (in a single GROUP BY query).
     * When `$visibleToAuthorId` is provided (contributors/authors without `edit_any`),
     * counts only entries owned by that author plus published entries.
     *
     * @return array<string, int>
     */
    public function countByStatus(string $type, ?int $visibleToAuthorId = null): array
    {
        $table = $this->db->prefix() . 'entries';
        if ($visibleToAuthorId !== null) {
            $rows = $this->db->select(
                sprintf('SELECT status, COUNT(*) AS cnt FROM `%s` WHERE type = ? AND (author_id = ? OR status = ?) GROUP BY status', $table),
                [$type, $visibleToAuthorId, Entry::STATUS_PUBLISHED],
            );
        } else {
            $rows = $this->db->select(
                sprintf('SELECT status, COUNT(*) AS cnt FROM `%s` WHERE type = ? GROUP BY status', $table),
                [$type],
            );
        }

        $counts = [
            'all' => 0,
            Entry::STATUS_PUBLISHED => 0,
            Entry::STATUS_DRAFT => 0,
            Entry::STATUS_PENDING => 0,
            Entry::STATUS_SCHEDULED => 0,
            Entry::STATUS_PRIVATE => 0,
            Entry::STATUS_TRASH => 0,
        ];

        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $cnt = (int) $row['cnt'];
            if (array_key_exists($status, $counts)) {
                $counts[$status] = $cnt;
            }
            if ($status !== Entry::STATUS_TRASH) {
                $counts['all'] += $cnt;
            }
        }

        return $counts;
    }

    /**
     * Return distinct YYYY-MM archive months present for a content type.
     *
     * @return list<string>
     */
    public function availableMonths(string $type, ?int $visibleToAuthorId = null): array
    {
        $table = $this->db->prefix() . 'entries';
        if ($visibleToAuthorId !== null) {
            $rows = $this->db->select(
                sprintf('SELECT published_at, created_at FROM `%s` WHERE type = ? AND (author_id = ? OR status = ?) ORDER BY created_at DESC', $table),
                [$type, $visibleToAuthorId, Entry::STATUS_PUBLISHED],
            );
        } else {
            $rows = $this->db->table('entries')
                ->select(['published_at', 'created_at'])
                ->where('type', '=', $type)
                ->orderBy('created_at', 'DESC')
                ->get();
        }

        $months = [];
        foreach ($rows as $row) {
            $date = (string) ($row['published_at'] ?: $row['created_at']);
            if (strlen($date) >= 7) {
                $ym = substr($date, 0, 7);
                $months[$ym] = true;
            }
        }

        $keys = array_keys($months);
        rsort($keys);

        return $keys;
    }

    /**
     * Return all non-trashed entries of a hierarchical type ordered for parent selector dropdowns.
     *
     * @return list<array{entry: Entry, depth: int}>
     */
    public function hierarchicalTree(string $type, ?int $excludeId = null): array
    {
        $rows = $this->db->table('entries')
            ->where('type', '=', $type)
            ->where('status', '!=', Entry::STATUS_TRASH)
            ->orderBy('menu_order', 'ASC')
            ->orderBy('title', 'ASC')
            ->get();

        $entries = array_map(static fn (array $row): Entry => Entry::fromRow($row), $rows);
        $childrenMap = [];
        foreach ($entries as $entry) {
            $childrenMap[$entry->parentId][] = $entry;
        }

        $result = [];
        $visited = [];

        $walk = static function (int $parentId, int $depth) use (&$walk, &$result, &$visited, $childrenMap, $excludeId): void {
            foreach ($childrenMap[$parentId] ?? [] as $child) {
                if (isset($visited[$child->id]) || ($excludeId !== null && $child->id === $excludeId)) {
                    continue;
                }
                $visited[$child->id] = true;
                $result[] = ['entry' => $child, 'depth' => $depth];
                $walk($child->id, $depth + 1);
            }
        };

        $walk(0, 0);

        return $result;
    }

    /**
     * Return all entry IDs (optionally restricted to one content type) for bulk maintenance commands.
     *
     * @return list<int>
     */
    public function allIds(?string $type = null): array
    {
        $qb = $this->db->table('entries')->select(['id'])->orderBy('id', 'ASC');
        if ($type !== null && $type !== '') {
            $qb->where('type', '=', $type);
        }

        return array_map(static fn (array $r): int => (int) $r['id'], $qb->get());
    }

    /**
     * Return most recently updated non-trashed entries across all types (for Dashboard activity).
     *
     * @return list<Entry>
     */
    public function recentActivity(int $limit = 6): array
    {
        $rows = $this->db->table('entries')
            ->where('status', '!=', Entry::STATUS_TRASH)
            ->orderBy('updated_at', 'DESC')
            ->limit(max(1, $limit))
            ->get();

        $entries = array_map(static fn (array $row): Entry => Entry::fromRow($row), $rows);

        return $this->hydrateEntries($entries, loadMeta: false, loadTerms: false, loadAuthors: true);
    }

    /**
     * Return all scheduled entries whose `published_at` has arrived (`<= $nowUtc`).
     *
     * @return list<Entry>
     */
    public function dueScheduled(?string $nowUtc = null): array
    {
        $now = $nowUtc ?? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $rows = $this->db->table('entries')
            ->where('status', '=', Entry::STATUS_SCHEDULED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $now)
            ->get();

        $entries = array_map(static fn (array $row): Entry => Entry::fromRow($row), $rows);

        return $this->hydrateEntries($entries);
    }

    /**
     * Return trashed entries whose `trashed_at` is older than `$cutoffUtc`.
     *
     * @return list<Entry>
     */
    public function expiredTrashed(string $cutoffUtc): array
    {
        $rows = $this->db->table('entries')
            ->where('status', '=', Entry::STATUS_TRASH)
            ->whereNotNull('trashed_at')
            ->where('trashed_at', '<=', $cutoffUtc)
            ->get();

        return array_map(static fn (array $row): Entry => Entry::fromRow($row), $rows);
    }

    /**
     * @param list<int> $entryIds
     * @return array<int, array<string, mixed>>
     */
    private function batchMeta(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        $rows = $this->db->table('entry_meta')
            ->whereIn('entry_id', $entryIds)
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $eid = (int) $row['entry_id'];
            $key = (string) $row['meta_key'];
            $raw = $row['meta_value'];
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            $map[$eid][$key] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $raw;
        }

        return $map;
    }

    /**
     * @param list<int> $entryIds
     * @return array<int, array<string, list<Term>>>
     */
    private function batchTerms(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        $etTable = $this->db->prefix() . 'entry_terms';
        $tTable = $this->db->prefix() . 'terms';
        $placeholders = implode(', ', array_fill(0, count($entryIds), '?'));

        $rows = $this->db->select(
            sprintf(
                'SELECT et.entry_id, t.* '
                . 'FROM `%s` et '
                . 'INNER JOIN `%s` t ON t.id = et.term_id '
                . 'WHERE et.entry_id IN (%s) '
                . 'ORDER BY et.position ASC, t.name ASC',
                $etTable,
                $tTable,
                $placeholders,
            ),
            $entryIds,
        );

        $map = [];
        foreach ($rows as $row) {
            $eid = (int) $row['entry_id'];
            $term = Term::fromRow($row);
            $map[$eid][$term->taxonomy][] = $term;
        }

        return $map;
    }

    /**
     * @param list<int> $authorIds
     * @return array<int, array{display_name: string, email: string}>
     */
    private function batchAuthors(array $authorIds): array
    {
        if ($authorIds === []) {
            return [];
        }

        $rows = $this->db->table('users')
            ->select(['id', 'display_name', 'email'])
            ->whereIn('id', $authorIds)
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = [
                'display_name' => (string) $row['display_name'],
                'email' => (string) $row['email'],
            ];
        }

        return $map;
    }
}
