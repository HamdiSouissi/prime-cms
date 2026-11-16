<?php

declare(strict_types=1);

namespace Prime\Content;

use InvalidArgumentException;

/**
 * Immutable code-declared taxonomy definition (core `category`/`tag` or plugin taxonomy).
 */
final readonly class Taxonomy
{
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{1,39}$/';

    /**
     * @var array{
     *   singular: string,
     *   plural: string,
     *   add_new: string,
     *   parent_label: string,
     *   empty_title: string
     * }
     */
    public array $labels;

    /**
     * @var list<string>
     */
    public array $contentTypes;

    /**
     * @param array<string, string> $labels
     * @param list<string> $contentTypes
     */
    public function __construct(
        public string $name,
        array $labels,
        public bool $hierarchical = false,
        array $contentTypes = [],
        public bool $public = true,
        public string $capability = 'content.edit_any',
        public ?string $defaultTermSlug = null,
        public string $icon = 'tag',
        public string $source = 'core',
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid taxonomy name [%s]. Must match %s.',
                $name,
                self::NAME_PATTERN,
            ));
        }

        $singular = trim($labels['singular'] ?? ucfirst($name));
        $plural = trim($labels['plural'] ?? ($singular . 's'));

        $this->labels = [
            'singular' => $singular,
            'plural' => $plural,
            'add_new' => trim($labels['add_new'] ?? ('Ajouter : ' . mb_strtolower($singular))),
            'parent_label' => trim($labels['parent_label'] ?? ($singular . ' parent')),
            'empty_title' => trim($labels['empty_title'] ?? ('Aucun terme dans « ' . $plural . ' »')),
        ];

        $types = [];
        foreach ($contentTypes as $ct) {
            $clean = trim($ct);
            if ($clean !== '' && !in_array($clean, $types, true)) {
                $types[] = $clean;
            }
        }
        $this->contentTypes = $types;
    }

    public function label(string $key): string
    {
        return $this->labels[$key] ?? $this->labels['singular'];
    }

    public function appliesTo(string $contentType): bool
    {
        return in_array($contentType, $this->contentTypes, true);
    }

    public function adminUrl(): string
    {
        return '/admin/taxonomies/' . $this->name;
    }

    public function withSource(string $source): self
    {
        return new self(
            name: $this->name,
            labels: $this->labels,
            hierarchical: $this->hierarchical,
            contentTypes: $this->contentTypes,
            public: $this->public,
            capability: $this->capability,
            defaultTermSlug: $this->defaultTermSlug,
            icon: $this->icon,
            source: $source,
        );
    }
}
