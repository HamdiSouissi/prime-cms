<?php

declare(strict_types=1);

namespace Prime\Site\Zone;

use Prime\Content\ContentTypeRegistry;
use Prime\Content\EntryQuery;
use Prime\Content\TaxonomyRegistry;
use Prime\Content\TermRepository;
use Prime\Hooks\Hooks;
use Prime\Media\MediaRepository;
use Prime\Site\Menu\MenuRenderer;
use Prime\Site\Menu\MenuRepository;
use Prime\Site\UrlGenerator;
use Throwable;

/**
 * Registry and runtime renderer for server-side dynamic Prime Editor blocks (L6).
 *
 * Built-in blocks:
 * - `latest_entries` (recent published entries of a content type, with optional thumbnail & excerpt)
 * - `term_list` (taxonomy terms with optional post counts)
 * - `menu` (renders any saved navigation menu by ID)
 * - `search_form` (accessible site search form targeting `/recherche`, zero settings)
 *
 * Plugins register additional dynamic blocks via the `blocks.dynamic` filter hook
 * and may declare an inspector `fields` schema (`text`, `number`, `toggle`, `select`).
 */
final class DynamicBlockRegistry
{
    private const ALLOWED_FIELD_TYPES = ['text', 'number', 'toggle', 'select'];

    private int $searchInstanceCounter = 0;

    /**
     * @var array<string, array{
     *     name: string,
     *     label: string,
     *     description: string,
     *     defaults: array<string, mixed>,
     *     fields: list<array<string, mixed>>,
     *     render: callable(array<string, mixed>): string
     * }>|null
     */
    private ?array $resolvedCache = null;

    public function __construct(
        private readonly EntryQuery $query,
        private readonly TermRepository $terms,
        private readonly TaxonomyRegistry $taxonomies,
        private readonly UrlGenerator $urls,
        private readonly MenuRenderer $menuRenderer,
        private readonly ?Hooks $hooks = null,
        private readonly ?ContentTypeRegistry $contentTypes = null,
        private readonly ?MenuRepository $menus = null,
        private readonly ?MediaRepository $media = null,
    ) {
    }

