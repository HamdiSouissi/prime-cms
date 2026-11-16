<?php

declare(strict_types=1);

namespace Prime\Media;

use DateTimeImmutable;
use DateTimeZone;
use Prime\Database\Connection;

/**
 * Data access layer for `pc_media` with search, filters, sorting, pagination,
 * duplicate checksum lookup, and aggregated disk statistics.
 */
final class MediaRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly ?MediaUsageRepository $usages = null,
    ) {
    }

    public function find(int $id): ?Media
    {
        $row = $this->db->table('media')
            ->where('id', '=', $id)
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->hydrateMany([$row])[0] ?? null;
    }

    /**
     * Batch-load media items by ID, keyed by `id`.
     *
     * @param list<int> $ids
     * @return array<int, Media>
     */
    public function findByIds(array $ids): array
    {
        $clean = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($clean === []) {
            return [];
        }

        $rows = $this->db->table('media')
            ->whereIn('id', $clean)
            ->get();

        $map = [];
        foreach ($this->hydrateMany($rows) as $media) {
            $map[$media->id] = $media;
        }

        return $map;
    }

    public function findByDiskPath(string $diskPath): ?Media
    {
        $row = $this->db->table('media')
            ->where('disk_path', '=', ltrim($diskPath, '/'))
            ->first();

        return $row !== null ? ($this->hydrateMany([$row])[0] ?? null) : null;
    }

    public function findFirstByChecksum(string $checksum, ?int $excludeId = null): ?Media
    {
        $query = $this->db->table('media')
            ->where('checksum', '=', $checksum)
            ->orderBy('id', 'ASC');

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        $row = $query->first();

        return $row !== null ? ($this->hydrateMany([$row])[0] ?? null) : null;
    }

    /**
     * Return all media rows (used by CLI `media:regenerate` and `media:cleanup`).
     *
     * @return list<Media>
     */
    public function all(?string $kind = null): array
    {
        $query = $this->db->table('media')->orderBy('id', 'ASC');
        if ($kind !== null && $kind !== '') {
            $query->where('kind', '=', $kind);
        }

        return $this->hydrateMany($query->get());
    }

    /**
     * Paginated, filtered, searched, and sorted media query for `/admin/media` and picker API.
     *
     * @param array{
     *   folder_id?: ?int,
     *   unclassified?: bool,
     *   kind?: string,
     *   kinds?: list<string>,
     *   month?: string,
     *   author_id?: ?int,
     *   unused?: bool,
     *   q?: string,
     *   sort?: string,
     *   dir?: string,
     *   page?: int,
     *   per_page?: int
     * } $filters
     * @return array{items: list<Media>, total: int, page: int, per_page: int, total_pages: int, has_more: bool}
     */
    public function paginate(array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 24)));

        $allowedSorts = [
            'created_at' => 'm.`created_at`',
            'date' => 'm.`created_at`',
            'name' => 'm.`original_name`',
            'title' => 'm.`title`',
            'size' => 'm.`size_bytes`',
            'size_bytes' => 'm.`size_bytes`',
        ];
        $sortKey = (string) ($filters['sort'] ?? 'created_at');
        $sortExpr = $allowedSorts[$sortKey] ?? 'm.`created_at`';
        $dir = strtoupper((string) ($filters['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $mediaTable = $this->db->prefixed('media');
        $usageTable = $this->db->prefixed('media_usage');
        $usersTable = $this->db->prefixed('users');

        $where = [];
        $bindings = [];

        if (!empty($filters['unclassified'])) {
            $where[] = 'm.`folder_id` IS NULL';
        } elseif (isset($filters['folder_id']) && $filters['folder_id'] > 0) {
            $where[] = 'm.`folder_id` = ?';
            $bindings[] = (int) $filters['folder_id'];
        }

        if (!empty($filters['kinds']) && is_array($filters['kinds'])) {
            $validKinds = array_values(array_intersect($filters['kinds'], Media::ALL_KINDS));
            if ($validKinds !== []) {
                $placeholders = implode(', ', array_fill(0, count($validKinds), '?'));
                $where[] = sprintf('m.`kind` IN (%s)', $placeholders);
                foreach ($validKinds as $k) {
                    $bindings[] = $k;
                }
            }
        } elseif (isset($filters['kind']) && $filters['kind'] !== '' && in_array($filters['kind'], Media::ALL_KINDS, true)) {
            $where[] = 'm.`kind` = ?';
            $bindings[] = $filters['kind'];
        }

        $month = isset($filters['month']) ? trim((string) $filters['month']) : '';
        if (preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            $start = $month . '-01 00:00:00';
            $nextMonth = (new DateTimeImmutable($month . '-01 00:00:00', new DateTimeZone('UTC')))
                ->modify('+1 month')
                ->format('Y-m-d H:i:s');
            $where[] = 'm.`created_at` >= ? AND m.`created_at` < ?';
            $bindings[] = $start;
            $bindings[] = $nextMonth;
        }

        if (isset($filters['author_id']) && $filters['author_id'] > 0) {
            $where[] = 'm.`author_id` = ?';
            $bindings[] = (int) $filters['author_id'];
        }

        if (!empty($filters['unused'])) {
            $where[] = sprintf(
                'NOT EXISTS (SELECT 1 FROM `%s` u WHERE u.`media_id` = m.`id`) AND NOT EXISTS (SELECT 1 FROM `%s` usr WHERE usr.`avatar_media_id` = m.`id`)',
                $usageTable,
                $usersTable,
            );
        }

        $search = isset($filters['q']) ? trim((string) $filters['q']) : '';
        if ($search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $like = '%' . $escaped . '%';
            $where[] = '(m.`original_name` LIKE ? OR m.`title` LIKE ? OR m.`alt` LIKE ? OR m.`caption` LIKE ? OR m.`filename` LIKE ?)';
            $bindings[] = $like;
            $bindings[] = $like;
            $bindings[] = $like;
            $bindings[] = $like;
            $bindings[] = $like;
        }

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        $countRow = $this->db->selectOne(
            sprintf('SELECT COUNT(*) AS `total` FROM `%s` m%s', $mediaTable, $whereSql),
            $bindings,
        );
        $total = (int) ($countRow['total'] ?? 0);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        $rows = $this->db->select(
            sprintf(
                'SELECT m.* FROM `%s` m%s ORDER BY %s %s, m.`id` DESC LIMIT %d OFFSET %d',
                $mediaTable,
                $whereSql,
                $sortExpr,
                $dir,
                $perPage,
                $offset,
            ),
            $bindings,
        );

        return [
            'items' => $this->hydrateMany($rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'has_more' => ($offset + count($rows)) < $total,
        ];
    }

    /**
     * Insert a new media record and return the hydrated Media object.
     *
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): Media
    {
        $now = $this->nowUtc();
        $variants = $attributes['variants'] ?? [];
        $variantsJson = is_string($variants)
            ? $variants
            : json_encode($variants, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $id = $this->db->table('media')->insertGetId([
            'folder_id' => $attributes['folder_id'] ?? null,
            'author_id' => $attributes['author_id'] ?? null,
            'disk_path' => (string) ($attributes['disk_path'] ?? ''),
            'filename' => (string) ($attributes['filename'] ?? ''),
            'original_name' => (string) ($attributes['original_name'] ?? ''),
            'extension' => strtolower((string) ($attributes['extension'] ?? '')),
            'mime_type' => (string) ($attributes['mime_type'] ?? 'application/octet-stream'),
            'kind' => (string) ($attributes['kind'] ?? Media::KIND_OTHER),
            'size_bytes' => max(0, (int) ($attributes['size_bytes'] ?? 0)),
            'width' => isset($attributes['width']) ? (int) $attributes['width'] : null,
            'height' => isset($attributes['height']) ? (int) $attributes['height'] : null,
            'checksum' => (string) ($attributes['checksum'] ?? ''),
            'alt' => (string) ($attributes['alt'] ?? ''),
            'title' => (string) ($attributes['title'] ?? ''),
            'caption' => isset($attributes['caption']) && $attributes['caption'] !== '' ? (string) $attributes['caption'] : null,
            'description' => isset($attributes['description']) && $attributes['description'] !== '' ? (string) $attributes['description'] : null,
            'focal_x' => number_format(max(0.0, min(1.0, (float) ($attributes['focal_x'] ?? 0.5))), 3, '.', ''),
            'focal_y' => number_format(max(0.0, min(1.0, (float) ($attributes['focal_y'] ?? 0.5))), 3, '.', ''),
            'variants' => is_string($variantsJson) ? $variantsJson : '{}',
            'created_at' => $attributes['created_at'] ?? $now,
            'updated_at' => $attributes['updated_at'] ?? $now,
        ]);

        $created = $this->find($id);
        if ($created === null) {
            throw new \RuntimeException('Impossible de charger le média créé.');
        }

        return $created;
    }

    /**
     * Update media metadata, folder, focal point, or file/variant attributes.
     *
     * @param array<string, mixed> $fields
     */
    public function update(int $id, array $fields): ?Media
    {
        if (array_key_exists('variants', $fields) && is_array($fields['variants'])) {
            $encoded = json_encode($fields['variants'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $fields['variants'] = is_string($encoded) ? $encoded : '{}';
        }
        if (array_key_exists('focal_x', $fields)) {
            $fields['focal_x'] = number_format(max(0.0, min(1.0, (float) $fields['focal_x'])), 3, '.', '');
        }
        if (array_key_exists('focal_y', $fields)) {
            $fields['focal_y'] = number_format(max(0.0, min(1.0, (float) $fields['focal_y'])), 3, '.', '');
        }
        $fields['updated_at'] = $this->nowUtc();

        $this->db->table('media')
            ->where('id', '=', $id)
            ->update($fields);

        return $this->find($id);
    }

    /**
     * Move multiple media items into a target folder (`null` for root / unclassified).
     *
     * @param list<int> $ids
     */
    public function moveMany(array $ids, ?int $folderId): int
    {
        $clean = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($clean === []) {
            return 0;
        }

        return $this->db->table('media')
            ->whereIn('id', $clean)
            ->update([
                'folder_id' => $folderId,
                'updated_at' => $this->nowUtc(),
            ]);
    }

    public function delete(int $id): bool
    {
        return $this->db->table('media')
            ->where('id', '=', $id)
            ->delete() > 0;
    }

    /**
     * Distinct `YYYY-MM` months present in `pc_media` (most recent first).
     *
     * @return list<string>
     */
    public function availableMonths(): array
    {
        $mediaTable = $this->db->prefixed('media');
        $rows = $this->db->select(
            sprintf('SELECT DISTINCT SUBSTR(`created_at`, 1, 7) AS `ym` FROM `%s` WHERE `created_at` IS NOT NULL ORDER BY `ym` DESC', $mediaTable),
        );

        $months = [];
        foreach ($rows as $row) {
            $ym = (string) ($row['ym'] ?? '');
            if (preg_match('/^\d{4}-\d{2}$/', $ym) === 1) {
                $months[] = $ym;
            }
        }

        return $months;
    }

    public function countAll(): int
    {
        return $this->db->table('media')->count();
    }

    public function countUnclassified(): int
    {
        return $this->db->table('media')
            ->whereNull('folder_id')
            ->count();
    }

    /**
     * Aggregated storage statistics for Dashboard tile and `media:stats` CLI.
     *
     * @return array{
     *   total_count: int,
     *   total_bytes: int,
     *   variants_bytes: int,
     *   unclassified_count: int,
     *   unused_count: int,
     *   by_kind: array<string, array{count: int, bytes: int}>
     * }
     */
    public function stats(): array
    {
        $mediaTable = $this->db->prefixed('media');
        $usageTable = $this->db->prefixed('media_usage');
        $usersTable = $this->db->prefixed('users');

        $rows = $this->db->select(
            sprintf('SELECT `kind`, COUNT(*) AS `cnt`, COALESCE(SUM(`size_bytes`), 0) AS `bytes` FROM `%s` GROUP BY `kind`', $mediaTable),
        );

        $totalCount = 0;
        $totalBytes = 0;
        $byKind = [];
        foreach ($rows as $row) {
            $k = (string) $row['kind'];
            $cnt = (int) $row['cnt'];
            $bytes = (int) $row['bytes'];
            $byKind[$k] = ['count' => $cnt, 'bytes' => $bytes];
            $totalCount += $cnt;
            $totalBytes += $bytes;
        }

        // Sum generated variant sizes recorded in JSON
        $variantBytes = 0;
        $varRows = $this->db->select(
            sprintf('SELECT `variants` FROM `%s` WHERE `variants` IS NOT NULL', $mediaTable),
        );
        foreach ($varRows as $vr) {
            $raw = $vr['variants'] ?? null;
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $vInfo) {
                if (is_array($vInfo) && isset($vInfo['size_bytes']) && is_numeric($vInfo['size_bytes'])) {
                    $variantBytes += (int) $vInfo['size_bytes'];
                }
            }
        }

        $unclassified = $this->countUnclassified();

        $unusedRow = $this->db->selectOne(
            sprintf(
                'SELECT COUNT(*) AS `cnt` FROM `%s` m
                 WHERE NOT EXISTS (SELECT 1 FROM `%s` u WHERE u.`media_id` = m.`id`)
                   AND NOT EXISTS (SELECT 1 FROM `%s` usr WHERE usr.`avatar_media_id` = m.`id`)',
                $mediaTable,
                $usageTable,
                $usersTable,
            ),
        );
        $unusedCount = (int) ($unusedRow['cnt'] ?? 0);

        return [
            'total_count' => $totalCount,
            'total_bytes' => $totalBytes,
            'variants_bytes' => $variantBytes,
            'unclassified_count' => $unclassified,
            'unused_count' => $unusedCount,
            'by_kind' => $byKind,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<Media>
     */
    private function hydrateMany(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $mediaIds = [];
        $authorIds = [];
        foreach ($rows as $row) {
            $mediaIds[] = (int) $row['id'];
            if (isset($row['author_id']) && $row['author_id'] !== '') {
                $authorIds[] = (int) $row['author_id'];
            }
        }

        $authorsMap = [];
        $uniqueAuthorIds = array_values(array_unique($authorIds));
        if ($uniqueAuthorIds !== []) {
            $userRows = $this->db->table('users')
                ->select(['id', 'display_name'])
                ->whereIn('id', $uniqueAuthorIds)
                ->get();
            foreach ($userRows as $ur) {
                $authorsMap[(int) $ur['id']] = (string) $ur['display_name'];
            }
        }

        $usageCounts = $this->usages !== null ? $this->usages->countByMediaIds($mediaIds) : [];

        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $authorId = isset($row['author_id']) && $row['author_id'] !== '' ? (int) $row['author_id'] : null;
            $items[] = Media::fromRow(
                $row,
                authorName: $authorId !== null ? ($authorsMap[$authorId] ?? null) : null,
                usageCount: $usageCounts[$id] ?? 0,
            );
        }

        return $items;
    }

    private function nowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
