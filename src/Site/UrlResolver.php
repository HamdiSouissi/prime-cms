<?php

declare(strict_types=1);

namespace Prime\Site;

use DateTimeImmutable;
use DateTimeZone;
use Prime\Auth\UserRepository;
use Prime\Content\ContentType;
use Prime\Content\ContentTypeRegistry;
use Prime\Content\Entry;
use Prime\Content\EntryQuery;
use Prime\Content\EntryRepository;
use Prime\Content\TaxonomyRegistry;
use Prime\Content\TermRepository;
use Prime\Hooks\Hooks;
use Prime\Http\Request;

/**
 * Resolves any public URL path + query string into an immutable `PageContext`
 * covering all 8 Prime CMS site contexts, pagination (`/page/{n}`), 301 redirects,
 * and plugin extension via `site.resolve`, `site.context`, and `site.not_found`.
 */
final class UrlResolver
{
    public function __construct(
        private readonly PermalinkSettings $permalinks,
        private readonly UrlGenerator $urls,
        private readonly ContentTypeRegistry $types,
        private readonly TaxonomyRegistry $taxonomies,
        private readonly EntryRepository $entries,
        private readonly EntryQuery $query,
        private readonly TermRepository $terms,
        private readonly UserRepository $users,
        private readonly ?RedirectRepository $redirects = null,
        private readonly ?Hooks $hooks = null,
    ) {
    }

    /**
     * Resolve an incoming HTTP request into a `PageContext`.
     */
    public function resolve(Request $request): PageContext
    {
        $rawPath = $request->path();
        $path = '/' . trim($rawPath, '/');
        $queryParam = $request->query('q');
        $searchQuery = is_string($queryParam) ? trim($queryParam) : '';

        // Allow plugins to short-circuit or provide custom public routes via `site.resolve`
        if ($this->hooks !== null) {
            $custom = $this->hooks->apply('site.resolve', null, $request, $path);
            if ($custom instanceof PageContext) {
                $this->emitContextHooks($custom, $request);

                return $custom;
            }
        }

        // Extract optional trailing `/page/{n}` pagination suffix
        $page = 1;
        $basePath = $path;
        if (preg_match('#^(.*?)/page/(\d+)$#', $path, $m) === 1) {
            $pageNum = (int) $m[2];
            if ($pageNum < 1) {
                $ctx = PageContext::notFound($path);
                $this->emitContextHooks($ctx, $request);

                return $ctx;
            }
            $basePath = $m[1] === '' ? '/' : $m[1];
            $page = $pageNum;
        }

        $context = $this->resolveNormalized($path, $basePath, $page, $searchQuery);

        // If not found, check `pc_redirects` for an automatic 301 redirect
        if ($context->isNotFound() && $this->redirects !== null) {
            $rule = $this->redirects->findBySource($path);
            if ($rule !== null) {
                $this->redirects->recordHit($rule['id']);
                $context = PageContext::redirect($path, $rule['target_path'], $rule['status_code']);
            }
        }

        $this->emitContextHooks($context, $request);

        return $context;
    }

    /**
     * Check whether an entry is publicly visible (published AND `published_at` not in the future).
     */
    public static function isPubliclyVisible(Entry $entry, ?string $nowUtc = null): bool
    {
        if ($entry->status !== Entry::STATUS_PUBLISHED) {
            return false;
        }

        if ($entry->publishedAt !== null && $entry->publishedAt !== '') {
            $now = $nowUtc ?? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            if ($entry->publishedAt > $now) {
                return false;
            }
        }

        return true;
    }

