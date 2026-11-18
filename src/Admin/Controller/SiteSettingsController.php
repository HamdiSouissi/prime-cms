<?php

declare(strict_types=1);

namespace Prime\Admin\Controller;

use InvalidArgumentException;
use Prime\Admin\AdminContext;
use Prime\Admin\TranslatesAdminCopy;
use Prime\Content\EntryQuery;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Media\MediaRepository;
use Prime\Site\PermalinkSettings;
use Prime\Site\RedirectRepository;
use Prime\Site\Seo\SeoSettings;
use Prime\View\View;

/**
 * Admin controller for Phase 8 site settings (`settings.manage`):
 * - « Réglages › Lecture » (`/admin/settings/reading`)
 * - « Réglages › Permaliens » (`/admin/settings/permalinks` + 301 redirects)
 * - « Réglages › Référencement » (`/admin/settings/seo`)
 */
final class SiteSettingsController
{
    use TranslatesAdminCopy;

    public function __construct(
        private readonly View $view,
        private readonly AdminContext $context,
        private readonly PermalinkSettings $permalinks,
        private readonly RedirectRepository $redirects,
        private readonly SeoSettings $seoSettings,
        private readonly EntryQuery $query,
        private readonly ?MediaRepository $media = null,
    ) {
    }

    /**
     * Reading settings (`GET /admin/settings/reading`).
     */
    public function reading(Request $request): Response
    {
        $pages = $this->query->newQuery()->type('page')->orderBy('title', 'ASC')->limit(200)->get();

        $html = $this->view->render('admin::settings/reading', $this->context->build($request, t('admin.nav.reading'), [
            'pages' => $pages,
            'frontMode' => $this->permalinks->frontMode(),
            'frontPageId' => $this->permalinks->frontPageId(),
            'postsPageId' => $this->permalinks->postsPageId(),
            'postsPerPage' => $this->permalinks->postsPerPage(),
            'primaryCtaInContent' => true,
            'breadcrumbs' => [
                ['label' => $this->t('admin.ctrl_text_sitesettings.tableau_de_bord'), 'url' => '/admin'],
                ['label' => t('admin.nav.settings'), 'url' => '/admin/settings'],
                ['label' => t('admin.nav.reading'), 'url' => null],
            ],
        ]));

        return Response::html($html);
    }

    /**
     * Save Reading settings (`POST /admin/settings/reading`).
     */
    public function saveReading(Request $request): Response
    {
        $body = $request->body();
        /** @var array<string, mixed> $input */
        $input = is_array($body) ? $body : [];
        $errors = $this->permalinks->saveReading($input);
        if ($errors !== []) {
            $this->context->flashToast(implode(' ', $errors), 'danger');
        } else {
            $this->context->flashToast($this->t('admin.ctrl_text_sitesettings.les_reglages_de_lecture_ont_ete_enregistres'), 'success');
        }

        return Response::redirect('/admin/settings/reading');
    }

    /**
     * Permalinks & 301 redirects (`GET /admin/settings/permalinks`).
     */
    public function permalinks(Request $request): Response
    {
        $html = $this->view->render('admin::settings/permalinks', $this->context->build($request, t('admin.nav.permalinks'), [
            'postStructure' => $this->permalinks->postStructure(),
            'categoryBase' => $this->permalinks->categoryBase(),
            'tagBase' => $this->permalinks->tagBase(),
            'redirects' => $this->redirects->all(100),
            'primaryCtaInContent' => true,
            'breadcrumbs' => [
                ['label' => $this->t('admin.ctrl_text_sitesettings.tableau_de_bord'), 'url' => '/admin'],
                ['label' => t('admin.nav.settings'), 'url' => '/admin/settings'],
                ['label' => t('admin.nav.permalinks'), 'url' => null],
            ],
        ]));

        return Response::html($html);
    }

