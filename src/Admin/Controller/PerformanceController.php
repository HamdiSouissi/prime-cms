<?php

declare(strict_types=1);

namespace Prime\Admin\Controller;

use Prime\Admin\AdminContext;
use Prime\Admin\TranslatesAdminCopy;
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
    use TranslatesAdminCopy;

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
                    ['label' => $this->t('admin.ctrl_text_performance.tableau_de_bord'), 'url' => '/admin'],
                    ['label' => t('admin.nav.settings'), 'url' => '/admin/settings'],
                    ['label' => t('admin.nav.performance'), 'url' => null],
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
            $this->context->flashToast($this->t('admin.ctrl_text_performance.la_duree_de_vie_du_cache_de_pages_doit_etre_comprise_entre_6'), 'danger');

            return Response::redirect('/admin/settings/performance');
        }

        $this->cache->pages()->setEnabled($enabled);
        $this->cache->pages()->setDefaultTtl($ttl);

        $this->cache->data()->flush();
        $this->cache->pages()->purgeAll();

        $this->context->flashToast(
            $enabled
                ? sprintf($this->t('admin.ctrl_text_performance.cache_de_pages_active_d_secondes_caches_vides'), $ttl)
                : $this->t('admin.ctrl_text_performance.cache_de_pages_desactive_caches_vides'),
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
        $this->context->flashToast($this->t('admin.ctrl_text_performance.tout_le_cache_a_ete_vide'), 'success');

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
