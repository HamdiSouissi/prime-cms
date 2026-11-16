<?php

declare(strict_types=1);

namespace Prime\Site\View;

use Prime\Content\EntryQuery;
use Prime\Hooks\Hooks;
use Prime\Media\MediaHelper;
use Prime\Media\MediaRepository;
use Prime\Settings\Settings;
use Prime\Site\Menu\MenuRenderer;
use Prime\Site\PageContext;
use Prime\Site\Seo\SeoManager;
use Prime\Site\TemplateResolver;
use Prime\Site\UrlGenerator;
use Prime\Site\Zone\DynamicBlockRegistry;
use Prime\Site\Zone\ZoneRepository;
use Prime\Themes\ThemeAssetPublisher;
use Prime\Themes\ThemeAssetRegistry;
use Prime\Themes\ThemeInfo;
use Prime\Themes\ThemeOptions;
use Throwable;

/**
 * Escaped-by-default global `$site` helper passed into every public theme template (L4).
 */
final class SiteView
{
    /** @var array<string, mixed>|null */
    private ?array $optionsCache = null;

    /** @var list<EntryView> */
    private array $boundEntries = [];

    private ?EntryView $boundEntry = null;

    /** @var list<string> */
    private array $themeBaseDirs;

    /**
     * @param list<string> $themeBaseDirs Ordered `[childThemeDir, parentThemeDir]`
     */
    public function __construct(
        private readonly PageContext $context,
        private readonly ThemeInfo $activeTheme,
        private readonly ?ThemeInfo $parentTheme,
        array $themeBaseDirs,
        private readonly ThemeOptions $themeOptions,
        private readonly ThemeAssetRegistry $assets,
        private readonly ThemeAssetPublisher $publisher,
        private readonly TemplateResolver $templates,
        private readonly UrlGenerator $urls,
        private readonly MenuRenderer $menuRenderer,
        private readonly ZoneRepository $zones,
        private readonly DynamicBlockRegistry $dynamicBlocks,
        private readonly SeoManager $seo,
        private readonly EntryViewFactory $entryFactory,
        private readonly EntryQuery $entryQuery,
        private readonly ?Settings $settings = null,
        private readonly ?MediaRepository $media = null,
        private readonly ?Hooks $hooks = null,
    ) {
        $this->themeBaseDirs = $themeBaseDirs;
    }

    /**
     * Bind current page's `$entry` and `$entries` so `partial()` forwards them cleanly.
     *
     * @param list<EntryView> $entries
     */
    public function bindViews(?EntryView $entry, array $entries): void
    {
        $this->boundEntry = $entry;
        $this->boundEntries = $entries;
    }

    public function context(): PageContext
    {
        return $this->context;
    }

    public function theme(): ThemeInfo
    {
        return $this->activeTheme;
    }

    /**
     * Escaped site name (`site.name`).
     */
    public function name(): string
    {
        return $this->e($this->rawName());
    }

    public function rawName(): string
    {
        $override = $this->rawOption('site_name');
        if (is_string($override) && trim($override) !== '') {
            return trim($override);
        }

        if ($this->settings !== null) {
            try {
                foreach (['site.name', 'site_name'] as $key) {
                    $val = $this->settings->get($key);
                    if (is_string($val) && trim($val) !== '') {
                        return trim($val);
                    }
                }
            } catch (Throwable) {
                // Ignore
            }
        }

        return 'Prime CMS';
    }

    /**
     * Escaped site tagline (`site.tagline`).
     */
    public function tagline(): string
    {
        return $this->e($this->rawTagline());
    }

    public function rawTagline(): string
    {
        $override = $this->rawOption('site_tagline');
        if (is_string($override) && trim($override) !== '') {
            return trim($override);
        }

        if ($this->settings !== null) {
            try {
                foreach (['site.tagline', 'site_tagline'] as $key) {
                    $val = $this->settings->get($key);
                    if (is_string($val) && trim($val) !== '') {
                        return trim($val);
                    }
                }
            } catch (Throwable) {
                // Ignore
            }
        }

        return '';
    }

    public function url(string $path = ''): string
    {
        $clean = $path === '' ? '/' : '/' . ltrim($path, '/');

        return $this->e($clean);
    }

    public function homeUrl(): string
    {
        return '/';
    }

    public function blogUrl(?int $page = null): string
    {
        return $this->e($this->urls->blogUrl($page));
    }

    public function searchUrl(?string $query = null): string
    {
        return $this->e($this->urls->searchUrl($query));
    }

    public function hasLogo(): bool
    {
        return $this->logoMediaId() !== null;
    }

    /**
     * Safe HTML for the configured site logo media (falls back to empty string).
     *
     * @param array<string, mixed> $attrs
     */
    public function logo(array $attrs = []): string
    {
        $id = $this->logoMediaId();
        if ($id === null || $this->media === null) {
            return '';
        }

        $item = $this->media->find($id);
        if ($item === null || !$item->isImage()) {
            return '';
        }

        $merged = array_merge([
            'variant' => 'medium',
            'alt' => $this->rawName(),
            'class' => 'site-brand__logo',
            'loading' => 'eager',
        ], $attrs);

        return MediaHelper::image($item, $merged);
    }

