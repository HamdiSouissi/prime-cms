<?php

declare(strict_types=1);

namespace Prime\Content;

use Prime\I18n\Translator;

/**
 * Immutable content entry value object representing a row in `pc_entries`
 * optionally hydrated with its metadata, taxonomy terms, and author info.
 */
final readonly class Entry
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PRIVATE = 'private';
    public const STATUS_TRASH = 'trash';

    /**
     * @var list<string>
     */
    public const ALL_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_PUBLISHED,
        self::STATUS_SCHEDULED,
        self::STATUS_PRIVATE,
        self::STATUS_TRASH,
    ];

    /**
     * Human-readable French labels for every status.
     *
     * @var array<string, string>
     */
    /**
     * Catalogue keys for every status label.
     *
     * @var array<string, string>
     */
    public const STATUS_LABEL_KEYS = [
        self::STATUS_DRAFT => 'core.statuses.draft',
        self::STATUS_PENDING => 'core.statuses.pending_review',
        self::STATUS_PUBLISHED => 'core.statuses.published',
        self::STATUS_SCHEDULED => 'core.statuses.scheduled',
        self::STATUS_PRIVATE => 'core.statuses.private',
        self::STATUS_TRASH => 'core.statuses.trash',
    ];

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Brouillon',
        self::STATUS_PENDING => 'En attente de relecture',
        self::STATUS_PUBLISHED => 'Publié',
        self::STATUS_SCHEDULED => 'Planifié',
        self::STATUS_PRIVATE => 'Privé',
        self::STATUS_TRASH => 'Corbeille',
    ];

    /**
     * Design system badge variants for every status.
     *
     * @var array<string, string>
     */
    public const STATUS_VARIANTS = [
        self::STATUS_DRAFT => 'neutral',
        self::STATUS_PENDING => 'amber',
        self::STATUS_PUBLISHED => 'success',
        self::STATUS_SCHEDULED => 'sky',
        self::STATUS_PRIVATE => 'violet',
        self::STATUS_TRASH => 'danger',
    ];

    /**
     * @param array<string, mixed> $meta
     * @param array<string, list<Term>> $terms Grouped by taxonomy name
     */
    public function __construct(
        public int $id,
        public string $type,
        public string $status,
        public string $title,
        public string $slug,
        public ?string $excerpt,
        public string $content,
        public string $contentHtml,
        public string $contentText,
        public ?int $authorId,
        public int $parentId,
        public int $menuOrder,
        public ?string $template,
        public ?string $publishedAt,
        public ?string $previousStatus,
        public ?string $trashedAt,
        public string $createdAt,
        public string $updatedAt,
        public array $meta = [],
        public array $terms = [],
        public ?string $authorName = null,
        public ?string $authorEmail = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $meta
     * @param array<string, list<Term>> $terms
     */
    public static function fromRow(
        array $row,
        array $meta = [],
        array $terms = [],
        ?string $authorName = null,
        ?string $authorEmail = null,
    ): self {
        return new self(
            id: (int) ($row['id'] ?? 0),
            type: (string) ($row['type'] ?? 'post'),
            status: (string) ($row['status'] ?? self::STATUS_DRAFT),
            title: (string) ($row['title'] ?? ''),
            slug: (string) ($row['slug'] ?? ''),
            excerpt: isset($row['excerpt']) && $row['excerpt'] !== '' ? (string) $row['excerpt'] : null,
            content: (string) ($row['content'] ?? '{"version":1,"blocks":[]}'),
            contentHtml: (string) ($row['content_html'] ?? ''),
            contentText: (string) ($row['content_text'] ?? ''),
            authorId: isset($row['author_id']) ? (int) $row['author_id'] : null,
            parentId: (int) ($row['parent_id'] ?? 0),
            menuOrder: (int) ($row['menu_order'] ?? 0),
            template: isset($row['template']) && $row['template'] !== '' ? (string) $row['template'] : null,
            publishedAt: isset($row['published_at']) && $row['published_at'] !== '' ? (string) $row['published_at'] : null,
            previousStatus: isset($row['previous_status']) && $row['previous_status'] !== '' ? (string) $row['previous_status'] : null,
            trashedAt: isset($row['trashed_at']) && $row['trashed_at'] !== '' ? (string) $row['trashed_at'] : null,
            createdAt: (string) ($row['created_at'] ?? ''),
            updatedAt: (string) ($row['updated_at'] ?? ''),
            meta: $meta,
            terms: $terms,
            authorName: $authorName ?? (isset($row['author_name']) ? (string) $row['author_name'] : null),
            authorEmail: $authorEmail ?? (isset($row['author_email']) ? (string) $row['author_email'] : null),
        );
    }

    /**
     * Decode the stored Prime Editor JSON document.
     *
     * @return array{version: int, blocks: list<array{id: string, type: string, data: array<string, mixed>}>}
     */
    public function decodedContent(): array
    {
        $decoded = json_decode($this->content, true);
        if (!is_array($decoded) || !is_array($decoded['blocks'] ?? null)) {
            return ['version' => 1, 'blocks' => []];
        }

        /** @var list<array{id: string, type: string, data: array<string, mixed>}> $blocks */
        $blocks = array_values(array_filter($decoded['blocks'], 'is_array'));

        return [
            'version' => is_int($decoded['version'] ?? null) ? $decoded['version'] : 1,
            'blocks' => $blocks,
        ];
    }

    public function meta(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->meta) ? $this->meta[$key] : $default;
    }

    /**
     * Return the featured image media ID stored in `_featured_media_id` meta, if set.
     */
    public function featuredMediaId(): ?int
    {
        $raw = $this->meta('_featured_media_id');
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }
        if (is_string($raw) && ctype_digit($raw) && (int) $raw > 0) {
            return (int) $raw;
        }

        return null;
    }

    /**
     * @return list<Term>
     */
    public function terms(string $taxonomy): array
    {
        return $this->terms[$taxonomy] ?? [];
    }

    /**
     * Return all term IDs attached to this entry (or for a single taxonomy).
     *
     * @return list<int>
     */
    public function termIds(?string $taxonomy = null): array
    {
        if ($taxonomy !== null) {
            return array_map(static fn (Term $t): int => $t->id, $this->terms($taxonomy));
        }

        $ids = [];
        foreach ($this->terms as $list) {
            foreach ($list as $term) {
                $ids[] = $term->id;
            }
        }

        return array_values(array_unique($ids));
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isTrashed(): bool
    {
        return $this->status === self::STATUS_TRASH;
    }

    /**
     * Localised status label (`core.statuses.*`), falling back to the raw status.
     */
    public function statusLabel(): string
    {
        if (!isset(self::STATUS_LABELS[$this->status])) {
            return $this->status;
        }

        $key = self::STATUS_LABEL_KEYS[$this->status];
        $translated = Translator::fallback()->translate($key);

        return $translated === $key ? self::STATUS_LABELS[$this->status] : $translated;
    }

    public function statusVariant(): string
    {
        return self::STATUS_VARIANTS[$this->status] ?? 'neutral';
    }

    public function editUrl(): string
    {
        return sprintf('/admin/content/%s/%d/edit', $this->type, $this->id);
    }

    public function previewUrl(): string
    {
        return sprintf('/admin/content/%s/%d/preview', $this->type, $this->id);
    }

    /**
     * Resolve the public URL of this entry via `UrlGenerator` (with clean fallback outside HTTP).
     */
    public function url(): string
    {
        if (\Prime\Kernel\Application::hasInstance()) {
            $container = \Prime\Kernel\Application::getInstance()->container();
            if ($container->has(\Prime\Site\UrlGenerator::class)) {
                /** @var \Prime\Site\UrlGenerator $generator */
                $generator = $container->get(\Prime\Site\UrlGenerator::class);

                return $generator->entryUrl($this);
            }
        }

        if ($this->type === 'page') {
            return '/' . rawurlencode($this->slug);
        }
        if ($this->type === 'post') {
            return '/blog/' . rawurlencode($this->slug);
        }

        return '/' . rawurlencode($this->type) . '/' . rawurlencode($this->slug);
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, list<Term>> $terms
     */
    public function withRelations(
        array $meta,
        array $terms,
        ?string $authorName = null,
        ?string $authorEmail = null,
    ): self {
        return new self(
            id: $this->id,
            type: $this->type,
            status: $this->status,
            title: $this->title,
            slug: $this->slug,
            excerpt: $this->excerpt,
            content: $this->content,
            contentHtml: $this->contentHtml,
            contentText: $this->contentText,
            authorId: $this->authorId,
            parentId: $this->parentId,
            menuOrder: $this->menuOrder,
            template: $this->template,
            publishedAt: $this->publishedAt,
            previousStatus: $this->previousStatus,
            trashedAt: $this->trashedAt,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
            meta: $meta,
            terms: $terms,
            authorName: $authorName ?? $this->authorName,
            authorEmail: $authorEmail ?? $this->authorEmail,
        );
    }
}
