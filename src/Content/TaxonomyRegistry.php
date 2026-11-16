<?php

declare(strict_types=1);

namespace Prime\Content;

use InvalidArgumentException;
use Prime\Hooks\Hooks;

/**
 * Registry of all Prime CMS taxonomies (core `category` & `tag` plus plugin taxonomies).
 */
final class TaxonomyRegistry
{
    /**
     * @var array<string, Taxonomy>
     */
    private array $taxonomies = [];

    public function __construct(
        private readonly ?Hooks $hooks = null,
    ) {
        $this->registerCoreTaxonomies();
    }

    public function register(Taxonomy $taxonomy): void
    {
        $this->taxonomies[$taxonomy->name] = $taxonomy;
    }

    public function has(string $name): bool
    {
        return isset($this->resolved()[$name]);
    }

    public function get(string $name): ?Taxonomy
    {
        return $this->resolved()[$name] ?? null;
    }

    /**
     * @return array<string, Taxonomy>
     */
    public function all(): array
    {
        return $this->resolved();
    }

    /**
     * Return all taxonomies associated with a content type (either declared on the
     * taxonomy's `contentTypes` or listed on the `ContentType::$taxonomies`).
     *
     * @return array<string, Taxonomy>
     */
    public function forContentType(string $contentType, ?ContentType $typeDefinition = null): array
    {
        $result = [];

        foreach ($this->resolved() as $name => $taxonomy) {
            if (
                $taxonomy->appliesTo($contentType)
                || ($typeDefinition !== null && in_array($name, $typeDefinition->taxonomies, true))
            ) {
                $result[$name] = $taxonomy;
            }
        }

        return $result;
    }

    /**
     * @return array<string, Taxonomy>
     */
    private function resolved(): array
    {
        if ($this->hooks === null) {
            return $this->taxonomies;
        }

        $filtered = $this->hooks->apply('content.taxonomies', $this->taxonomies);
        if (!is_array($filtered)) {
            return $this->taxonomies;
        }

        $validated = [];
        foreach ($filtered as $key => $taxonomy) {
            if ($taxonomy instanceof Taxonomy) {
                $validated[$taxonomy->name] = $taxonomy;
            } elseif (is_string($key)) {
                throw new InvalidArgumentException(sprintf(
                    'Filter "content.taxonomies" returned invalid definition for taxonomy [%s].',
                    $key,
                ));
            }
        }

        return $validated;
    }

    private function registerCoreTaxonomies(): void
    {
        $this->register(new Taxonomy(
            name: 'category',
            labels: [
                'singular' => 'Catégorie',
                'plural' => 'Catégories',
                'add_new' => 'Ajouter une catégorie',
                'parent_label' => 'Catégorie parente',
                'empty_title' => 'Aucune catégorie',
            ],
            hierarchical: true,
            contentTypes: ['post'],
            public: true,
            capability: 'content.edit_any',
            defaultTermSlug: 'non-classe',
            icon: 'folder',
            source: 'core',
        ));

        $this->register(new Taxonomy(
            name: 'tag',
            labels: [
                'singular' => 'Étiquette',
                'plural' => 'Étiquettes',
                'add_new' => 'Ajouter une étiquette',
                'parent_label' => 'Parent',
                'empty_title' => 'Aucune étiquette pour le moment',
            ],
            hierarchical: false,
            contentTypes: ['post'],
            public: true,
            capability: 'content.edit_any',
            defaultTermSlug: null,
            icon: 'tag',
            source: 'core',
        ));
    }
}