    /**
     * Escaped theme option value (strings are HTML-escaped; booleans/ints returned as-is).
     */
    public function option(string $key, mixed $default = null): mixed
    {
        $val = $this->rawOption($key, $default);
        if (is_string($val)) {
            return $this->e($val);
        }

        return $val;
    }

    /**
     * Raw unescaped theme option value (ideal for toggles, numeric comparisons, select keys).
     */
    public function rawOption(string $key, mixed $default = null): mixed
    {
        if ($this->optionsCache === null && $this->activeTheme->manifest !== null) {
            $this->optionsCache = $this->themeOptions->all(
                $this->activeTheme->manifest,
                $this->parentTheme?->manifest,
            );
        }

        if ($this->optionsCache !== null && array_key_exists($key, $this->optionsCache)) {
            return $this->optionsCache[$key];
        }

        return $default;
    }

    /**
     * Render a navigation menu assigned to `$location` (safe HTML).
     *
     * @param array<string, mixed> $options
     */
    public function menu(string $location, array $options = []): string
    {
        $options['current_path'] ??= $this->context->path;

        return $this->menuRenderer->renderLocation($location, $options);
    }

    public function hasMenu(string $location): bool
    {
        return $this->menuRenderer->hasLocation($location);
    }

    /**
     * Render a Prime Editor block zone live (safe HTML, evaluates dynamic blocks).
     */
    public function zone(string $slug): string
    {
        return $this->zones->renderLive($slug, $this->dynamicBlocks);
    }

    public function hasZone(string $slug): bool
    {
        return $this->zones->hasContent($slug);
    }

    /**
     * Return a published theme asset URL with cache-busting hash (child overrides parent).
     */
    public function asset(string $relativePath): string
    {
        $clean = ltrim(str_replace('\\', '/', $relativePath), '/');
        $slug = $this->activeTheme->slug;

        // If child theme doesn't have the asset, fall back to parent theme
        if ($this->parentTheme !== null) {
            $childFile = rtrim($this->activeTheme->path, DIRECTORY_SEPARATOR . '/') . '/assets/' . $clean;
            if (!is_file($childFile)) {
                $slug = $this->parentTheme->slug;
            }
        }

        return $this->e($this->publisher->url($slug, $clean));
    }

    /**
     * Emit all `<head>` SEO metadata, favicons, JSON-LD, and registered CSS links (safe HTML).
     */
    public function head(): string
    {
        if ($this->hooks !== null) {
            $this->hooks->emit('site.assets', $this->assets, $this->context);
        }

        $parts = [];
        $parts[] = $this->seo->renderHeadTags($this->context);

        $stylesHtml = $this->assets->renderStyles();
        if ($stylesHtml !== '') {
            $parts[] = $stylesHtml;
        }

        if ($this->hooks !== null) {
            $extra = $this->hooks->apply('site.head', '', $this->context);
            if (is_string($extra) && trim($extra) !== '') {
                $parts[] = trim($extra);
            }
        }

        return implode("\n", array_filter($parts, static fn (string $s): bool => $s !== ''));
    }

    /**
     * Emit registered deferred `<script>` tags before `</body>` (safe HTML).
     */
    public function foot(): string
    {
        $parts = [];
        $scriptsHtml = $this->assets->renderScripts();
        if ($scriptsHtml !== '') {
            $parts[] = $scriptsHtml;
        }

        // Load external customizer preview listener ONLY in authenticated customizer iframe preview
        if ($this->context->isCustomizerPreview) {
            $parts[] = '<script src="/assets/admin/js/customizer-preview.js" defer></script>';
        }

        if ($this->hooks !== null) {
            $extra = $this->hooks->apply('site.foot', '', $this->context);
            if (is_string($extra) && trim($extra) !== '') {
                $parts[] = trim($extra);
            }
        }

        return implode("\n", array_filter($parts, static fn (string $s): bool => $s !== ''));
    }

    /**
     * Semantic space-separated CSS classes for `<body>`.
     */
    public function bodyClass(): string
    {
        $classes = ['site-body', 'ctx-' . $this->context->type, 'theme-' . $this->activeTheme->slug];
        if ($this->context->entry !== null) {
            $classes[] = 'entry-type-' . $this->context->entry->type;
            if ($this->context->entry->template !== null) {
                $classes[] = 'entry-tpl-' . $this->context->entry->template;
            }
        }
        if ($this->context->contentType !== null) {
            $classes[] = 'content-type-' . $this->context->contentType->name;
        }
        if ($this->hasZone('sidebar')) {
            $classes[] = 'has-sidebar';
        }
        if ($this->context->isPreview) {
            $classes[] = 'is-preview';
        }
        if ($this->context->isCustomizerPreview) {
            $classes[] = 'is-customizer-preview';
        }

        return $this->e(implode(' ', $classes));
    }

    /**
     * Escaped breadcrumb items for the current page.
     *
     * @return list<array{label: string, url: string}>
     */
    public function breadcrumbs(): array
    {
        $out = [];
        foreach ($this->seo->breadcrumbs($this->context) as $crumb) {
            $out[] = [
                'label' => $this->e($crumb['label']),
                'url' => $this->e($crumb['url']),
            ];
        }

        return $out;
    }