    private function resolveNormalized(string $fullPath, string $basePath, int $page, string $searchQuery): PageContext
    {
        $perPage = $this->permalinks->postsPerPage();
        $segments = $basePath === '/' ? [] : explode('/', ltrim($basePath, '/'));

        // 1. Homepage (`/`, or `/page/{n}` when showing latest posts)
        if ($segments === []) {
            return $this->resolveHome($fullPath, $page, $perPage);
        }

        $first = strtolower(rawurldecode($segments[0]));

        // 2. Search (`/recherche` or `/recherche/page/{n}`)
        if ($first === $this->permalinks->searchBase() && count($segments) === 1) {
            return $this->resolveSearch($fullPath, $searchQuery, $page, $perPage);
        }

        // 3. Author archive (`/auteur/{username}`)
        if ($first === $this->permalinks->authorBase() && count($segments) === 2) {
            $username = rawurldecode($segments[1]);

            return $this->resolveAuthor($fullPath, $username, $page, $perPage);
        }

        // 4. Taxonomies (`/{categoryBase|tagBase|taxName}/{slug}`)
        if (count($segments) === 2) {
            foreach ($this->taxonomies->all() as $taxonomy) {
                if (!$taxonomy->public) {
                    continue;
                }
                if ($first === $this->permalinks->taxonomyBase($taxonomy->name)) {
                    $termSlug = rawurldecode($segments[1]);

                    return $this->resolveTerm($fullPath, $taxonomy->name, $termSlug, $page, $perPage);
                }
            }
        }

        // 5. Blog archive at `/blog` (when static front page has no custom posts page or `/blog` is hit)
        if ($first === 'blog' && count($segments) === 1) {
            return $this->resolveBlogListing($fullPath, $page, $perPage, entry: null);
        }

        // 6. Posts by configured permalink structure
        $postStructure = $this->permalinks->postStructure();
        if ($page === 1) {
            // 6a. `/blog/{slug}`
            if ($postStructure === PermalinkSettings::POST_BLOG_SLUG && $first === 'blog' && count($segments) === 2) {
                return $this->resolveSingleBySlug($fullPath, 'post', rawurldecode($segments[1]));
            }

            // 6b. `/{annee}/{mois}/{slug}`
            if (
                $postStructure === PermalinkSettings::POST_YEAR_MONTH_SLUG
                && count($segments) === 3
                && preg_match('/^\d{4}$/', $segments[0]) === 1
                && preg_match('/^(0[1-9]|1[0-2])$/', $segments[1]) === 1
            ) {
                $entry = $this->entries->findBySlug('post', rawurldecode($segments[2]), parentId: 0);
                if ($entry !== null && self::isPubliclyVisible($entry)) {
                    $dateSource = $entry->publishedAt ?? $entry->createdAt;
                    $ts = $dateSource !== '' ? strtotime($dateSource) : false;
                    $y = $ts !== false ? gmdate('Y', $ts) : '';
                    $m = $ts !== false ? gmdate('m', $ts) : '';
                    if ($y === $segments[0] && $m === $segments[1]) {
                        return $this->makeEntryContext($fullPath, $entry);
                    }
                }

                return PageContext::notFound($fullPath);
            }
        }

        // 7. Custom content types: archive `/{rewriteBase}` and single `/{rewriteBase}/{slug}`
        foreach ($this->types->all() as $type) {
            if (!$type->public || in_array($type->name, ['page', 'post'], true)) {
                continue;
            }
            $typeBase = strtolower($this->urls->contentTypeBase($type->name));
            if ($first !== $typeBase) {
                continue;
            }

            if (count($segments) === 1) {
                return $this->resolveArchive($fullPath, $type, $page, $perPage);
            }

            if (count($segments) === 2 && $page === 1) {
                return $this->resolveSingleBySlug($fullPath, $type->name, rawurldecode($segments[1]));
            }

            return PageContext::notFound($fullPath);
        }

        // 8. Static posts page (when configured under Reading settings)
        if ($this->permalinks->frontMode() === PermalinkSettings::FRONT_MODE_PAGE) {
            $postsPageId = $this->permalinks->postsPageId();
            if ($postsPageId !== null) {
                $postsPage = $this->entries->find($postsPageId);
                if ($postsPage !== null && self::isPubliclyVisible($postsPage)) {
                    $postsPagePath = '/' . trim($this->urls->entryUrl($postsPage), '/');
                    if ($basePath === $postsPagePath) {
                        return $this->resolveBlogListing($fullPath, $page, $perPage, entry: $postsPage);
                    }
                }
            }
        }

        // 9. Hierarchical pages (`/{slug}` or `/{parent}/{child}/...`)
        if ($page === 1) {
            $resolvedPage = $this->resolveHierarchicalPage($segments);
            if ($resolvedPage !== null) {
                // If this page is configured as the static front page, redirect `/accueil` -> `/`
                if (
                    $this->permalinks->frontMode() === PermalinkSettings::FRONT_MODE_PAGE
                    && $this->permalinks->frontPageId() === $resolvedPage->id
                ) {
                    return PageContext::redirect($fullPath, '/', 301);
                }

                return $this->makeEntryContext($fullPath, $resolvedPage);
            }
        }

        // 10. Root-level post permalink (`/{slug}`) when configured
        if ($page === 1 && $postStructure === PermalinkSettings::POST_SLUG_ONLY && count($segments) === 1) {
            return $this->resolveSingleBySlug($fullPath, 'post', rawurldecode($segments[0]));
        }

        return PageContext::notFound($fullPath);
    }

