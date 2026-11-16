<?php

declare(strict_types=1);

namespace Prime\Site\Seo;

use Prime\Content\ContentTypeRegistry;
use Prime\Content\Entry;
use Prime\Content\EntryQuery;
use Prime\Content\TaxonomyRegistry;
use Prime\Content\TermRepository;
use Prime\Hooks\Hooks;
use Prime\Media\Media;
use Prime\Media\MediaRepository;
use Prime\Site\UrlGenerator;

/**
 * Generates dynamic XML sitemaps (`/sitemap.xml` index + paginated `/sitemap-{type}-{n}.xml`)
 * strictly excluding drafts, future/private/trashed entries, and `noindex` entries,
 * including `<image:image>` elements (featured image, `image` and `gallery` blocks),
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
        private readonly ?MediaRepository $media = null,
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
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

        foreach ($slice as $row) {
            $loc = $row['loc'];
            if ($loc === '') {
                continue;
            }
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . $this->xmlEscape($loc) . "</loc>\n";
            if (is_string($row['lastmod'] ?? null) && $row['lastmod'] !== '') {
                $xml .= '    <lastmod>' . $this->xmlEscape($row['lastmod']) . "</lastmod>\n";
            }
            if (isset($row['images'])) {
                foreach ($row['images'] as $img) {
                    $imgLoc = trim($img['loc']);
                    if ($imgLoc === '') {
                        continue;
                    }
                    $xml .= "    <image:image>\n";
                    $xml .= '      <image:loc>' . $this->xmlEscape($imgLoc) . "</image:loc>\n";
                    $imgTitle = trim($img['title'] ?? '');
                    if ($imgTitle !== '') {
                        $xml .= '      <image:title>' . $this->xmlEscape($imgTitle) . "</image:title>\n";
                    }
                    $imgCaption = trim($img['caption'] ?? '');
                    if ($imgCaption !== '') {
                        $xml .= '      <image:caption>' . $this->xmlEscape($imgCaption) . "</image:caption>\n";
                    }
                    $xml .= "    </image:image>\n";
                }
            }
            $xml .= "  </url>\n";
        }

        $xml .= "</urlset>\n";

        return $xml;
    }

    /**
     * Build the provider map (`slug => fn(): list<array{loc: string, lastmod?: string, images?: list<array{loc: string, title?: string, caption?: string}>}>`).
     *
     * @return array<string, callable(): list<array{loc: string, lastmod?: string, images?: list<array{loc: string, title?: string, caption?: string}>}>>
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
                $mediaMap = $this->preloadMediaForEntries($entries);

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
                    $images = $this->extractEntryImages($entry, $mediaMap);
                    if ($images !== []) {
                        $item['images'] = $images;
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

    /**
     * Batch-preload all image media referenced by entries (featured image, `image` & `gallery` blocks).
     *
     * @param list<Entry> $entries
     * @return array<int, Media>
     */
    private function preloadMediaForEntries(array $entries): array
    {
        if ($this->media === null || $entries === []) {
            return [];
        }

        $ids = [];
        foreach ($entries as $entry) {
            $featured = $entry->featuredMediaId();
            if ($featured !== null) {
                $ids[$featured] = true;
            }

            $doc = $entry->decodedContent();
            foreach ($doc['blocks'] as $block) {
                $type = $block['type'];
                $data = $block['data'];

                if ($type === 'image') {
                    $mId = $data['mediaId'] ?? ($data['media_id'] ?? null);
                    if (is_numeric($mId) && (int) $mId > 0) {
                        $ids[(int) $mId] = true;
                    }
                } elseif ($type === 'gallery') {
                    $items = is_array($data['items'] ?? null)
                        ? $data['items']
                        : (is_array($data['images'] ?? null) ? $data['images'] : []);
                    foreach ($items as $galleryItem) {
                        if (!is_array($galleryItem)) {
                            continue;
                        }
                        $mId = $galleryItem['mediaId'] ?? ($galleryItem['media_id'] ?? null);
                        if (is_numeric($mId) && (int) $mId > 0) {
                            $ids[(int) $mId] = true;
                        }
                    }
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        return $this->media->findByIds(array_keys($ids));
    }

    /**
     * Extract deduplicated `<image:image>` items from an entry (featured image + `image` & `gallery` blocks).
     *
     * @param array<int, Media> $mediaMap
     * @return list<array{loc: string, title?: string, caption?: string}>
     */
    private function extractEntryImages(Entry $entry, array $mediaMap): array
    {
        /** @var array<string, true> $seen */
        $seen = [];
        /** @var list<array{loc: string, title?: string, caption?: string}> $images */
        $images = [];

        // 1. Featured image
        $featured = $entry->featuredMediaId();
        if ($featured !== null) {
            $media = $mediaMap[$featured] ?? null;
            if ($media !== null && $media->isImage()) {
                $featCaption = ($media->caption !== null && $media->caption !== '')
                    ? $media->caption
                    : $media->alt;
                $item = $this->buildImageNode(
                    $media->url(),
                    $media->title !== '' ? $media->title : $entry->title,
                    $featCaption,
                    $seen,
                );
                if ($item !== null) {
                    $images[] = $item;
                }
            }
        }

        // 2. `image` and `gallery` blocks
        $doc = $entry->decodedContent();
        foreach ($doc['blocks'] as $block) {
            $type = $block['type'];
            $data = $block['data'];

            if ($type === 'image') {
                $mId = $data['mediaId'] ?? ($data['media_id'] ?? null);
                $media = (is_numeric($mId) && (int) $mId > 0) ? ($mediaMap[(int) $mId] ?? null) : null;
                $rawUrl = ($media !== null && $media->isImage())
                    ? $media->url()
                    : (is_string($data['url'] ?? null) ? trim($data['url']) : '');
                $alt = is_string($data['alt'] ?? null) ? $data['alt'] : ($media?->alt ?? '');
                $caption = is_string($data['caption'] ?? null) ? $data['caption'] : ($media?->caption ?? '');
                $item = $this->buildImageNode($rawUrl, $alt, $caption, $seen);
                if ($item !== null) {
                    $images[] = $item;
                }
            } elseif ($type === 'gallery') {
                $items = is_array($data['items'] ?? null)
                    ? $data['items']
                    : (is_array($data['images'] ?? null) ? $data['images'] : []);
                foreach ($items as $galleryItem) {
                    if (!is_array($galleryItem)) {
                        continue;
                    }
                    $mId = $galleryItem['mediaId'] ?? ($galleryItem['media_id'] ?? null);
                    $media = (is_numeric($mId) && (int) $mId > 0) ? ($mediaMap[(int) $mId] ?? null) : null;
                    $rawUrl = ($media !== null && $media->isImage())
                        ? $media->url()
                        : (is_string($galleryItem['url'] ?? null) ? trim($galleryItem['url']) : '');
                    $alt = is_string($galleryItem['alt'] ?? null) ? $galleryItem['alt'] : ($media?->alt ?? '');
                    $caption = is_string($galleryItem['caption'] ?? null) ? $galleryItem['caption'] : ($media?->caption ?? '');
                    $item = $this->buildImageNode($rawUrl, $alt, $caption, $seen);
                    if ($item !== null) {
                        $images[] = $item;
                    }
                }
            }
        }

        return $images;
    }

    /**
     * @param array<string, true> $seen
     * @return ?array{loc: string, title?: string, caption?: string}
     */
    private function buildImageNode(string $rawUrl, string $title, string $caption, array &$seen): ?array
    {
        $trimmed = trim($rawUrl);
        if ($trimmed === '') {
            return null;
        }

        $loc = preg_match('#^https?://#i', $trimmed) === 1
            ? $trimmed
            : $this->urls->absolute($trimmed);
        if ($loc === '' || isset($seen[$loc])) {
            return null;
        }

        $seen[$loc] = true;

        $item = ['loc' => $loc];
        $cleanTitle = trim(strip_tags($title));
        $cleanCaption = trim(strip_tags($caption));
        if ($cleanTitle !== '') {
            $item['title'] = $cleanTitle;
        }
        if ($cleanCaption !== '') {
            $item['caption'] = $cleanCaption;
        }

        return $item;
    }

    private function xmlEscape(string $val): string
    {
        return htmlspecialchars($val, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
