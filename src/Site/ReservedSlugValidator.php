<?php

declare(strict_types=1);

namespace Prime\Site;

use Prime\Content\ContentTypeRegistry;
use Prime\Content\TaxonomyRegistry;
use Prime\Http\Router;

/**
 * Computes all reserved top-level URL slugs that cannot be claimed by root pages
 * or root-level post permalinks (`/{slug}`), preventing route collisions.
 */
final class ReservedSlugValidator
{
    /**
     * Static list of core system routes and reserved public endpoints.
     *
     * @var list<string>
     */
    public const SYSTEM_RESERVED = [
        'admin',
        'uploads',
        'assets',
        'install',
        'sitemap.xml',
        'sitemap',
        'robots.txt',
        'robots',
        'health',
        'login',
        'api',
        'content',
        'page',
        'blog',
    ];

    public function __construct(
        private readonly ?PermalinkSettings $permalinks = null,
        private readonly ?ContentTypeRegistry $types = null,
        private readonly ?TaxonomyRegistry $taxonomies = null,
        private readonly ?Router $router = null,
    ) {
    }

    /**
     * Return all lowercase reserved first-level segments.
     *
     * @return list<string>
     */
    public function reservedSlugs(): array
    {
        $reserved = self::SYSTEM_RESERVED;

        $permalinks = $this->permalinks ?? new PermalinkSettings();
        foreach ([
            $permalinks->categoryBase(),
            $permalinks->tagBase(),
            $permalinks->authorBase(),
            $permalinks->searchBase(),
        ] as $base) {
            if ($base !== '' && !in_array($base, $reserved, true)) {
                $reserved[] = $base;
            }
        }

        if ($this->types !== null) {
            foreach ($this->types->all() as $type) {
                if (!$type->public || in_array($type->name, ['page', 'post'], true)) {
                    continue;
                }
                $base = strtolower(trim($type->rewriteBase ?? $type->name, '/'));
                if ($base !== '' && !in_array($base, $reserved, true)) {
                    $reserved[] = $base;
                }
            }
        }

        if ($this->taxonomies !== null) {
            foreach ($this->taxonomies->all() as $taxonomy) {
                if (!$taxonomy->public) {
                    continue;
                }
                $base = strtolower($permalinks->taxonomyBase($taxonomy->name));
                if ($base !== '' && !in_array($base, $reserved, true)) {
                    $reserved[] = $base;
                }
            }
        }

        if ($this->router !== null) {
            foreach ($this->router->routes() as $route) {
                $pattern = ltrim($route->path(), '/');
                if ($pattern === '') {
                    continue;
                }
                $first = strtolower(trim(explode('/', $pattern, 2)[0]));
                if ($first !== '' && !str_contains($first, '{') && !in_array($first, $reserved, true)) {
                    $reserved[] = $first;
                }
            }
        }

        return $reserved;
    }

    /**
     * Check whether `$slug` conflicts with a reserved top-level route.
     */
    public function isReserved(string $slug): bool
    {
        $clean = strtolower(trim($slug, " /\t\n\r"));
        if ($clean === '') {
            return false;
        }

        // Also block `sitemap-*.xml` patterns
        if (str_starts_with($clean, 'sitemap-') && str_ends_with($clean, '.xml')) {
            return true;
        }

        return in_array($clean, $this->reservedSlugs(), true);
    }

    /**
     * Determine whether an entry of `$type` with `$parentId` would sit at the URL root
     * and therefore must not collide with any reserved segment.
     */
    public function appliesToEntry(string $type, int $parentId): bool
    {
        if ($type === 'page' && $parentId === 0) {
            return true;
        }

        if ($type === 'post') {
            $permalinks = $this->permalinks ?? new PermalinkSettings();

            return $permalinks->postStructure() === PermalinkSettings::POST_SLUG_ONLY;
        }

        return false;
    }
}
