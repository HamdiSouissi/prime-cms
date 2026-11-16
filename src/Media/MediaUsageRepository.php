<?php

declare(strict_types=1);

namespace Prime\Media;

use Prime\Content\Entry;
use Prime\Content\Fields\FieldRegistry;
use Prime\Database\Connection;

/**
 * Tracks where media items are referenced across content entries (`pc_media_usage`):
 * - `thumbnail` (featured image)
 * - `block` (Prime Editor image, gallery, file, audio, video blocks)
 * - `field:<name>` (custom `media` fields)
 */
final class MediaUsageRepository
{
    public const CONTEXT_THUMBNAIL = 'thumbnail';
    public const CONTEXT_BLOCK = 'block';
    public const CONTEXT_FIELD_PREFIX = 'field:';

    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * Recalculate and replace all media usages for a given entry inside a transaction.
     *
     * @param list<array{media_id: int, context: string}> $usages
     */
    public function syncForEntry(int $entryId, array $usages): void
    {
        // Deduplicate & verify referenced media IDs exist so FK never fails if a referenced media was deleted
        $uniquePairs = [];
        $candidateIds = [];
        foreach ($usages as $item) {
            $mid = $item['media_id'];
            $ctx = trim($item['context']);
            if ($mid <= 0 || $ctx === '') {
                continue;
            }
            $ctx = mb_substr($ctx, 0, 100, 'UTF-8');
            $key = $mid . '|' . $ctx;
            $uniquePairs[$key] = ['media_id' => $mid, 'context' => $ctx];
            $candidateIds[$mid] = $mid;
        }

        $validIds = [];
        if ($candidateIds !== []) {
            $existingRows = $this->db->table('media')
                ->select(['id'])
                ->whereIn('id', array_values($candidateIds))
                ->get();
            foreach ($existingRows as $row) {
                $validIds[(int) $row['id']] = true;
            }
        }

        $this->db->transaction(function () use ($entryId, $uniquePairs, $validIds): void {
            $this->db->table('media_usage')
                ->where('entry_id', '=', $entryId)
                ->delete();

            foreach ($uniquePairs as $pair) {
                if (!isset($validIds[$pair['media_id']])) {
                    continue;
                }
                $this->db->table('media_usage')->insert([
                    'media_id' => $pair['media_id'],
                    'entry_id' => $entryId,
                    'context' => $pair['context'],
                ]);
            }
        });
    }

    /**
     * Extract all media usages (`thumbnail`, `block`, `field:<name>`) from an entry and persist them.
     */
    public function syncFromEntry(Entry $entry, ?FieldRegistry $fields = null): void
    {
        $this->syncForEntry($entry->id, self::extractUsagesFromEntry($entry, $fields));
    }

    /**
     * Return all entries currently referencing a media item, hydrated with entry title/type/status/edit URL.
     *
     * @return list<array{entry_id: int, context: string, context_label: string, title: string, type: string, status: string, edit_url: string}>
     */
    public function findByMedia(int $mediaId): array
    {
        $usageTable = $this->db->tableName('media_usage');
        $entriesTable = $this->db->tableName('entries');

        $rows = $this->db->select(
            sprintf(
                'SELECT u.`entry_id`, u.`context`, e.`title`, e.`type`, e.`status`
                 FROM `%s` u
                 INNER JOIN `%s` e ON e.`id` = u.`entry_id`
                 WHERE u.`media_id` = ?
                 ORDER BY e.`updated_at` DESC, u.`context` ASC',
                $usageTable,
                $entriesTable,
            ),
            [$mediaId],
        );

        $results = [];
        foreach ($rows as $row) {
            $entryId = (int) $row['entry_id'];
            $type = (string) $row['type'];
            $context = (string) $row['context'];
            $results[] = [
                'entry_id' => $entryId,
                'context' => $context,
                'context_label' => self::contextLabel($context),
                'title' => (string) $row['title'],
                'type' => $type,
                'status' => (string) $row['status'],
                'edit_url' => sprintf('/admin/content/%s/%d/edit', $type, $entryId),
            ];
        }

        return $results;
    }

