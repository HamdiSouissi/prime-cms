<?php

declare(strict_types=1);

namespace Prime\Site\Zone;

use Prime\Content\EntryQuery;
use Prime\Content\TaxonomyRegistry;
use Prime\Content\TermRepository;
use Prime\Hooks\Hooks;
use Prime\Site\Menu\MenuRenderer;
use Prime\Site\UrlGenerator;
use Throwable;

/**
 * Registry and runtime renderer for server-side dynamic Prime Editor blocks (L6).
 *
 * Built-in blocks:
 * - `latest_entries` (recent published entries of a content type)
 * - `term_list` (taxonomy terms with optional post counts)
 * - `search_form` (accessible site search form targeting `/recherche`)
 * - `menu` (renders any saved navigation menu by ID or location)
 *
 * Plugins register additional dynamic blocks via the `blocks.dynamic` filter hook.
 */
final class DynamicBlockRegistry
{
    /**
     * @var array<string, array{
     *     name: string,
     *     label: string,
     *     description: string,
     *     defaults: array<string, mixed>,
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
                    $blocks[$key] = [
                        'name' => $key,
                        'label' => is_string($def['label'] ?? null) && $def['label'] !== '' ? $def['label'] : $key,
                        'description' => is_string($def['description'] ?? null) ? $def['description'] : '',
                        'defaults' => is_array($def['defaults'] ?? null) ? $def['defaults'] : [],
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
     * Return serializable catalog metadata (without callables) for the Prime Editor / Zones UI.
     *
     * @return list<array{name: string, label: string, description: string, defaults: array<string, mixed>}>
     */
    public function catalog(): array
    {
        $list = [];
        foreach ($this->all() as $block) {
            $list[] = [
                'name' => $block['name'],
                'label' => $block['label'],
                'description' => $block['description'],
                'defaults' => $block['defaults'],
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
     *     render: callable(array<string, mixed>): string
     * }>
     */
    private function builtInBlocks(): array
    {
        return [
            'latest_entries' => [
                'name' => 'latest_entries',
                'label' => 'Derniers contenus',
                'description' => 'Affiche la liste des derniers articles ou contenus publiés.',
                'defaults' => [
                    'title' => 'Derniers articles',
                    'type' => 'post',
                    'limit' => 5,
                    'show_date' => true,
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
                    'limit' => 12,
                ],
                'render' => fn (array $attrs): string => $this->renderTermList($attrs),
            ],
            'search_form' => [
                'name' => 'search_form',
                'label' => 'Formulaire de recherche',
                'description' => 'Champ de recherche accessible pointant vers la page de recherche.',
                'defaults' => [
                    'title' => 'Rechercher',
                    'placeholder' => 'Rechercher sur le site…',
                    'button_label' => 'Rechercher',
                ],
                'render' => fn (array $attrs): string => $this->renderSearchForm($attrs),
            ],
            'menu' => [
                'name' => 'menu',
                'label' => 'Menu de navigation',
                'description' => 'Affiche un menu de navigation enregistré.',
                'defaults' => [
                    'title' => '',
                    'location' => 'footer',
                    'menu_id' => 0,
                ],
                'render' => fn (array $attrs): string => $this->renderMenuBlock($attrs),
            ],
        ];
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
        $limit = max(1, min(20, (int) ($attrs['limit'] ?? 5)));
        $showDate = (bool) ($attrs['show_date'] ?? true);

        $entries = $this->query->newQuery()
            ->type($type)
            ->orderBy('published_at', 'DESC')
            ->limit($limit)
            ->get();

        if ($entries === []) {
            return '';
        }

        $html = '<section class="prime-dyn prime-dyn--latest-entries">';
        if ($title !== '') {
            $html .= '<h3 class="prime-dyn__title">' . $this->e($title) . '</h3>';
        }
        $html .= '<ul class="prime-dyn__list">';
        foreach ($entries as $entry) {
            $url = $this->urls->entryUrl($entry);
            $html .= '<li class="prime-dyn__item">';
            $html .= '<a href="' . $this->e($url) . '">' . $this->e($entry->title) . '</a>';
            if ($showDate && ($entry->publishedAt ?? $entry->createdAt) !== '') {
                $ts = strtotime((string) ($entry->publishedAt ?? $entry->createdAt));
                if ($ts !== false) {
                    $iso = gmdate('Y-m-d', $ts);
                    $html .= ' <time class="prime-dyn__date" datetime="' . $this->e($iso) . '">' . $this->e(gmdate('d/m/Y', $ts)) . '</time>';
                }
            }
            $html .= '</li>';
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
        $showCount = (bool) ($attrs['show_count'] ?? true);
        $limit = max(1, min(50, (int) ($attrs['limit'] ?? 12)));

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
        $title = is_string($attrs['title'] ?? null) ? trim($attrs['title']) : '';
        $placeholder = is_string($attrs['placeholder'] ?? null) && trim($attrs['placeholder']) !== ''
            ? trim($attrs['placeholder'])
            : 'Rechercher sur le site…';
        $buttonLabel = is_string($attrs['button_label'] ?? null) && trim($attrs['button_label']) !== ''
            ? trim($attrs['button_label'])
            : 'Rechercher';
        $action = $this->urls->searchUrl();

        $html = '<section class="prime-dyn prime-dyn--search-form">';
        if ($title !== '') {
            $html .= '<h3 class="prime-dyn__title">' . $this->e($title) . '</h3>';
        }
        $html .= '<form class="site-search-form" role="search" method="get" action="' . $this->e($action) . '">';
        $html .= '<label class="sr-only" for="prime-zone-search">Rechercher</label>';
        $html .= '<input id="prime-zone-search" class="site-search-form__input" type="search" name="q" placeholder="' . $this->e($placeholder) . '" required>';
        $html .= '<button class="site-search-form__btn" type="submit">' . $this->e($buttonLabel) . '</button>';
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

    private function e(string $val): string
    {
        return htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
