<?php

declare(strict_types=1);

namespace Prime\Cache;

use Prime\Content\Entry;
use Prime\Hooks\Hooks;

/**
 * Subscribes to content, media, and theme events in order to purge only the affected page-cache entries.
 *
 * A published entry invalidates its own URL, the site home page, and every archive prefix it can
 * appear on (date, taxonomy, author, feed) — never the whole cache.
 */
final class CachePurgeSubscriber
{
    /**
     * Archive path prefixes affected by any content change.
     *
     * @var list<string>
     */
    private const ARCHIVE_PREFIXES = [
        '/blog',
        '/page',
        '/feed',
        '/categorie',
        '/category',
        '/etiquette',
        '/tag',
        '/auteur',
        '/author',
        '/archive',
    ];

    public function __construct(
        private readonly PageCache $pages,
        private readonly CacheManager $manager,
    ) {
    }

    public function register(Hooks $hooks): void
    {
        $contentEvents = [
            'content.saved',
            'content.updated',
            'content.published',
            'content.deleted',
            'content.trashed',
            'content.restored',
            'content.status_changed',
        ];

        foreach ($contentEvents as $event) {
            $hooks->on($event, function (mixed ...$args): void {
                $this->purgeForEntry($args[0] ?? null);
            });
        }

        // Navigation caches (menus, custom menus, widgets) are rebuilt from the database.
        $navEvents = ['menu.updated', 'menu.item_created', 'menu.item_deleted', 'settings.updated', 'customize.saved'];
        foreach ($navEvents as $event) {
            $hooks->on($event, function (): void {
                $this->manager->namespace('nav')->flush();
                $this->manager->namespace('menus')->flush();
                $this->manager->namespace('zones')->flush();
            });
        }

        // Media alt/caption changes are reflected in already-rendered pages.
        $hooks->on('media.updated', function (): void {
            $this->manager->namespace('media')->flush();
        });

        $hooks->on('theme.activated', function (): void {
            $this->pages->purgeAll();
            $this->manager->namespace('themes')->flush();
        });
    }

    /**
     * Purge cached pages related to a single content entry.
     */
    public function purgeForEntry(mixed $entry): void
    {
        if (!$entry instanceof Entry) {
            return;
        }

        $exact = ['/'];
        $url = $entry->url();
        if (is_string($url) && $url !== '') {
            $exact[] = $url;
        }

        $this->pages->purgePaths($exact, self::ARCHIVE_PREFIXES);
        $this->manager->namespace('content')->flush();
        $this->manager->namespace('sitemap')->flush();
    }
}
