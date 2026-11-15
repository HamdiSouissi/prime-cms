<?php

declare(strict_types=1);

namespace Prime\Content;

use InvalidArgumentException;

/**
 * Immutable code-declared content type definition (core or plugin-registered).
 */
final readonly class ContentType
{
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{1,39}$/';

    /**
     * Supported core feature identifiers.
     *
     * @var list<string>
     */
    public const VALID_SUPPORTS = [
        'editor',
        'excerpt',
        'author',
        'revisions',
        'page_attributes',
        'custom_fields',
        'thumbnail',
    ];

    /**
     * Allowed design system accents.
     *
     * @var list<string>
     */
    public const VALID_ACCENTS = ['brand', 'teal', 'violet', 'sky', 'amber'];

    /**
     * Allowed editor engines (`blocks` now, `builder` reserved for Phase 11).
     *
     * @var list<string>
     */
    public const VALID_EDITORS = ['blocks', 'builder'];

    /**
     * @var array{
     *   singular: string,
     *   plural: string,
     *   add_new: string,
     *   edit: string,
     *   empty_title: string,
     *   empty_description: string,
     *   search_placeholder: string
     * }
     */
    public array $labels;

    /**
     * @var list<string>
     */
    public array $supports;

    /**
     * @var list<string>
     */
    public array $taxonomies;

    /**
     * @var array{
     *   view: string,
     *   create: string,
     *   edit_own: string,
     *   edit_any: string,
     *   publish: string,
     *   delete_own: string,
     *   delete_any: string
     * }
     */
    public array $capabilities;

    /**
     * @param array<string, string> $labels
     * @param list<string> $supports
     * @param list<string> $taxonomies
     * @param array<string, string> $capabilities
     */
    public function __construct(
        public string $name,
        array $labels,
        public string $icon = 'file-text',
        public string $accent = 'teal',
        public bool $hierarchical = false,
        public bool $public = true,
        array $supports = ['editor', 'author', 'revisions', 'custom_fields'],
        array $taxonomies = [],
        public int $menuPosition = 25,
        public ?string $rewriteBase = null,
        public string $editor = 'blocks',
        array $capabilities = [],
        public string $source = 'core',
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid content type name [%s]. Must match %s.',
                $name,
                self::NAME_PATTERN,
            ));
        }

        if (!in_array($accent, self::VALID_ACCENTS, true)) {
            throw new InvalidArgumentException(sprintf('Invalid accent [%s] for content type [%s].', $accent, $name));
        }

        if (!in_array($editor, self::VALID_EDITORS, true)) {
            throw new InvalidArgumentException(sprintf('Invalid editor [%s] for content type [%s].', $editor, $name));
        }

        $singular = trim($labels['singular'] ?? ucfirst($name));
        $plural = trim($labels['plural'] ?? ($singular . 's'));

        $this->labels = [
            'singular' => $singular,
            'plural' => $plural,
            'add_new' => trim($labels['add_new'] ?? ('Créer un élément (' . mb_strtolower($singular) . ')')),
            'edit' => trim($labels['edit'] ?? ('Modifier : ' . mb_strtolower($singular))),
            'empty_title' => trim($labels['empty_title'] ?? ('Aucun contenu de type « ' . $singular . ' »')),
            'empty_description' => trim($labels['empty_description'] ?? 'Commencez par créer votre premier contenu.'),
            'search_placeholder' => trim($labels['search_placeholder'] ?? ('Rechercher dans ' . mb_strtolower($plural) . '…')),
        ];

        $cleanSupports = [];
        foreach ($supports as $feature) {
            if (!in_array($feature, self::VALID_SUPPORTS, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown feature [%s] in supports for content type [%s].',
                    $feature,
                    $name,
                ));
            }
            if (!in_array($feature, $cleanSupports, true)) {
                $cleanSupports[] = $feature;
            }
        }
        $this->supports = $cleanSupports;

        $cleanTaxonomies = [];
        foreach ($taxonomies as $tax) {
            $t = trim($tax);
            if ($t !== '' && !in_array($t, $cleanTaxonomies, true)) {
                $cleanTaxonomies[] = $t;
            }
        }
        $this->taxonomies = $cleanTaxonomies;

        $this->capabilities = [
            'view' => $capabilities['view'] ?? 'content.view',
            'create' => $capabilities['create'] ?? 'content.create',
            'edit_own' => $capabilities['edit_own'] ?? 'content.edit_own',
            'edit_any' => $capabilities['edit_any'] ?? 'content.edit_any',
            'publish' => $capabilities['publish'] ?? 'content.publish',
            'delete_own' => $capabilities['delete_own'] ?? 'content.delete_own',
            'delete_any' => $capabilities['delete_any'] ?? 'content.delete_any',
        ];
    }

    public function supports(string $feature): bool
    {
        return in_array($feature, $this->supports, true);
    }

    public function label(string $key): string
    {
        return $this->labels[$key] ?? $this->labels['singular'];
    }

    public function capability(string $action): string
    {
        return $this->capabilities[$action] ?? 'content.' . $action;
    }

    public function adminListUrl(): string
    {
        return '/admin/content/' . $this->name;
    }

    public function adminCreateUrl(): string
    {
        return '/admin/content/' . $this->name . '/create';
    }

    public function withSource(string $source): self
    {
        return new self(
            name: $this->name,
            labels: $this->labels,
            icon: $this->icon,
            accent: $this->accent,
            hierarchical: $this->hierarchical,
            public: $this->public,
            supports: $this->supports,
            taxonomies: $this->taxonomies,
            menuPosition: $this->menuPosition,
            rewriteBase: $this->rewriteBase,
            editor: $this->editor,
            capabilities: $this->capabilities,
            source: $source,
        );
    }
}
