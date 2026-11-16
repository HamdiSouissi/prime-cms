<?php

declare(strict_types=1);

namespace Prime\Site\Seo;

use Prime\Content\Entry;
use Prime\Hooks\Hooks;
use Prime\Media\MediaRepository;
use Prime\Settings\Settings;
use Prime\Site\PageContext;
use Prime\Site\UrlGenerator;
use Prime\Themes\ThemeOptions;
use Throwable;

/**
 * Generates SEO `<head>` tags (title, description, canonical, robots, pagination rel=prev/next,
 * Open Graph, Twitter Card, favicons, and JSON-LD `WebSite`, `Article`, `BreadcrumbList`)
 * with `seo.title`, `seo.meta`, and `seo.schema` filter hooks.
 */
final class SeoManager
{
    public function __construct(
        private readonly SeoSettings $seoSettings,
        private readonly UrlGenerator $urls,
        private readonly ?Settings $settings = null,
        private readonly ?MediaRepository $media = null,
        private readonly ?ThemeOptions $themeOptions = null,
        private readonly ?Hooks $hooks = null,
    ) {
    }

    /**
     * Compute the document `<title>` for `$context` and run the `seo.title` filter.
     */
    public function title(PageContext $context): string
    {
        $siteName = $this->siteName();
        $tagline = $this->siteTagline();
        $sep = $this->seoSettings->separator();

        $rawTitle = match ($context->type) {
            PageContext::TYPE_FRONT => $this->frontTitle($context, $siteName, $tagline, $sep),
            PageContext::TYPE_BLOG => $this->formatTitle($context->entry !== null ? $context->entry->title : 'Blog', $siteName, $sep),
            PageContext::TYPE_ENTRY => $this->entryTitle($context->entry, $siteName, $sep),
            PageContext::TYPE_ARCHIVE => $this->formatTitle($context->contentType !== null ? $context->contentType->label('plural') : 'Archives', $siteName, $sep),
            PageContext::TYPE_TERM => $this->formatTitle($context->term !== null ? $context->term->name : 'Archive', $siteName, $sep),
            PageContext::TYPE_AUTHOR => $this->formatTitle($context->author !== null ? $context->author->displayName : 'Auteur', $siteName, $sep),
            PageContext::TYPE_SEARCH => $this->formatTitle(
                $context->searchQuery !== null && $context->searchQuery !== ''
                    ? sprintf('Recherche : %s', $context->searchQuery)
                    : 'Recherche',
                $siteName,
                $sep,
            ),
            default => $this->formatTitle('Page introuvable', $siteName, $sep),
        };

        if ($context->page > 1) {
            $rawTitle = sprintf('%s %s Page %d', $rawTitle, $sep, $context->page);
        }

        if ($this->hooks !== null) {
            $filtered = $this->hooks->apply('seo.title', $rawTitle, $context);
            if (is_string($filtered) && trim($filtered) !== '') {
                $rawTitle = trim($filtered);
            }
        }

        return $rawTitle;
    }

    /**
     * Compute the meta description for `$context`.
     */
    public function description(PageContext $context): string
    {
        $desc = '';

        if ($context->entry !== null) {
            $custom = $context->entry->meta('seo_description');
            if (is_string($custom) && trim($custom) !== '') {
                $desc = trim($custom);
            } elseif ($context->entry->excerpt !== null && trim($context->entry->excerpt) !== '') {
                $desc = trim($context->entry->excerpt);
            } elseif ($context->entry->contentText !== '') {
                $desc = $this->truncateText($context->entry->contentText, 160);
            }
        } elseif ($context->isTerm() && $context->term !== null && $context->term->description !== null && trim($context->term->description) !== '') {
            $desc = $this->truncateText($context->term->description, 160);
        } elseif ($context->isAuthor() && $context->author !== null && $context->author->bio !== null) {
            $desc = $this->truncateText($context->author->bio, 160);
        }

        if ($desc === '') {
            $desc = $this->seoSettings->defaultDescription();
        }
        if ($desc === '') {
            $desc = $this->siteTagline();
        }

        return $this->truncateText($desc, 160);
    }

