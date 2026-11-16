<?php

declare(strict_types=1);

namespace Prime\Admin\Controller;

use Prime\Admin\AdminContext;
use Prime\Cache\ApcuCache;
use Prime\Cache\CacheManager;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\View\View;

/**
 * Admin controller for « Réglages › Performance » (`/admin/settings/performance`).
 *
 * Shows the data-cache driver, the page-cache toggle and TTL, live statistics, and the
 * "Vider tout le cache" action. Settings are persisted through the cache runtime config
 * file (see {@see \Prime\Cache\CacheServiceProvider}) so the change applies to the very
 * next request, before the database is even reachable.
 */
final class PerformanceController
{
    public function __construct(
        private readonly View $view,
        private readonly AdminContext $context,
        private readonly CacheManager $cache,
    ) {
    }

    /**
     * Performance settings (`GET /admin/settings/performance`).
     */
    public function index(Request $request): Response
    {
        return Response::html($this->view->render('admin::settings/performance', $this->context->build(
            $request,
            'Performance',
            [
                'stats' => $this->cache->stats(),
                'driver' => $this->cache->driverName(),
                'apcuAvailable' => ApcuCache::isAvailable(),
                'lastPurgedAt' => $this->lastPurgedAt(),
                'breadcrumbs' => [
                    ['label' => 'Tableau de bord', 'url' => '/admin'],
                    ['label' => 'Réglages', 'url' => '/admin/settings'],
                    ['label' => 'Performance', 'url' => null],
                ],
            ],
        )));
    }

    /**
     * Save the page-cache toggle and TTL (`POST /admin/settings/performance`).
     */
    public function save(Request $request): Response
    {
        $enabled = $request->body('page_enabled') !== null;
        $ttlRaw = $request->body('page_ttl', '600');
        $ttl = is_scalar($ttlRaw) ? (int) $ttlRaw : 600;

        if ($ttl < 60 || $ttl > 86400) {
            $this->context->flashToast('La durée de vie du cache de pages doit être comprise entre 60 et 86 400 secondes.', 'danger');

            return Response::redirect('/admin/settings/performance');
        }

        $this->cache->pages()->setEnabled($enabled);
        $this->cache->pages()->setDefaultTtl($ttl);

        $this->cache->data()->flush();
        $this->cache->pages()->purgeAll();

        $this->context->flashToast(
            $enabled
                ? sprintf('Cache de pages activé (%d secondes) ; caches vidés.', $ttl)
                : 'Cache de pages désactivé ; caches vidés.',
            'success',
        );

        return Response::redirect('/admin/settings/performance');
    }

    /**
     * Flush every cache (`POST /admin/settings/performance/purge`).
     */
    public function purge(Request $request): Response
    {
        $this->cache->clearAll();
        $this->context->flashToast('Tout le cache a été vidé.', 'success');

        return Response::redirect('/admin/settings/performance');
    }

    /**
     * Timestamp of the last flush, read from the page-cache purge marker.
     */
    private function lastPurgedAt(): ?int
    {
        return $this->cache->pages()->lastPurgedAt();
    }
}