    private function resolveHome(string $fullPath, int $page, int $perPage): PageContext
    {
        if ($this->permalinks->frontMode() === PermalinkSettings::FRONT_MODE_PAGE) {
            if ($page > 1) {
                return PageContext::notFound($fullPath);
            }

            $frontId = $this->permalinks->frontPageId();
            if ($frontId !== null) {
                $frontEntry = $this->entries->find($frontId);
                if ($frontEntry !== null && $frontEntry->type === 'page' && self::isPubliclyVisible($frontEntry)) {
                    return new PageContext(
                        type: PageContext::TYPE_FRONT,
                        path: '/',
                        page: 1,
                        perPage: $perPage,
                        entry: $frontEntry,
                        contentType: $this->types->get('page'),
                    );
                }
            }
        }

        // Default homepage: latest published posts
        $result = $this->query->newQuery()
            ->type('post')
            ->orderBy('published_at', 'DESC')
            ->paginate($page, $perPage);

        if ($page > 1 && $result['items'] === []) {
            return PageContext::notFound($fullPath);
        }

        return new PageContext(
            type: PageContext::TYPE_FRONT,
            path: $fullPath,
            page: $result['page'],
            perPage: $result['per_page'],
            entries: $result['items'],
            totalEntries: $result['total'],
            totalPages: max(1, $result['total_pages']),
            contentType: $this->types->get('post'),
        );
    }

    private function resolveBlogListing(string $fullPath, int $page, int $perPage, ?Entry $entry): PageContext
    {
        $result = $this->query->newQuery()
            ->type('post')
            ->orderBy('published_at', 'DESC')
            ->paginate($page, $perPage);

        if ($page > 1 && $result['items'] === []) {
            return PageContext::notFound($fullPath);
        }

        return new PageContext(
            type: PageContext::TYPE_BLOG,
            path: $fullPath,
            page: $result['page'],
            perPage: $result['per_page'],
            entry: $entry,
            entries: $result['items'],
            totalEntries: $result['total'],
            totalPages: max(1, $result['total_pages']),
            contentType: $this->types->get('post'),
        );
    }

    private function resolveSingleBySlug(string $fullPath, string $typeName, string $slug): PageContext
    {
        $type = $this->types->get($typeName);
        if ($type === null || !$type->public) {
            return PageContext::notFound($fullPath);
        }

        $entry = $this->entries->findBySlug($typeName, $slug, parentId: 0);
        if ($entry === null || !self::isPubliclyVisible($entry)) {
            return PageContext::notFound($fullPath);
        }

        return $this->makeEntryContext($fullPath, $entry);
    }

    /**
     * Walk `/{parent}/{child}/...` segments strictly against published pages.
     *
     * @param list<string> $segments
     */
    private function resolveHierarchicalPage(array $segments): ?Entry
    {
        if ($segments === [] || count($segments) > 12) {
            return null;
        }

        $parentId = 0;
        $current = null;

        foreach ($segments as $seg) {
            $slug = rawurldecode($seg);
            $found = $this->entries->findBySlug('page', $slug, parentId: $parentId);
            if ($found === null || !self::isPubliclyVisible($found)) {
                return null;
            }
            $current = $found;
            $parentId = $found->id;
        }

        return $current;
    }

    private function makeEntryContext(string $fullPath, Entry $entry): PageContext
    {
        return new PageContext(
            type: PageContext::TYPE_ENTRY,
            path: $fullPath,
            page: 1,
            perPage: $this->permalinks->postsPerPage(),
            entry: $entry,
            contentType: $this->types->get($entry->type),
        );
    }

