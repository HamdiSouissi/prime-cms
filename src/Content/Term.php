<?php

declare(strict_types=1);

namespace Prime\Content;

/**
 * Immutable taxonomy term value object.
 */
final readonly class Term
{
    public function __construct(
        public int $id,
        public string $taxonomy,
        public string $name,
        public string $slug,
        public ?string $description,
        public int $parentId,
        public int $menuOrder,
        public int $count,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) ($row['id'] ?? 0),
            taxonomy: (string) ($row['taxonomy'] ?? ''),
            name: (string) ($row['name'] ?? ''),
            slug: (string) ($row['slug'] ?? ''),
            description: isset($row['description']) && $row['description'] !== '' ? (string) $row['description'] : null,
            parentId: (int) ($row['parent_id'] ?? 0),
            menuOrder: (int) ($row['menu_order'] ?? 0),
            count: (int) ($row['count'] ?? 0),
            createdAt: (string) ($row['created_at'] ?? ''),
            updatedAt: (string) ($row['updated_at'] ?? ''),
        );
    }
}
