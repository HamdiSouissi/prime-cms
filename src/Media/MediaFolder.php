<?php

declare(strict_types=1);

namespace Prime\Media;

/**
 * Immutable value object representing a hierarchical media folder in `pc_media_folders`.
 */
final readonly class MediaFolder
{
    public function __construct(
        public int $id,
        public ?int $parentId,
        public string $name,
        public string $slug,
        public string $createdAt,
        public string $updatedAt,
        public int $mediaCount = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row, int $mediaCount = 0): self
    {
        return new self(
            id: (int) ($row['id'] ?? 0),
            parentId: isset($row['parent_id']) && $row['parent_id'] !== '' ? (int) $row['parent_id'] : null,
            name: (string) ($row['name'] ?? ''),
            slug: (string) ($row['slug'] ?? ''),
            createdAt: (string) ($row['created_at'] ?? ''),
            updatedAt: (string) ($row['updated_at'] ?? ''),
            mediaCount: isset($row['media_count']) ? max(0, (int) $row['media_count']) : $mediaCount,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parentId,
            'name' => $this->name,
            'slug' => $this->slug,
            'media_count' => $this->mediaCount,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