    /**
     * Return all registered dynamic blocks (built-ins + plugin blocks from `blocks.dynamic`).
     *
     * @return array<string, array{
     *     name: string,
     *     label: string,
     *     description: string,
     *     defaults: array<string, mixed>,
     *     fields: list<array<string, mixed>>,
     *     render: callable(array<string, mixed>): string
     * }>
     */
    public function all(bool $fresh = false): array
    {
        if (!$fresh && $this->resolvedCache !== null) {
            return $this->resolvedCache;
        }

        $blocks = $this->builtInBlocks();

        if ($this->hooks !== null) {
            $filtered = $this->hooks->apply('blocks.dynamic', $blocks);
            if (is_array($filtered)) {
                foreach ($filtered as $key => $def) {
                    if (!is_string($key) || preg_match('/^[a-z0-9][a-z0-9_-]{1,60}$/', $key) !== 1 || !is_array($def)) {
                        continue;
                    }
                    if (!is_callable($def['render'] ?? null)) {
                        continue;
                    }
                    $defaults = is_array($def['defaults'] ?? null) ? $def['defaults'] : [];
                    $rawFields = is_array($def['fields'] ?? null) ? $def['fields'] : [];
                    $fields = $this->normalizeFields($rawFields, $defaults);

                    $blocks[$key] = [
                        'name' => $key,
                        'label' => is_string($def['label'] ?? null) && $def['label'] !== '' ? $def['label'] : $key,
                        'description' => is_string($def['description'] ?? null) ? $def['description'] : '',
                        'defaults' => $defaults,
                        'fields' => $fields,
                        'render' => $def['render'],
                    ];
                }
            }
        }

        $this->resolvedCache = $blocks;

        return $blocks;
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    /**
     * Return serializable catalog metadata (without callables) for the Prime Editor & Zones UI.
     *
     * @return list<array{
     *     name: string,
     *     label: string,
     *     description: string,
     *     defaults: array<string, mixed>,
     *     fields: list<array<string, mixed>>
     * }>
     */
    public function catalog(): array
    {
        $list = [];
        foreach ($this->all(fresh: true) as $block) {
            $list[] = [
                'name' => $block['name'],
                'label' => $block['label'],
                'description' => $block['description'],
                'defaults' => $block['defaults'],
                'fields' => $block['fields'],
            ];
        }

        return $list;
    }

    /**
     * Render a dynamic block safely at request time.
     *
     * @param array<string, mixed> $attrs
     */
    public function render(string $name, array $attrs = []): string
    {
        $blocks = $this->all();
        if (!isset($blocks[$name])) {
            return '';
        }

        $def = $blocks[$name];
        $merged = array_merge($def['defaults'], $attrs);

        try {
            return ($def['render'])($merged);
        } catch (Throwable) {
            return '';
        }
    }

    public function resetCache(): void
    {
        $this->resolvedCache = null;
    }

    /**
     * @return array<string, array{
     *     name: string,
     *     label: string,
     *     description: string,
     *     defaults: array<string, mixed>,
     *     fields: list<array<string, mixed>>,
     *     render: callable(array<string, mixed>): string
     * }>
     */
    private function builtInBlocks(): array
    {
        $typeOptions = $this->contentTypeOptions();
        $taxonomyOptions = $this->taxonomyOptions();
        $menuOptions = $this->menuOptions();
        $defaultMenuId = $menuOptions !== [] ? (int) $menuOptions[0]['value'] : 0;

        return [
            'latest_entries' => [
                'name' => 'latest_entries',
                'label' => 'Derniers contenus',
                'description' => 'Affiche la liste des derniers articles ou contenus publiés.',
                'defaults' => [
                    'title' => 'Derniers articles',
                    'type' => 'post',
                    'limit' => 5,
                    'show_image' => false,
                    'show_excerpt' => false,
                ],
                'fields' => [
                    [
                        'name' => 'title',
                        'type' => 'text',
                        'label' => 'Titre affiché',
                        'placeholder' => 'Derniers articles',
                        'default' => 'Derniers articles',
                    ],
                    [
                        'name' => 'type',
                        'type' => 'select',
                        'label' => 'Type de contenu',
                        'options' => $typeOptions,
                        'default' => 'post',
                    ],
                    [
                        'name' => 'limit',
                        'type' => 'number',
                        'label' => 'Nombre d’éléments (1 à 12)',
                        'min' => 1,
                        'max' => 12,
                        'step' => 1,
                        'default' => 5,
                    ],
                    [
                        'name' => 'show_image',
                        'type' => 'toggle',
                        'label' => 'Afficher l’image mise en avant',
                        'default' => false,
                    ],
                    [
                        'name' => 'show_excerpt',
                        'type' => 'toggle',
                        'label' => 'Afficher l’extrait',
                        'default' => false,
                    ],
                ],
                'render' => fn (array $attrs): string => $this->renderLatestEntries($attrs),
            ],
            'term_list' => [
                'name' => 'term_list',
                'label' => 'Liste de termes',
                'description' => 'Affiche les catégories ou étiquettes du site.',
                'defaults' => [
                    'title' => 'Catégories',
                    'taxonomy' => 'category',
                    'show_count' => true,
                ],
                'fields' => [
                    [
                        'name' => 'title',
                        'type' => 'text',
                        'label' => 'Titre affiché',
                        'placeholder' => 'Catégories',
                        'default' => 'Catégories',
                    ],
                    [
                        'name' => 'taxonomy',
                        'type' => 'select',
                        'label' => 'Taxonomie',
                        'options' => $taxonomyOptions,
                        'default' => 'category',
                    ],
                    [
                        'name' => 'show_count',
                        'type' => 'toggle',
                        'label' => 'Afficher le nombre de contenus',
                        'default' => true,
                    ],
                ],
                'render' => fn (array $attrs): string => $this->renderTermList($attrs),
            ],
            'menu' => [
                'name' => 'menu',
                'label' => 'Menu de navigation',
                'description' => 'Affiche un menu de navigation enregistré.',
                'defaults' => [
                    'title' => '',
                    'menu_id' => $defaultMenuId,
                ],
                'fields' => [
                    [
                        'name' => 'title',
                        'type' => 'text',
                        'label' => 'Titre affiché (optionnel)',
                        'placeholder' => 'Navigation',
                        'default' => '',
                    ],
                    [
                        'name' => 'menu_id',
                        'type' => 'select',
                        'label' => 'Menu à afficher',
                        'options' => $menuOptions !== []
                            ? $menuOptions
                            : [['value' => '0', 'label' => 'Aucun menu enregistré']],
                        'default' => (string) $defaultMenuId,
                    ],
                ],
                'render' => fn (array $attrs): string => $this->renderMenuBlock($attrs),
            ],
            'search_form' => [
                'name' => 'search_form',
                'label' => 'Formulaire de recherche',
                'description' => 'Champ de recherche accessible pointant vers la page de recherche.',
                'defaults' => [],
                'fields' => [],
                'render' => fn (array $attrs): string => $this->renderSearchForm($attrs),
            ],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function contentTypeOptions(): array
    {
        $options = [];
        if ($this->contentTypes !== null) {
            foreach ($this->contentTypes->all() as $ct) {
                if (!$ct->public) {
                    continue;
                }
                $options[] = [
                    'value' => $ct->name,
                    'label' => $ct->label('plural'),
                ];
            }
        }

        if ($options === []) {
            return [
                ['value' => 'post', 'label' => 'Articles'],
                ['value' => 'page', 'label' => 'Pages'],
            ];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function taxonomyOptions(): array
    {
        $options = [];
        foreach ($this->taxonomies->all() as $tax) {
            if (!$tax->public) {
                continue;
            }
            $options[] = [
                'value' => $tax->name,
                'label' => $tax->label('plural'),
            ];
        }

        if ($options === []) {
            return [
                ['value' => 'category', 'label' => 'Catégories'],
                ['value' => 'tag', 'label' => 'Étiquettes'],
            ];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function menuOptions(): array
    {
        if ($this->menus === null) {
            return [];
        }

        try {
            $all = $this->menus->all();
        } catch (Throwable) {
            return [];
        }

        $options = [];
        foreach ($all as $menu) {
            $options[] = [
                'value' => (string) $menu['id'],
                'label' => $menu['name'],
            ];
        }

        return $options;
    }

    /**
     * Normalize plugin-declared dynamic block fields into a clean serializable schema.
     *
     * @param array<mixed> $rawFields
     * @param array<string, mixed> $defaults
     * @return list<array<string, mixed>>
     */
    private function normalizeFields(array $rawFields, array $defaults): array
    {
        $normalized = [];
        foreach ($rawFields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = is_string($field['name'] ?? null) ? trim($field['name']) : '';
            $type = is_string($field['type'] ?? null) ? strtolower(trim($field['type'])) : 'text';
            if (preg_match('/^[a-z0-9_]{1,40}$/', $name) !== 1 || !in_array($type, self::ALLOWED_FIELD_TYPES, true)) {
                continue;
            }
            $label = is_string($field['label'] ?? null) && trim($field['label']) !== ''
                ? trim($field['label'])
                : $name;

            $item = [
                'name' => $name,
                'type' => $type,
                'label' => $label,
            ];

            if (array_key_exists('default', $field)) {
                $item['default'] = $field['default'];
            } elseif (array_key_exists($name, $defaults)) {
                $item['default'] = $defaults[$name];
            }

            if ($type === 'text' && is_string($field['placeholder'] ?? null)) {
                $item['placeholder'] = $field['placeholder'];
            }

            if ($type === 'number') {
                if (is_numeric($field['min'] ?? null)) {
                    $item['min'] = (int) $field['min'];
                }
                if (is_numeric($field['max'] ?? null)) {
                    $item['max'] = (int) $field['max'];
                }
                if (is_numeric($field['step'] ?? null)) {
                    $item['step'] = (int) $field['step'];
                }
            }

            if ($type === 'select') {
                $opts = [];
                if (is_array($field['options'] ?? null)) {
                    foreach ($field['options'] as $optKey => $optVal) {
                        if (is_array($optVal) && isset($optVal['value'])) {
                            $val = is_scalar($optVal['value']) ? (string) $optVal['value'] : '';
                            $optLabel = is_string($optVal['label'] ?? null) && $optVal['label'] !== '' ? $optVal['label'] : $val;
                            $opts[] = ['value' => $val, 'label' => $optLabel];
                        } elseif (is_scalar($optVal)) {
                            $opts[] = ['value' => (string) $optKey, 'label' => (string) $optVal];
                        }
                    }
                }
                $item['options'] = $opts;
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function renderLatestEntries(array $attrs): string
    {
        $title = is_string($attrs['title'] ?? null) ? trim($attrs['title']) : '';
        $type = is_string($attrs['type'] ?? null) && preg_match('/^[a-z0-9_-]{1,64}$/', $attrs['type']) === 1
            ? $attrs['type']
            : 'post';
        $limit = max(1, min(12, is_numeric($attrs['limit'] ?? null) ? (int) $attrs['limit'] : 5));
        $showImage = $this->toBool($attrs['show_image'] ?? false);
        $showExcerpt = $this->toBool($attrs['show_excerpt'] ?? false);
        $showDate = $this->toBool($attrs['show_date'] ?? true);

        $entries = $this->query->newQuery()
            ->type($type)
            ->orderBy('published_at', 'DESC')
            ->limit($limit)
            ->get();

        if ($entries === []) {
            return '';
        }

        $this->urls->primePageCache($entries);

        $mediaMap = [];
        if ($showImage && $this->media !== null) {
            $mediaIds = [];
            foreach ($entries as $entry) {
                $fid = $entry->featuredMediaId();
                if ($fid !== null) {
                    $mediaIds[$fid] = true;
                }
            }
            if ($mediaIds !== []) {
                $mediaMap = $this->media->findByIds(array_keys($mediaIds));
            }
        }

        $html = '<section class="prime-dyn prime-dyn--latest-entries">';
        if ($title !== '') {
            $html .= '<h3 class="prime-dyn__title">' . $this->e($title) . '</h3>';
        }
        $html .= '<ul class="prime-dyn__list">';
        foreach ($entries as $entry) {
            $url = $this->urls->entryUrl($entry);
            $html .= '<li class="prime-dyn__item' . ($showImage ? ' prime-dyn__item--with-thumb' : '') . '">';
            if ($showImage) {
                $fid = $entry->featuredMediaId();
                $thumb = $fid !== null ? ($mediaMap[$fid] ?? null) : null;
                if ($thumb !== null && $thumb->isImage()) {
                    $thumbUrl = $thumb->variantUrl('thumbnail');
                    $thumbAlt = $thumb->alt !== '' ? $thumb->alt : $entry->title;
                    $html .= '<a class="prime-dyn__thumb-link" href="' . $this->e($url) . '">';
                    $html .= '<img class="prime-dyn__thumb" src="' . $this->e($thumbUrl) . '" alt="' . $this->e($thumbAlt) . '" loading="lazy" decoding="async">';
                    $html .= '</a>';
                }
            }
            $html .= '<div class="prime-dyn__body">';
            $html .= '<a class="prime-dyn__link" href="' . $this->e($url) . '">' . $this->e($entry->title) . '</a>';
            $dateRaw = $entry->publishedAt ?? $entry->createdAt;
            if ($showDate && $dateRaw !== '') {
                $ts = strtotime($dateRaw);
                if ($ts !== false) {
                    $iso = gmdate('Y-m-d', $ts);
                    $html .= ' <time class="prime-dyn__date" datetime="' . $this->e($iso) . '">' . $this->e(gmdate('d/m/Y', $ts)) . '</time>';
                }
            }
            if ($showExcerpt) {
                $rawExcerpt = ($entry->excerpt !== null && trim($entry->excerpt) !== '')
                    ? trim($entry->excerpt)
                    : trim($entry->contentText);
                $cleanExcerpt = trim(preg_replace('/\s+/', ' ', strip_tags($rawExcerpt)) ?? $rawExcerpt);
                if (mb_strlen($cleanExcerpt, 'UTF-8') > 140) {
                    $cleanExcerpt = rtrim(mb_substr($cleanExcerpt, 0, 139, 'UTF-8')) . '…';
                }
                if ($cleanExcerpt !== '') {
                    $html .= '<p class="prime-dyn__excerpt">' . $this->e($cleanExcerpt) . '</p>';
                }
            }
            $html .= '</div></li>';
        }
        $html .= '</ul></section>';

        return $html;
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function renderTermList(array $attrs): string
    {
        $title = is_string($attrs['title'] ?? null) ? trim($attrs['title']) : '';
        $taxonomy = is_string($attrs['taxonomy'] ?? null) ? trim($attrs['taxonomy']) : 'category';
        if ($this->taxonomies->get($taxonomy) === null) {
            return '';
        }
        $showCount = $this->toBool($attrs['show_count'] ?? true);
        $limit = max(1, min(50, is_numeric($attrs['limit'] ?? null) ? (int) $attrs['limit'] : 12));

        $terms = array_slice($this->terms->allForTaxonomy($taxonomy), 0, $limit);
        if ($terms === []) {
            return '';
        }

        $html = '<section class="prime-dyn prime-dyn--term-list">';
        if ($title !== '') {
            $html .= '<h3 class="prime-dyn__title">' . $this->e($title) . '</h3>';
        }
        $html .= '<ul class="prime-dyn__list">';
        foreach ($terms as $term) {
            $url = $this->urls->termUrl($term);
            $html .= '<li class="prime-dyn__item">';
            $html .= '<a href="' . $this->e($url) . '">' . $this->e($term->name) . '</a>';
            if ($showCount) {
                $html .= ' <span class="prime-dyn__count">(' . $term->count . ')</span>';
            }
            $html .= '</li>';
        }
        $html .= '</ul></section>';

        return $html;
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function renderSearchForm(array $attrs): string
    {
        $this->searchInstanceCounter++;
        $inputId = 'prime-dyn-search-' . $this->searchInstanceCounter;
        $action = $this->urls->searchUrl();

        $html = '<section class="prime-dyn prime-dyn--search-form">';
        $html .= '<form class="site-search-form" role="search" method="get" action="' . $this->e($action) . '">';
        $html .= '<label class="sr-only" for="' . $this->e($inputId) . '">Rechercher sur le site</label>';
        $html .= '<input id="' . $this->e($inputId) . '" class="site-search-form__input" type="search" name="q" placeholder="Rechercher sur le site…" required>';
        $html .= '<button class="site-search-form__btn" type="submit">Rechercher</button>';
        $html .= '</form></section>';

        return $html;
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function renderMenuBlock(array $attrs): string
    {
        $title = is_string($attrs['title'] ?? null) ? trim($attrs['title']) : '';
        $location = is_string($attrs['location'] ?? null) ? trim($attrs['location']) : '';
        $menuId = is_numeric($attrs['menu_id'] ?? null) ? (int) $attrs['menu_id'] : 0;

        if ($menuId <= 0 && $location === '') {
            return '';
        }

        $opts = ['class' => 'prime-dyn__menu-nav', 'list_class' => 'prime-dyn__list'];
        if ($menuId > 0) {
            $opts['menu_id'] = $menuId;
        }
        $navHtml = $this->menuRenderer->renderLocation($location, $opts);
        if ($navHtml === '') {
            return '';
        }

        $html = '<section class="prime-dyn prime-dyn--menu">';
        if ($title !== '') {
            $html .= '<h3 class="prime-dyn__title">' . $this->e($title) . '</h3>';
        }
        $html .= $navHtml . '</section>';

        return $html;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    private function e(string $val): string
    {
        return htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
