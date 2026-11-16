<?php

declare(strict_types=1);

namespace Prime\Site\Seo;

use Prime\Content\ContentTypeRegistry;
use Prime\Content\EntryQuery;
use Prime\Content\TaxonomyRegistry;
use Prime\Content\TermRepository;
use Prime\Hooks\Hooks;
use Prime\Site\UrlGenerator;

/**
 * Generates dynamic XML sitemaps (`/sitemap.xml` index + paginated `/sitemap-{type}-{n}.xml`)
 * strictly excluding drafts, future/private/trashed entries, and `noindex` entries,
 * with plugin extensibility via the `seo.sitemap_providers` filter.
 */
final class SitemapGenerator
{
    public const MAX_URLS_PER_PAGE = 5000;

    public function __construct(
        private readonly ContentTypeRegistry $types,
        private readonly TaxonomyRegistry $taxonomies,
        private readonly EntryQuery $query,
        private readonly TermRepository $terms,
        private readonly UrlGenerator $urls,
        private readonly ?Hooks $hooks = null,
    ) {
    }

    /**
     * Render the root `/sitemap.xml` sitemapindex listing all active sub-sitemaps.
     */
    public function renderIndex(): string
    {
        $providers = $this->providers();
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($providers as $typeKey => $provider) {
            $entries = $provider();
            $total = count($entries);
            if ($total === 0) {
                continue;
            }
            $pages = (int) ceil($total / self::MAX_URLS_PER_PAGE);
            for ($p = 1; $p <= $pages; $p++) {
                $loc = $this->urls->absolute(sprintf('/sitemap-%s-%d.xml', $typeKey, $p));
                $lastmod = $entries[0]['lastmod'] ?? null;
                $xml .= "  <sitemap>\n";
                $xml .= '    <loc>' . $this->xmlEscape($loc) . "</loc>\n";
                if (is_string($lastmod) && $lastmod !== '') {
                    $xml .= '    <lastmod>' . $this->xmlEscape($lastmod) . "</lastmod>\n";
                }
                $xml .= "  </sitemap>\n";
            }
        }

        $xml .= "</sitemapindex>\n";

        return $xml;
    }

    /**
     * Render a paginated sub-sitemap `/sitemap-{type}-{page}.xml` (returns null when unknown or empty).
     */
    public function renderSubSitemap(string $typeKey, int $page): ?string
    {
        if ($page < 1) {
            return null;
        }

        $providers = $this->providers();
        if (!isset($providers[$typeKey])) {
            return null;
        }

        $allUrls = ($providers[$typeKey])();
        $offset = ($page - 1) * self::MAX_URLS_PER_PAGE;
        $slice = array_slice($allUrls, $offset, self::MAX_URLS_PER_PAGE);

        if ($slice === []) {
            return null;
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($slice as $row) {
            $loc = (string) ($row['loc'] ?? '');
            if ($loc === '') {
                continue;
            }
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . $this->xmlEscape($loc) . "</loc>\n";
            if (is_string($row['lastmod'] ?? null) && $row['lastmod'] !== '') {
                $xml .= '    <lastmod>' . $this->xmlEscape($row['lastmod']) . "</lastmod>\n";
            }
            $xml .= "  </url>\n";
        }

        $xml .= "</urlset>\n";

        return $xml;
    }

    /**
     * Build the provider map (`slug => fn(): list<array{loc: string, lastmod?: string}>`).
     *
     * @return array<string, callable(): list<array{loc: string, lastmod?: string}>>
     */
    private function providers(): array
    {
        $providers = [];

        // Public content types (post, page, CPTs)
        foreach ($this->types->all() as $type) {
            if (!$type->public) {
                continue;
            }
            $typeName = $type->name;
            $providers[$typeName] = function () use ($typeName): array {
                $entries = $this->query->newQuery()
                    ->type($typeName)
                    ->orderBy('updated_at', 'DESC')
                    ->get();

                $this->urls->primePageCache($entries);

                $list = [];
                foreach ($entries as $entry) {
                    if (SeoManager::entryIsNoindex($entry)) {
                        continue;
                    }
                    $lastmod = $entry->updatedAt !== ''
                        ? gmdate('c', strtotime($entry->updatedAt) ?: time())
                        : null;
                    $item = ['loc' => $this->urls->absolute($this->urls->entryUrl($entry))];
                    if ($lastmod !== null) {
                        $item['lastmod'] = $lastmod;
                    }
                    $list[] = $item;
                }

                return $list;
            };
        }

        // Public taxonomies (only terms with at least 1 published entry)
        foreach ($this->taxonomies->all() as $taxonomy) {
            if (!$taxonomy->public) {
                continue;
            }
            $taxName = $taxonomy->name;
            $providers['tax-' . $taxName] = function () use ($taxName): array {
                $list = [];
                foreach ($this->terms->allForTaxonomy($taxName) as $term) {
                    if ($term->count <= 0) {
                        continue;
                    }
                    $list[] = [
                        'loc' => $this->urls->absolute($this->urls->termUrl($term)),
                    ];
                }

                return $list;
            };
        }

        if ($this->hooks !== null) {
            $filtered = $this->hooks->apply('seo.sitemap_providers', $providers);
            if (is_array($filtered)) {
                $clean = [];
                foreach ($filtered as $key => $cb) {
                    if (is_string($key) && preg_match('/^[a-z0-9_-]{1,64}$/', $key) === 1 && is_callable($cb)) {
                        $clean[$key] = $cb;
                    }
                }
                if ($clean !== []) {
                    $providers = $clean;
                }
            }
        }

        return $providers;
    }

    private function xmlEscape(string $val): string
    {
        return htmlspecialchars($val, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
