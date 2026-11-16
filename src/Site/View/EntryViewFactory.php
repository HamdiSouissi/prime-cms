<?php

declare(strict_types=1);

namespace Prime\Site\View;

use Prime\Auth\UserRepository;
use Prime\Content\Entry;
use Prime\Content\EntryQuery;
use Prime\Media\MediaRepository;
use Prime\Site\UrlGenerator;
use Prime\Site\Zone\DynamicBlockRegistry;
use Prime\Site\Zone\ZoneRepository;

/**
 * Batch-hydrates lists of `Entry` objects into `EntryView`s in constant query count
 * (1 query for authors + 1 query for featured images & author avatars) — zero N+1!
 */
final class EntryViewFactory
{
    public function __construct(
        private readonly UrlGenerator $urls,
        private readonly ?UserRepository $users = null,
        private readonly ?MediaRepository $media = null,
        private readonly ?EntryQuery $query = null,
        private readonly ?ZoneRepository $zones = null,
        private readonly ?DynamicBlockRegistry $dynamicBlocks = null,
    ) {
    }

    /**
     * Batch-hydrate a list of entries into `EntryView` wrappers.
     *
     * @param list<Entry> $entries
     * @return list<EntryView>
     */
    public function makeMany(array $entries): array
    {
        if ($entries === []) {
            return [];
        }

        $this->urls->primePageCache($entries);

        // Collect all unique author IDs and featured image IDs
        $authorIds = [];
        $mediaIds = [];

        foreach ($entries as $entry) {
            if ($entry->authorId > 0) {
                $authorIds[$entry->authorId] = true;
            }
            $featured = $entry->meta('featured_image');
            if (is_numeric($featured) && (int) $featured > 0) {
                $mediaIds[(int) $featured] = true;
            }
        }

        $usersMap = ($this->users !== null && $authorIds !== [])
            ? $this->users->findByIds(array_keys($authorIds))
            : [];

        // Also batch-collect author avatar media IDs into the SAME single media query!
        foreach ($usersMap as $user) {
            if ($user->avatarMediaId !== null && $user->avatarMediaId > 0) {
                $mediaIds[$user->avatarMediaId] = true;
            }
        }

        $mediaMap = ($this->media !== null && $mediaIds !== [])
            ? $this->media->findByIds(array_keys($mediaIds))
            : [];

        $views = [];
        foreach ($entries as $entry) {
            $author = $usersMap[$entry->authorId] ?? null;
            $featuredId = $entry->meta('featured_image');
            $featuredMedia = (is_numeric($featuredId) && (int) $featuredId > 0)
                ? ($mediaMap[(int) $featuredId] ?? null)
                : null;
            $avatarMedia = ($author !== null && $author->avatarMediaId !== null)
                ? ($mediaMap[$author->avatarMediaId] ?? null)
                : null;

            $views[] = new EntryView(
                entry: $entry,
                urls: $this->urls,
                authorUser: $author,
                featuredMedia: $featuredMedia,
                authorAvatarMedia: $avatarMedia,
                query: $this->query,
                factory: $this,
                zones: $this->zones,
                dynamicBlocks: $this->dynamicBlocks,
            );
        }

        return $views;
    }

    public function makeOne(Entry $entry): EntryView
    {
        return $this->makeMany([$entry])[0];
    }
}
