<?php

declare(strict_types=1);

namespace Prime\Site;

use Prime\Auth\User;
use Prime\Content\ContentTypeRegistry;
use Prime\Content\Entry;
use Prime\Content\EntryRepository;
use Prime\Content\Term;

/**
 * Authoritative public URL generator for entries, terms, archives, authors,
 * search, blog listings, and pagination across admin, menus, templates, and sitemaps.
 */
final class UrlGenerator
{
    /**
     * In-request ancestor slug cache (`entry_id => ['slug' => ..., 'parent_id' => ...]`).
     *
     * @var array<int, array{slug: string, parent_id: int}>
     */
    private array $pageAncestorCache = [];

    public function __construct(
        private readonly PermalinkSettings $permalinks,
        private readonly ?ContentTypeRegistry $types = null,
        private readonly ?EntryRepository $entries = null,
        private readonly string $baseUrl = '',
    ) {
    }

    public function homeUrl(): string
    {
        return '/';
    }

    /**
     * Generate the public path for a content entry (`Entry`).
     */
    public function entryUrl(Entry $entry): string
    {
        // Static homepage returns `/`
        if (
            $entry->type === 'page'
            && $this->permalinks->frontMode() === PermalinkSettings::FRONT_MODE_PAGE
            && $this->permalinks->frontPageId() === $entry->id
        ) {
            return '/';
        }

        if ($entry->type === 'page') {
            return $this->hierarchicalPagePath($entry);
        }

        if ($entry->type === 'post') {
            $structure = $this->permalinks->postStructure();
            if ($structure === PermalinkSettings::POST_SLUG_ONLY) {
                return '/' . rawurlencode($entry->slug);
            }
            if ($structure === PermalinkSettings::POST_YEAR_MONTH_SLUG) {
                $dateSource = $entry->publishedAt ?? $entry->createdAt;
                $ts = $dateSource !== '' ? strtotime($dateSource) : false;
                $year = $ts !== false ? gmdate('Y', $ts) : gmdate('Y');
                $month = $ts !== false ? gmdate('m', $ts) : gmdate('m');

                return sprintf('/%s/%s/%s', $year, $month, rawurlencode($entry->slug));
            }

            return '/blog/' . rawurlencode($entry->slug);
        }

        // Custom content type: `/{rewriteBase or name}/{slug}`
        $base = $this->contentTypeBase($entry->type);

        return '/' . rawurlencode($base) . '/' . rawurlencode($entry->slug);
    }

    /**
     * Generate the public archive path for a content type (`post` -> blog URL, CPT -> `/{rewriteBase}`).
     */
    public function archiveUrl(string $typeName): string
    {
        if ($typeName === 'post') {
            return $this->blogUrl();
        }

        return '/' . rawurlencode($this->contentTypeBase($typeName));
    }

    /**
     * Generate the public blog listing URL (static posts page if configured, otherwise `/` or `/blog`).
     */
    public function blogUrl(?int $page = null): string
    {
        $base = '/';
        if ($this->permalinks->frontMode() === PermalinkSettings::FRONT_MODE_PAGE) {
            $postsPageId = $this->permalinks->postsPageId();
            if ($postsPageId !== null && $this->entries !== null) {
                $postsPage = $this->entries->find($postsPageId, withRelations: false);
                if ($postsPage !== null) {
                    $base = $this->hierarchicalPagePath($postsPage);
                } else {
                    $base = '/blog';
                }
            } else {
                $base = '/blog';
            }
        }

        return $page !== null && $page > 1 ? $this->paginatePath($base, $page) : $base;
    }

    /**
     * Generate the public path for a taxonomy term (`/{base}/{slug}`).
     */
    public function termUrl(Term $term): string
    {
        $base = $this->permalinks->taxonomyBase($term->taxonomy);

        return '/' . rawurlencode($base) . '/' . rawurlencode($term->slug);
    }

    /**
     * Generate the public author archive path (`/auteur/{username}`).
     */
    public function authorUrl(User|string $author): string
    {
        $slug = $author instanceof User ? $author->authorSlug() : trim($author);

        return '/' . rawurlencode($this->permalinks->authorBase()) . '/' . rawurlencode($slug);
    }

    /**
     * Generate the public search URL (`/recherche` or `/recherche?q=...`).
     */
    public function searchUrl(?string $query = null, int $page = 1): string
    {
        $base = '/' . rawurlencode($this->permalinks->searchBase());
        $path = $page > 1 ? $this->paginatePath($base, $page) : $base;

        if ($query !== null && trim($query) !== '') {
            return $path . '?q=' . rawurlencode(trim($query));
        }

        return $path;
    }

    /**
     * Append `/page/{n}` cleanly to any listing path (returns `$basePath` when `$page <= 1`).
     */
    public function paginatePath(string $basePath, int $page): string
    {
        $clean = '/' . trim($basePath, '/');
        if ($page <= 1) {
            return $clean;
        }

        return $clean === '/'
            ? '/page/' . $page
            : $clean . '/page/' . $page;
    }

    /**
     * Convert a root-relative path to an absolute URL using configured `APP_URL`.
     */
    public function absolute(string $path): string
    {
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        $root = rtrim($this->baseUrl, '/');
        if ($root === '') {
            return '/' . ltrim($path, '/');
        }

        return $root . '/' . ltrim($path, '/');
    }

    /**
     * Seed ancestor cache for a batch of pages to avoid extra DB queries.
     *
     * @param list<Entry> $entries
     */
    public function primePageCache(array $entries): void
    {
        foreach ($entries as $entry) {
            if ($entry->type === 'page') {
                $this->pageAncestorCache[$entry->id] = [
                    'slug' => $entry->slug,
                    'parent_id' => $entry->parentId,
                ];
            }
        }
    }

    public function contentTypeBase(string $typeName): string
    {
        if ($this->types !== null) {
            $type = $this->types->get($typeName);
            if ($type !== null && $type->rewriteBase !== null && trim($type->rewriteBase, '/') !== '') {
                return trim($type->rewriteBase, '/');
            }
        }

        return $typeName;
    }

    private function hierarchicalPagePath(Entry $entry): string
    {
        $segments = [rawurlencode($entry->slug)];
        $parentId = $entry->parentId;
        $visited = [$entry->id => true];
        $depth = 0;

        while ($parentId > 0 && $depth < 12 && !isset($visited[$parentId])) {
            $visited[$parentId] = true;
            $ancestor = $this->resolveAncestor($parentId);
            if ($ancestor === null) {
                break;
            }
            array_unshift($segments, rawurlencode($ancestor['slug']));
            $parentId = $ancestor['parent_id'];
            $depth++;
        }

        return '/' . implode('/', $segments);
    }

    /**
     * @return ?array{slug: string, parent_id: int}
     */
    private function resolveAncestor(int $entryId): ?array
    {
        if (isset($this->pageAncestorCache[$entryId])) {
            return $this->pageAncestorCache[$entryId];
        }

        if ($this->entries === null) {
            return null;
        }

        $parent = $this->entries->find($entryId, withRelations: false);
        if ($parent === null) {
            return null;
        }

        $this->pageAncestorCache[$entryId] = [
            'slug' => $parent->slug,
            'parent_id' => $parent->parentId,
        ];

        return $this->pageAncestorCache[$entryId];
    }
}
