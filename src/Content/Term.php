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

    /**
     * Resolve the public URL of this taxonomy term via `UrlGenerator`.
     */
    public function url(): string
    {
        if (\Prime\Kernel\Application::hasInstance()) {
            $container = \Prime\Kernel\Application::getInstance()->container();
            if ($container->has(\Prime\Site\UrlGenerator::class)) {
                /** @var \Prime\Site\UrlGenerator $generator */
                $generator = $container->get(\Prime\Site\UrlGenerator::class);

                return $generator->termUrl($this);
            }
        }

        $base = match ($this->taxonomy) {
            'category' => 'categorie',
            'tag' => 'etiquette',
            default => $this->taxonomy,
        };

        return '/' . rawurlencode($base) . '/' . rawurlencode($this->slug);
    }
}