    /**
     * Compute the canonical URL for `$context`.
     */
    public function canonicalUrl(PageContext $context): string
    {
        if ($context->entry !== null) {
            $custom = $context->entry->meta('seo_canonical');
            if (is_string($custom) && trim($custom) !== '') {
                return $this->urls->absolute(trim($custom));
            }
            if ($context->isEntry() || ($context->isFront() && $context->page === 1)) {
                return $this->urls->absolute($this->urls->entryUrl($context->entry));
            }
        }

        return $this->urls->absolute($context->path);
    }

    /**
     * Determine whether `$context` must emit `noindex, nofollow`.
     */
    public function shouldNoindex(PageContext $context): bool
    {
        if ($this->seoSettings->globalNoindex()) {
            return true;
        }
        if ($context->isPreview || $context->isCustomizerPreview) {
            return true;
        }
        if ($context->isNotFound() || $context->isSearch()) {
            return true;
        }
        if ($context->entry !== null && self::entryIsNoindex($context->entry)) {
            return true;
        }

        return false;
    }

    public static function entryIsNoindex(Entry $entry): bool
    {
        $val = $entry->meta('seo_noindex');
        if (is_bool($val)) {
            return $val;
        }
        if (is_int($val)) {
            return $val === 1;
        }
        if (is_string($val)) {
            return in_array(strtolower(trim($val)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * Build the structured metadata dictionary and apply the `seo.meta` filter.
     *
     * @return array<string, mixed>
     */
    public function meta(PageContext $context): array
    {
        $title = $this->title($context);
        $description = $this->description($context);
        $canonical = $this->canonicalUrl($context);
        $noindex = $this->shouldNoindex($context);
        $imageUrl = $this->resolveShareImageUrl($context);
        $ogType = ($context->isEntry() && $context->entry !== null && $context->entry->type === 'post')
            ? 'article'
            : 'website';

        $meta = [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'robots' => $noindex ? 'noindex, nofollow' : 'index, follow',
            'og:title' => $title,
            'og:description' => $description,
            'og:type' => $ogType,
            'og:url' => $canonical,
            'og:site_name' => $this->siteName(),
            'og:locale' => 'fr_FR',
            'og:image' => $imageUrl,
            'twitter:card' => $imageUrl !== null ? 'summary_large_image' : 'summary',
            'twitter:title' => $title,
            'twitter:description' => $description,
            'twitter:image' => $imageUrl,
        ];

        if ($this->hooks !== null) {
            $filtered = $this->hooks->apply('seo.meta', $meta, $context);
            if (is_array($filtered)) {
                /** @var array<string, mixed> $filtered */
                $meta = $filtered;
            }
        }

        return $meta;
    }

    /**
     * Build JSON-LD structured data (`WebSite` + `SearchAction`, `Article`/`BlogPosting`, `BreadcrumbList`)
     * and apply the `seo.schema` filter.
     *
     * @return list<array<string, mixed>>
     */
    public function schemas(PageContext $context): array
    {
        $schemas = [];
        $siteUrl = $this->urls->absolute('/');
        $siteName = $this->siteName();

        // 1. WebSite + SearchAction (homepage or global context)
        if ($context->isFront()) {
            $schemas[] = [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => $siteName,
                'url' => $siteUrl,
                'description' => $this->description($context),
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => $this->urls->absolute($this->urls->searchUrl()) . '?q={search_term_string}',
                    'query-input' => 'required name=search_term_string',
                ],
            ];
        }

        // 2. Article / BlogPosting on single post entries
        if ($context->isEntry() && $context->entry !== null && $context->entry->type === 'post') {
            $entry = $context->entry;
            $article = [
                '@context' => 'https://schema.org',
                '@type' => 'BlogPosting',
                'headline' => $entry->title,
                'description' => $this->description($context),
                'url' => $this->canonicalUrl($context),
                'mainEntityOfPage' => [
                    '@type' => 'WebPage',
                    '@id' => $this->canonicalUrl($context),
                ],
                'publisher' => [
                    '@type' => 'Organization',
                    'name' => $siteName,
                ],
            ];
            if ($entry->publishedAt !== null && $entry->publishedAt !== '') {
                $article['datePublished'] = gmdate('c', strtotime($entry->publishedAt) ?: time());
            }
            if ($entry->updatedAt !== '') {
                $article['dateModified'] = gmdate('c', strtotime($entry->updatedAt) ?: time());
            }
            if ($entry->authorName !== null && $entry->authorName !== '') {
                $article['author'] = [
                    '@type' => 'Person',
                    'name' => $entry->authorName,
                ];
            }
            $image = $this->resolveShareImageUrl($context);
            if ($image !== null) {
                $article['image'] = [$image];
            }
            $schemas[] = $article;
        }

        // 3. BreadcrumbList on non-front, non-404 pages
        $crumbs = $this->breadcrumbs($context);
        if (count($crumbs) > 1) {
            $items = [];
            foreach ($crumbs as $idx => $crumb) {
                $items[] = [
                    '@type' => 'ListItem',
                    'position' => $idx + 1,
                    'name' => $crumb['label'],
                    'item' => $this->urls->absolute($crumb['url']),
                ];
            }
            $schemas[] = [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => $items,
            ];
        }

        if ($this->hooks !== null) {
            $filtered = $this->hooks->apply('seo.schema', $schemas, $context);
            if (is_array($filtered)) {
                /** @var list<array<string, mixed>> $filtered */
                $schemas = array_values(array_filter($filtered, 'is_array'));
            }
        }

        return $schemas;
    }

    /**
     * Build semantic breadcrumb trail for `$context`.
     *
     * @return list<array{label: string, url: string}>
     */
    public function breadcrumbs(PageContext $context): array
    {
        if ($context->isFront() || $context->isNotFound()) {
            return [];
        }

        $crumbs = [
            ['label' => 'Accueil', 'url' => '/'],
        ];

        if ($context->isBlog()) {
            $crumbs[] = ['label' => $context->entry !== null ? $context->entry->title : 'Blog', 'url' => $context->path];
        } elseif ($context->isEntry() && $context->entry !== null) {
            if ($context->entry->type === 'post') {
                $crumbs[] = ['label' => 'Blog', 'url' => $this->urls->blogUrl()];
            } elseif ($context->entry->type !== 'page' && $context->contentType !== null) {
                $crumbs[] = ['label' => $context->contentType->label('plural'), 'url' => $this->urls->archiveUrl($context->contentType->name)];
            }
            $crumbs[] = ['label' => $context->entry->title, 'url' => $this->urls->entryUrl($context->entry)];
        } elseif ($context->isArchive() && $context->contentType !== null) {
            $crumbs[] = ['label' => $context->contentType->label('plural'), 'url' => $this->urls->archiveUrl($context->contentType->name)];
        } elseif ($context->isTerm() && $context->term !== null) {
            $crumbs[] = ['label' => $context->term->name, 'url' => $this->urls->termUrl($context->term)];
        } elseif ($context->isAuthor() && $context->author !== null) {
            $crumbs[] = ['label' => $context->author->displayName, 'url' => $this->urls->authorUrl($context->author)];
        } elseif ($context->isSearch()) {
            $crumbs[] = ['label' => 'Recherche', 'url' => $this->urls->searchUrl($context->searchQuery)];
        }

        return $crumbs;
    }

    /**
     * Render all SEO `<head>` tags (meta, canonical, pagination links, OG, Twitter, favicons, JSON-LD).
     */
    public function renderHeadTags(PageContext $context): string
    {
        $meta = $this->meta($context);
        $lines = [];

        $lines[] = '<title>' . $this->e((string) ($meta['title'] ?? $this->siteName())) . '</title>';

        if (is_string($meta['description'] ?? null) && $meta['description'] !== '') {
            $lines[] = '<meta name="description" content="' . $this->e($meta['description']) . '">';
        }

        if (is_string($meta['robots'] ?? null) && $meta['robots'] !== '') {
            $lines[] = '<meta name="robots" content="' . $this->e($meta['robots']) . '">';
        }

        if (!$context->isNotFound() && is_string($meta['canonical'] ?? null) && $meta['canonical'] !== '') {
            $lines[] = '<link rel="canonical" href="' . $this->e($meta['canonical']) . '">';
        }

        // Pagination rel="prev" / rel="next"
        if ($context->totalPages > 1) {
            $basePath = preg_replace('#/page/\d+$#', '', $context->path) ?? $context->path;
            $basePath = $basePath === '' ? '/' : $basePath;
            $qs = ($context->isSearch() && $context->searchQuery !== null && $context->searchQuery !== '')
                ? '?q=' . rawurlencode($context->searchQuery)
                : '';

            if ($context->page > 1) {
                $prevUrl = $this->urls->absolute($this->urls->paginatePath($basePath, $context->page - 1)) . $qs;
                $lines[] = '<link rel="prev" href="' . $this->e($prevUrl) . '">';
            }
            if ($context->page < $context->totalPages) {
                $nextUrl = $this->urls->absolute($this->urls->paginatePath($basePath, $context->page + 1)) . $qs;
                $lines[] = '<link rel="next" href="' . $this->e($nextUrl) . '">';
            }
        }

        // Open Graph
        foreach (['og:title', 'og:description', 'og:type', 'og:url', 'og:site_name', 'og:locale', 'og:image'] as $prop) {
            if (is_string($meta[$prop] ?? null) && $meta[$prop] !== '') {
                $lines[] = '<meta property="' . $this->e($prop) . '" content="' . $this->e($meta[$prop]) . '">';
            }
        }

        // Twitter Card
        foreach (['twitter:card', 'twitter:title', 'twitter:description', 'twitter:image'] as $name) {
            if (is_string($meta[$name] ?? null) && $meta[$name] !== '') {
                $lines[] = '<meta name="' . $this->e($name) . '" content="' . $this->e($meta[$name]) . '">';
            }
        }

        // Favicons
        $favicons = $this->themeOptions !== null ? $this->themeOptions->favicons() : [
            32 => '/assets/brand/favicon-32.png',
            180 => '/assets/brand/apple-touch-icon.png',
        ];
        if (isset($favicons[32])) {
            $lines[] = '<link rel="icon" type="image/png" sizes="32x32" href="' . $this->e($favicons[32]) . '">';
        }
        if (isset($favicons[180])) {
            $lines[] = '<link rel="apple-touch-icon" sizes="180x180" href="' . $this->e($favicons[180]) . '">';
        }
        if (isset($favicons[192])) {
            $lines[] = '<link rel="icon" type="image/png" sizes="192x192" href="' . $this->e($favicons[192]) . '">';
        }

        // JSON-LD structured data (safe JSON escaping preventing `</script>` breakout)
        foreach ($this->schemas($context) as $schema) {
            $json = json_encode(
                $schema,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
            );
            if (is_string($json)) {
                $lines[] = '<script type="application/ld+json">' . $json . '</script>';
            }
        }

        return implode("\n", $lines);
    }

    private function frontTitle(PageContext $context, string $siteName, string $tagline, string $sep): string
    {
        if ($context->entry !== null) {
            $custom = $context->entry->meta('seo_title');
            if (is_string($custom) && trim($custom) !== '') {
                return trim($custom);
            }
        }

        return $tagline !== '' ? sprintf('%s %s %s', $siteName, $sep, $tagline) : $siteName;
    }

    private function entryTitle(?Entry $entry, string $siteName, string $sep): string
    {
        if ($entry === null) {
            return $siteName;
        }
        $custom = $entry->meta('seo_title');
        if (is_string($custom) && trim($custom) !== '') {
            return trim($custom);
        }

        return $this->formatTitle($entry->title, $siteName, $sep);
    }

    private function formatTitle(string $pageTitle, string $siteName, string $sep): string
    {
        return str_replace(
            ['{title}', '{sep}', '{site_name}'],
            [$pageTitle, $sep, $siteName],
            $this->seoSettings->titleTemplate(),
        );
    }

    private function resolveShareImageUrl(PageContext $context): ?string
    {
        $mediaId = null;

        if ($context->entry !== null) {
            $seoImg = $context->entry->meta('seo_image_id');
            if (is_numeric($seoImg) && (int) $seoImg > 0) {
                $mediaId = (int) $seoImg;
            } else {
                $featured = $context->entry->meta('featured_image');
                if (is_numeric($featured) && (int) $featured > 0) {
                    $mediaId = (int) $featured;
                }
            }
        }

        if ($mediaId === null) {
            $mediaId = $this->seoSettings->defaultImageId();
        }

        if ($mediaId === null || $this->media === null) {
            return null;
        }

        $item = $this->media->find($mediaId);
        if ($item === null) {
            return null;
        }

        return $this->urls->absolute($item->url());
    }

    private function siteName(): string
    {
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

    private function siteTagline(): string
    {
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

    private function truncateText(string $text, int $max): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? $text);
        if (mb_strlen($clean, 'UTF-8') <= $max) {
            return $clean;
        }

        return rtrim(mb_substr($clean, 0, $max - 1, 'UTF-8')) . '…';
    }

    private function e(string $val): string
    {
        return htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
