<?php

declare(strict_types=1);

namespace Prime\Content;

use InvalidArgumentException;
use Prime\Hooks\Hooks;

/**
 * Registry of all Prime CMS content types (core `page` & `post` plus plugin types).
 */
final class ContentTypeRegistry
{
    /**
     * @var array<string, ContentType>
     */
    private array $types = [];

    public function __construct(
        private readonly ?Hooks $hooks = null,
    ) {
        $this->registerCoreTypes();
    }

    public function register(ContentType $type): void
    {
        $this->types[$type->name] = $type;
    }

    public function has(string $name): bool
    {
        return isset($this->resolved()[$name]);
    }

    public function get(string $name): ?ContentType
    {
        return $this->resolved()[$name] ?? null;
    }

    /**
     * Return all registered content types ordered by `menuPosition` then `name`.
     *
     * @return array<string, ContentType>
     */
    public function all(): array
    {
        $sorted = $this->resolved();

        uasort($sorted, static function (ContentType $a, ContentType $b): int {
            $pos = $a->menuPosition <=> $b->menuPosition;

            return $pos !== 0 ? $pos : strcmp($a->name, $b->name);
        });

        return $sorted;
    }

    /**
     * @return array<string, ContentType>
     */
    public function allPublic(): array
    {
        return array_filter($this->all(), static fn (ContentType $type): bool => $type->public);
    }

    /**
     * @return array<string, ContentType>
     */
    private function resolved(): array
    {
        if ($this->hooks === null) {
            return $this->types;
        }

        $filtered = $this->hooks->apply('content.types', $this->types);
        if (!is_array($filtered)) {
            return $this->types;
        }

        $validated = [];
        foreach ($filtered as $key => $type) {
            if ($type instanceof ContentType) {
                $validated[$type->name] = $type;
            } elseif (is_string($key)) {
                throw new InvalidArgumentException(sprintf(
                    'Filter "content.types" returned invalid definition for type [%s].',
                    $key,
                ));
            }
        }

        return $validated;
    }

    private function registerCoreTypes(): void
    {
        $this->register(new ContentType(
            name: 'page',
            labels: [
                'singular' => 'core.content_types.page.singular',
                'plural' => 'core.content_types.page.plural',
                'add_new' => 'core.content_types.page.add_new',
                'edit' => 'core.content_types.page.edit',
                'empty_title' => 'core.content_types.page.empty_title',
                'empty_description' => 'core.content_types.page.empty_description',
                'search_placeholder' => 'core.content_types.page.search_placeholder',
            ],
            icon: 'file-text',
            accent: 'teal',
            hierarchical: true,
            public: true,
            supports: ['editor', 'author', 'revisions', 'page_attributes', 'custom_fields', 'thumbnail'],
            taxonomies: [],
            menuPosition: 20,
            rewriteBase: '',
            editor: 'blocks',
            source: 'core',
        ));

        $this->register(new ContentType(
            name: 'post',
            labels: [
                'singular' => 'core.content_types.post.singular',
                'plural' => 'core.content_types.post.plural',
                'add_new' => 'core.content_types.post.add_new',
                'edit' => 'core.content_types.post.edit',
                'empty_title' => 'core.content_types.post.empty_title',
                'empty_description' => 'core.content_types.post.empty_description',
                'search_placeholder' => 'core.content_types.post.search_placeholder',
            ],
            icon: 'newspaper',
            accent: 'teal',
            hierarchical: false,
            public: true,
            supports: ['editor', 'excerpt', 'author', 'revisions', 'custom_fields', 'thumbnail'],
            taxonomies: ['category', 'tag'],
            menuPosition: 30,
            rewriteBase: 'articles',
            editor: 'blocks',
            source: 'core',
        ));
    }
}