    private function resolveArchive(string $fullPath, ContentType $type, int $page, int $perPage): PageContext
    {
        $result = $this->query->newQuery()
            ->type($type->name)
            ->orderBy('published_at', 'DESC')
            ->paginate($page, $perPage);

        if ($page > 1 && $result['items'] === []) {
            return PageContext::notFound($fullPath);
        }

        return new PageContext(
            type: PageContext::TYPE_ARCHIVE,
            path: $fullPath,
            page: $result['page'],
            perPage: $result['per_page'],
            entries: $result['items'],
            totalEntries: $result['total'],
            totalPages: max(1, $result['total_pages']),
            contentType: $type,
        );
    }

    private function resolveTerm(string $fullPath, string $taxonomyName, string $termSlug, int $page, int $perPage): PageContext
    {
        $taxonomy = $this->taxonomies->get($taxonomyName);
        if ($taxonomy === null || !$taxonomy->public) {
            return PageContext::notFound($fullPath);
        }

        $term = $this->terms->findBySlug($taxonomyName, $termSlug);
        if ($term === null) {
            return PageContext::notFound($fullPath);
        }

        $publicTypes = [];
        foreach ($taxonomy->contentTypes as $ctName) {
            $ct = $this->types->get($ctName);
            if ($ct !== null && $ct->public) {
                $publicTypes[] = $ct->name;
            }
        }
        if ($publicTypes === []) {
            $publicTypes = ['post'];
        }

        $result = $this->query->newQuery()
            ->type($publicTypes)
            ->whereTerm($taxonomyName, $term->id)
            ->orderBy('published_at', 'DESC')
            ->paginate($page, $perPage);

        if ($page > 1 && $result['items'] === []) {
            return PageContext::notFound($fullPath);
        }

        return new PageContext(
            type: PageContext::TYPE_TERM,
            path: $fullPath,
            page: $result['page'],
            perPage: $result['per_page'],
            entries: $result['items'],
            totalEntries: $result['total'],
            totalPages: max(1, $result['total_pages']),
            taxonomy: $taxonomy,
            term: $term,
        );
    }

    private function resolveAuthor(string $fullPath, string $username, int $page, int $perPage): PageContext
    {
        $author = $this->users->findByAuthorSlug($username);
        if ($author === null || !$author->isActive()) {
            return PageContext::notFound($fullPath);
        }

        $result = $this->query->newQuery()
            ->type('post')
            ->author($author->id)
            ->orderBy('published_at', 'DESC')
            ->paginate($page, $perPage);

        if ($page > 1 && $result['items'] === []) {
            return PageContext::notFound($fullPath);
        }

        return new PageContext(
            type: PageContext::TYPE_AUTHOR,
            path: $fullPath,
            page: $result['page'],
            perPage: $result['per_page'],
            entries: $result['items'],
            totalEntries: $result['total'],
            totalPages: max(1, $result['total_pages']),
            author: $author,
        );
    }

    private function resolveSearch(string $fullPath, string $searchQuery, int $page, int $perPage): PageContext
    {
        if ($searchQuery === '') {
            return new PageContext(
                type: PageContext::TYPE_SEARCH,
                path: $fullPath,
                page: 1,
                perPage: $perPage,
                entries: [],
                totalEntries: 0,
                totalPages: 1,
                searchQuery: '',
            );
        }

        $publicTypes = [];
        foreach ($this->types->all() as $type) {
            if ($type->public) {
                $publicTypes[] = $type->name;
            }
        }
        if ($publicTypes === []) {
            $publicTypes = ['post', 'page'];
        }

        $result = $this->query->newQuery()
            ->type($publicTypes)
            ->search($searchQuery)
            ->orderBy('published_at', 'DESC')
            ->paginate($page, $perPage);

        if ($page > 1 && $result['items'] === []) {
            return PageContext::notFound($fullPath);
        }

        return new PageContext(
            type: PageContext::TYPE_SEARCH,
            path: $fullPath,
            page: $result['page'],
            perPage: $result['per_page'],
            entries: $result['items'],
            totalEntries: $result['total'],
            totalPages: max(1, $result['total_pages']),
            searchQuery: $searchQuery,
        );
    }

    private function emitContextHooks(PageContext $context, Request $request): void
    {
        if ($this->hooks === null) {
            return;
        }

        $this->hooks->emit('site.context', $context, $request);
        if ($context->isNotFound() && !$context->isRedirect()) {
            $this->hooks->emit('site.not_found', $context, $request);
        }
    }
}