    /**
     * Batch count of usages keyed by `media_id` (plus avatar references on `pc_users`).
     *
     * @param list<int> $mediaIds
     * @return array<int, int>
     */
    public function countByMediaIds(array $mediaIds): array
    {
        $ids = array_values(array_unique(array_filter($mediaIds, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $usageTable = $this->db->tableName('media_usage');

        $rows = $this->db->select(
            sprintf(
                'SELECT `media_id`, COUNT(*) AS `cnt` FROM `%s` WHERE `media_id` IN (%s) GROUP BY `media_id`',
                $usageTable,
                $placeholders,
            ),
            $ids,
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['media_id']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Extract all referenced `(media_id, context)` pairs from an Entry's featured image,
     * Prime Editor JSON blocks, and custom `media` fields.
     *
     * @return list<array{media_id: int, context: string}>
     */
    public static function extractUsagesFromEntry(Entry $entry, ?FieldRegistry $fields = null): array
    {
        $usages = [];

        // 1. Featured image (`thumbnail`)
        $featuredId = $entry->meta('_featured_media_id');
        if (is_numeric($featuredId) && (int) $featuredId > 0) {
            $usages[] = [
                'media_id' => (int) $featuredId,
                'context' => self::CONTEXT_THUMBNAIL,
            ];
        }

        // 2. Editor JSON blocks (`block`)
        $doc = $entry->decodedContent();
        foreach ($doc['blocks'] as $block) {
            $data = $block['data'] ?? [];
            foreach (self::extractMediaIdsFromBlockData($data) as $mid) {
                $usages[] = [
                    'media_id' => $mid,
                    'context' => self::CONTEXT_BLOCK,
                ];
            }
        }

        // 3. Custom `media` fields (`field:<name>`)
        if ($fields !== null) {
            foreach ($fields->fieldsForContentType($entry->type) as $name => $field) {
                if ($field->type !== 'media') {
                    continue;
                }
                $val = $entry->meta($name);
                foreach (self::extractMediaIdsFromFieldValue($val) as $mid) {
                    $usages[] = [
                        'media_id' => $mid,
                        'context' => self::CONTEXT_FIELD_PREFIX . $name,
                    ];
                }
            }
        }

        return $usages;
    }

    public static function contextLabel(string $context): string
    {
        if ($context === self::CONTEXT_THUMBNAIL) {
            return 'Image mise en avant';
        }
        if ($context === self::CONTEXT_BLOCK) {
            return 'Contenu (bloc)';
        }
        if (str_starts_with($context, self::CONTEXT_FIELD_PREFIX)) {
            return 'Champ « ' . substr($context, strlen(self::CONTEXT_FIELD_PREFIX)) . ' »';
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<int>
     */
    private static function extractMediaIdsFromBlockData(array $data): array
    {
        $ids = [];

        foreach (['mediaId', 'media_id', 'posterMediaId', 'poster_media_id'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key]) && (int) $data[$key] > 0) {
                $ids[] = (int) $data[$key];
            }
        }

        // Gallery items array: [{ mediaId, url, alt, caption }, ...]
        if (isset($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                foreach (['mediaId', 'media_id'] as $k) {
                    if (isset($item[$k]) && is_numeric($item[$k]) && (int) $item[$k] > 0) {
                        $ids[] = (int) $item[$k];
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<int>
     */
    private static function extractMediaIdsFromFieldValue(mixed $val): array
    {
        if (is_int($val) && $val > 0) {
            return [$val];
        }
        if (is_string($val)) {
            $trimmed = trim($val);
            if ($trimmed === '') {
                return [];
            }
            if (ctype_digit($trimmed) && (int) $trimmed > 0) {
                return [(int) $trimmed];
            }
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                $val = $decoded;
            } else {
                $parts = array_filter(explode(',', $trimmed), static fn (string $s): bool => ctype_digit(trim($s)));

                return array_values(array_unique(array_filter(array_map('intval', $parts), static fn (int $i): bool => $i > 0)));
            }
        }
        if (is_array($val)) {
            $ids = [];
            foreach ($val as $item) {
                if (is_numeric($item) && (int) $item > 0) {
                    $ids[] = (int) $item;
                }
            }

            return array_values(array_unique($ids));
        }

        return [];
    }
}
