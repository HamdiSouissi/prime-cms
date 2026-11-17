<?php

declare(strict_types=1);

namespace Prime\Admin\Controller;

use InvalidArgumentException;
use Prime\Admin\AdminContext;
use Prime\Admin\TranslatesAdminCopy;
use Prime\Auth\Auth;
use Prime\Editor\BlockRegistry;
use Prime\Editor\Renderer;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Site\Zone\DynamicBlockRegistry;
use Prime\Site\Zone\ZoneRepository;
use Prime\Themes\ThemeManager;
use Prime\View\View;

/**
 * Admin controller for « Apparence › Zones » (`themes.manage`) powered by Prime Editor.
 */
final class ZoneController
{
    use TranslatesAdminCopy;

    public function __construct(
        private readonly View $view,
        private readonly AdminContext $context,
        private readonly Auth $auth,
        private readonly ThemeManager $themes,
        private readonly ZoneRepository $zones,
        private readonly DynamicBlockRegistry $dynamicBlocks,
        private readonly BlockRegistry $blocks,
        private readonly Renderer $renderer,
    ) {
    }

    /**
     * Display theme zones & Prime Editor for the selected zone (`GET /admin/appearance/zones`).
     */
    public function index(Request $request): Response
    {
        $declaredZones = $this->themes->zones();
        $savedZones = $this->zones->allIndexed();

        $selectedSlug = is_string($request->query('zone')) ? trim((string) $request->query('zone')) : '';
        if ($selectedSlug === '' || !isset($declaredZones[$selectedSlug])) {
            $selectedSlug = (string) (array_key_first($declaredZones) ?? '');
        }

        $zoneContentJson = $selectedSlug !== ''
            ? $this->renderer->encode($this->zones->document($selectedSlug))
            : $this->renderer->encode(Renderer::emptyDocument());

        $user = $this->auth->user();
        $editorConfig = [
            'blocks' => $this->blocks->clientDefinitions($user),
            'canUnfilteredHtml' => $user !== null && $user->can('content.unfiltered_html'),
            'mediaApiUrl' => '/admin/media/api/items',
            'mediaUploadUrl' => '/admin/media/api/upload',
            'dynamicBlocks' => $this->dynamicBlocks->catalog(),
        ];

        $zoneCounts = [];
        foreach ($declaredZones as $zSlug => $_meta) {
            $zoneCounts[$zSlug] = count($this->zones->document($zSlug)['blocks']);
        }

        $selectedZone = $declaredZones[$selectedSlug] ?? null;

        $html = $this->view->render('admin::appearance/zones', $this->context->build($request, 'Zones', [
            'declaredZones' => $declaredZones,
            'savedZones' => $savedZones,
            'zoneCounts' => $zoneCounts,
            'selectedSlug' => $selectedSlug,
            'selectedZone' => $selectedZone,
            'zoneContentJson' => $zoneContentJson,
            'editorConfigJson' => json_encode($editorConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'dynamicCatalog' => $this->dynamicBlocks->catalog(),
            'primaryCtaInContent' => $selectedZone !== null,
            'breadcrumbs' => [
                ['label' => $this->t('admin.ctrl_text_zone.tableau_de_bord'), 'url' => '/admin'],
                ['label' => 'Apparence', 'url' => '/admin/appearance/themes'],
                ['label' => 'Zones', 'url' => null],
            ],
        ]));

        return Response::html($html);
    }

    /**
     * Save a zone's Prime Editor document (`POST /admin/appearance/zones/{slug}/save`).
     */
    public function save(Request $request, string $slug): Response
    {
        $declaredZones = $this->themes->zones();
        if (!isset($declaredZones[$slug])) {
            if ($request->wantsJson()) {
                return Response::json(['ok' => false, 'message' => $this->t('admin.ctrl_text_zone.zone_inconnue_pour_le_theme_actif')], 404);
            }
            $this->context->flashToast($this->t('admin.ctrl_text_zone.zone_inconnue_pour_le_theme_actif'), 'danger');

            return Response::redirect('/admin/appearance/zones');
        }

        $body = $request->body();
        $payload = is_array($body) ? $body : [];
        if ($payload === [] && $request->rawBody() !== '') {
            $dec = json_decode($request->rawBody(), true);
            if (is_array($dec)) {
                $payload = $dec;
            }
        }

        $rawContent = $payload['content_json'] ?? ($payload['content'] ?? Renderer::emptyDocument());
        $user = $this->auth->user();
        $allowUnfiltered = $user !== null && $user->can('content.unfiltered_html');

        try {
            $saved = $this->zones->save(
                $slug,
                is_string($rawContent) || is_array($rawContent) ? $rawContent : Renderer::emptyDocument(),
                allowUnfilteredHtml: $allowUnfiltered,
            );
        } catch (InvalidArgumentException $e) {
            if ($request->wantsJson()) {
                return Response::json(['ok' => false, 'message' => $e->getMessage()], 422);
            }
            $this->context->flashToast($e->getMessage(), 'danger');

            return Response::redirect('/admin/appearance/zones?zone=' . rawurlencode($slug));
        }

        if ($request->wantsJson()) {
            return Response::json([
                'ok' => true,
                'message' => $this->t('admin.ctrl_text_zone.zone_enregistree_avec_succes'),
                'block_count' => $saved['block_count'],
            ]);
        }

        $this->context->flashToast(t('admin.appearance.zones.saved', [
            'zone' => t((string) $declaredZones[$slug]['label']),
        ]), 'success');

        return Response::redirect('/admin/appearance/zones?zone=' . rawurlencode($slug));
    }
}
