<?php

declare(strict_types=1);

namespace Prime\Content;

/**
 * Immutable snapshot of an entry stored in `pc_revisions` (regular revision or autosave).
 */
final readonly class Revision
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public int $id,
        public int $entryId,
        public ?int $authorId,
        public bool $isAutosave,
        public string $title,
        public ?string $excerpt,
        public string $content,
        public array $meta,
        public string $createdAt,
        public ?string $authorName = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row, ?string $authorName = null): self
    {
        $rawMeta = isset($row['meta']) && is_string($row['meta']) && $row['meta'] !== ''
            ? json_decode($row['meta'], true)
            : [];

        return new self(
            id: (int) ($row['id'] ?? 0),
            entryId: (int) ($row['entry_id'] ?? 0),
            authorId: isset($row['author_id']) ? (int) $row['author_id'] : null,
            isAutosave: (bool) ($row['is_autosave'] ?? false),
            title: (string) ($row['title'] ?? ''),
            excerpt: isset($row['excerpt']) && $row['excerpt'] !== '' ? (string) $row['excerpt'] : null,
            content: (string) ($row['content'] ?? '{"version":1,"blocks":[]}'),
            meta: is_array($rawMeta) ? $rawMeta : [],
            createdAt: (string) ($row['created_at'] ?? ''),
            authorName: $authorName ?? (isset($row['author_name']) ? (string) $row['author_name'] : null),
        );
    }
}