    /**
     * Save Permalink structure & taxonomy bases (`POST /admin/settings/permalinks`).
     */
    public function savePermalinks(Request $request): Response
    {
        $body = $request->body();
        /** @var array<string, mixed> $input */
        $input = is_array($body) ? $body : [];
        $errors = $this->permalinks->savePermalinks($input);
        if ($errors !== []) {
            $this->context->flashToast(implode(' ', $errors), 'danger');
        } else {
            $this->context->flashToast($this->t('admin.ctrl_text_sitesettings.la_structure_des_permaliens_a_ete_enregistree'), 'success');
        }

        return Response::redirect('/admin/settings/permalinks');
    }

    /**
     * Add a manual internal 301 redirect (`POST /admin/settings/permalinks/redirects`).
     */
    public function addRedirect(Request $request): Response
    {
        $source = is_string($request->input('source_path')) ? trim((string) $request->input('source_path')) : '';
        $target = is_string($request->input('target_path')) ? trim((string) $request->input('target_path')) : '';

        try {
            $created = $this->redirects->registerRedirect($source, $target, 301);
            if ($created) {
                $this->context->flashToast(sprintf($this->t('admin.ctrl_text_sitesettings.redirection_301_creee_s_s'), $source, $target), 'success');
            } else {
                $this->context->flashToast($this->t('admin.ctrl_text_sitesettings.redirection_ignoree_source_identique_a_la_cible_ou_boucle_de'), 'warning');
            }
        } catch (InvalidArgumentException $e) {
            $this->context->flashToast($e->getMessage(), 'danger');
        }

        return Response::redirect('/admin/settings/permalinks');
    }

    /**
     * Delete a redirect rule (`POST /admin/settings/permalinks/redirects/{id}/delete`).
     */
    public function deleteRedirect(Request $request, string $id): Response
    {
        $this->redirects->delete((int) $id);
        $this->context->flashToast($this->t('admin.ctrl_text_sitesettings.la_redirection_a_ete_supprimee'), 'success');

        return Response::redirect('/admin/settings/permalinks');
    }

    /**
     * Global SEO settings (`GET /admin/settings/seo`).
     */
    public function seo(Request $request): Response
    {
        $imageId = $this->seoSettings->defaultImageId();
        $defaultImage = ($imageId !== null && $this->media !== null) ? $this->media->find($imageId) : null;

        $html = $this->view->render('admin::settings/seo', $this->context->build($request, t('admin.nav.seo'), [
            'separator' => $this->seoSettings->separator(),
            'titleTemplate' => $this->seoSettings->titleTemplate(),
            'defaultDescription' => $this->seoSettings->defaultDescription(),
            'defaultImageId' => $imageId,
            'defaultImageUrl' => $defaultImage?->url(),
            'defaultImageData' => $defaultImage?->toApiArray(),
            'globalNoindex' => $this->seoSettings->globalNoindex(),
            'robotsCustom' => $this->seoSettings->robotsCustom(),
            'primaryCtaInContent' => true,
            'breadcrumbs' => [
                ['label' => $this->t('admin.ctrl_text_sitesettings.tableau_de_bord'), 'url' => '/admin'],
                ['label' => t('admin.nav.settings'), 'url' => '/admin/settings'],
                ['label' => t('admin.nav.seo'), 'url' => null],
            ],
        ]));

        return Response::html($html);
    }

    /**
     * Save global SEO settings (`POST /admin/settings/seo`).
     */
    public function saveSeo(Request $request): Response
    {
        $body = $request->body();
        /** @var array<string, mixed> $input */
        $input = is_array($body) ? $body : [];
        $errors = $this->seoSettings->save($input);
        if ($errors !== []) {
            $this->context->flashToast(implode(' ', $errors), 'danger');
        } else {
            $this->context->flashToast($this->t('admin.ctrl_text_sitesettings.les_reglages_de_referencement_ont_ete_enregistres'), 'success');
        }

        return Response::redirect('/admin/settings/seo');
    }
}
