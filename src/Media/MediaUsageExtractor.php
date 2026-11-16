<?php

declare(strict_types=1);

namespace Prime\Media;

use Prime\Content\Fields\Field;

/**
 * Extracts every media ID referenced by an entry's blocks, featured image, and `media` custom fields
 * so `MediaUsageRepository::sync()` keeps `pc_media_usage` strictly accurate.
 */
final class MediaUsageExtractor
{
    /**
     * Extract media IDs grouped by `field_name` (`featured_image`, `content`, `meta.<name>`).
     *
     * @param array<string, mixed> $document Decoded Prime Editor document
     * @param array<string, mixed> $meta Normalized entry meta map
     * @param array<string, Field> $fields Registered fields for this content type
     * @return array<string, list<int>>
     */
    public static function fromEntryData(array $document, array $meta, array $fields = []): array
    {
        $grouped = [];

        // 1. Featured image (`_featured_media_id`)
        $featuredId = self::positiveInt($meta['_featured_media_id'] ?? null);
        if ($featuredId !== null) {
            $grouped['featured_image'] = [$featuredId];
        }

        // 2. Prime Editor blocks
        $blockMediaIds = self::fromDocument($document);
        if ($blockMediaIds !== []) {
            $grouped['content'] = $blockMediaIds;
        }

        // 3. `media` custom fields
        foreach ($fields as $name => $field) {
            if ($field->type !== 'media') {
                continue;
            }
            $ids = self::extractIdsFromValue($meta[$name] ?? null);
            if ($ids !== []) {
                $grouped['meta.' . $name] = $ids;
            }
        }

        return $grouped;
    }

    /**
     * Extract all unique media IDs referenced inside Prime Editor blocks.
     *
     * @param array<string, mixed> $document
     * @return list<int>
     */
    public static function fromDocument(array $document): array
    {
        $blocks = $document['blocks'] ?? [];
        if (!is_array($blocks)) {
            return [];
        }

        $ids = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = is_string($block['type'] ?? null) ? $block['type'] : '';
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];

            switch ($type) {
                case 'image':
                case 'audio':
                case 'file':
                    $id = self::positiveInt($data['mediaId'] ?? null);
                    if ($id !== null) {
                        $ids[$id] = true;
                    }
                    break;

                case 'video':
                    $id = self::positiveInt($data['mediaId'] ?? null);
                    if ($id !== null) {
                        $ids[$id] = true;
                    }
                    $posterId = self::positiveInt($data['posterMediaId'] ?? null);
                    if ($posterId !== null) {
                        $ids[$posterId] = true;
                    }
                    break;

                case 'gallery':
                    $items = is_array($data['items'] ?? null) ? $data['items'] : [];
                    foreach ($items as $item) {
                        if (!is_array($item)) {
                            continue;
                        }
                        $id = self::positiveInt($item['mediaId'] ?? null);
                        if ($id !== null) {
                            $ids[$id] = true;
                        }
                    }
                    break;
            }
        }

        return array_values(array_map('intval', array_keys($ids)));
    }

    /**
     * @return list<int>
     */
    public static function extractIdsFromValue(mixed $value): array
    {
        $ids = [];

        if (is_int($value) && $value > 0) {
            return [$value];
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return [];
            }
            if (str_starts_with($trimmed, '[')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    $value = $decoded;
                }
            } else {
                foreach (explode(',', $trimmed) as $part) {
                    $id = self::positiveInt(trim($part));
                    if ($id !== null) {
                        $ids[$id] = true;
                    }
                }

                return array_values(array_map('intval', array_keys($ids)));
            }
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $id = self::positiveInt($item);
                if ($id !== null) {
                    $ids[$id] = true;
                }
            }
        }

        return array_values(array_map('intval', array_keys($ids)));
    }

    private static function positiveInt(mixed $raw): ?int
    {
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }
        if (is_string($raw) && ctype_digit($raw) && (int) $raw > 0) {
            return (int) $raw;
        }

        return null;
    }
}