    /**
     * Structured pagination descriptor for archive/blog/term/author/search listings.
     *
     * @return array{
     *     current: int,
     *     total: int,
     *     prev_url: ?string,
     *     next_url: ?string,
     *     pages: list<array{num: int, url: string, active: bool}>
     * }
     */
    public function pagination(): array
    {
        $current = max(1, $this->context->page);
        $total = max(1, $this->context->totalPages);

        if ($total <= 1) {
            return [
                'current' => 1,
                'total' => 1,
                'prev_url' => null,
                'next_url' => null,
                'pages' => [],
            ];
        }

        $basePath = preg_replace('#/page/\d+$#', '', $this->context->path) ?? $this->context->path;
        $basePath = $basePath === '' ? '/' : $basePath;
        $qs = ($this->context->isSearch() && $this->context->searchQuery !== null && $this->context->searchQuery !== '')
            ? '?q=' . rawurlencode($this->context->searchQuery)
            : '';

        $buildUrl = fn (int $p): string => $this->e($this->urls->paginatePath($basePath, $p) . $qs);

        $pages = [];
        for ($i = 1; $i <= $total; $i++) {
            $pages[] = [
                'num' => $i,
                'url' => $buildUrl($i),
                'active' => $i === $current,
            ];
        }

        return [
            'current' => $current,
            'total' => $total,
            'prev_url' => $current > 1 ? $buildUrl($current - 1) : null,
            'next_url' => $current < $total ? $buildUrl($current + 1) : null,
            'pages' => $pages,
        ];
    }

    /**
     * Query published entries from inside any theme template, returning batch-hydrated `EntryView`s.
     *
     * @param array{type?: string|list<string>, limit?: int, order_by?: string, direction?: string, taxonomy?: string, term_id?: int} $args
     * @return list<EntryView>
     */
    public function entries(array $args = []): array
    {
        $q = $this->entryQuery->newQuery();

        if (isset($args['type'])) {
            $q->type($args['type']);
        } else {
            $q->type('post');
        }

        if (isset($args['taxonomy'], $args['term_id'])) {
            $q->whereTerm($args['taxonomy'], $args['term_id']);
        }

        $orderBy = is_string($args['order_by'] ?? null) ? $args['order_by'] : 'published_at';
        $direction = is_string($args['direction'] ?? null) ? $args['direction'] : 'DESC';
        $q->orderBy($orderBy, $direction);

        $limit = isset($args['limit']) ? max(1, min(50, $args['limit'])) : 6;
        $q->limit($limit);

        return $this->entryFactory->makeMany($q->get());
    }

    /**
     * Include a theme partial (`partials/{name}.php`) safely with child -> parent inheritance.
     *
     * @param array<string, mixed> $data
     */
    public function partial(string $name, array $data = []): string
    {
        $rel = 'partials/' . ltrim($name, '/');
        if (!str_ends_with($rel, '.php')) {
            $rel .= '.php';
        }

        $file = $this->templates->resolveRelative($rel, $this->themeBaseDirs);
        if ($file === null) {
            return '';
        }

        return self::renderPhpFile($file, array_merge([
            'site' => $this,
            'context' => $this->context,
            'entry' => $this->boundEntry,
            'entries' => $this->boundEntries,
        ], $data));
    }

    public function isFront(): bool
    {
        return $this->context->isFront();
    }

    public function isBlog(): bool
    {
        return $this->context->isBlog();
    }

    public function isEntry(): bool
    {
        return $this->context->isEntry();
    }

    public function isArchive(): bool
    {
        return $this->context->isArchive();
    }

    public function isTerm(): bool
    {
        return $this->context->isTerm();
    }

    public function isAuthor(): bool
    {
        return $this->context->isAuthor();
    }

    public function isSearch(): bool
    {
        return $this->context->isSearch();
    }

    public function isNotFound(): bool
    {
        return $this->context->isNotFound();
    }

    public function isPreview(): bool
    {
        return $this->context->isPreview;
    }

    public function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Render a PHP template file in an isolated closure scope.
     *
     * @param array<string, mixed> $vars
     */
    public static function renderPhpFile(string $file, array $vars): string
    {
        $render = static function (string $__primeFile, array $__primeVars): string {
            extract($__primeVars, EXTR_SKIP);
            ob_start();
            try {
                include $__primeFile;

                return (string) ob_get_clean();
            } catch (Throwable $e) {
                ob_end_clean();
                throw $e;
            }
        };

        return $render($file, $vars);
    }

    private function logoMediaId(): ?int
    {
        $fromOpt = $this->rawOption('site_logo_id');
        if (is_numeric($fromOpt) && (int) $fromOpt > 0) {
            return (int) $fromOpt;
        }

        if ($this->settings !== null) {
            try {
                $val = $this->settings->get('site.logo_id');
                if (is_numeric($val) && (int) $val > 0) {
                    return (int) $val;
                }
            } catch (Throwable) {
                // Ignore
            }
        }

        return null;
    }
}
