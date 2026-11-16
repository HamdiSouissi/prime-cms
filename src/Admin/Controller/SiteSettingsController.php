<?php

declare(strict_types=1);

namespace Prime\Admin\Controller;

use InvalidArgumentException;
use Prime\Admin\AdminContext;
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

        $html = $this->view->render('admin::settings/reading', $this->context->build($request, 'Lecture', [
            'pages' => $pages,
            'frontMode' => $this->permalinks->frontMode(),
            'frontPageId' => $this->permalinks->frontPageId(),
            'postsPageId' => $this->permalinks->postsPageId(),
            'postsPerPage' => $this->permalinks->postsPerPage(),
            'primaryCtaInContent' => true,
            'breadcrumbs' => [
                ['label' => 'Tableau de bord', 'url' => '/admin'],
                ['label' => 'Réglages', 'url' => '/admin/settings'],
                ['label' => 'Lecture', 'url' => null],
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
            $this->context->flashToast('Les réglages de lecture ont été enregistrés.', 'success');
        }

        return Response::redirect('/admin/settings/reading');
    }

    /**
     * Permalinks & 301 redirects (`GET /admin/settings/permalinks`).
     */
    public function permalinks(Request $request): Response
    {
        $html = $this->view->render('admin::settings/permalinks', $this->context->build($request, 'Permaliens', [
            'postStructure' => $this->permalinks->postStructure(),
            'categoryBase' => $this->permalinks->categoryBase(),
            'tagBase' => $this->permalinks->tagBase(),
            'redirects' => $this->redirects->all(100),
            'primaryCtaInContent' => true,
            'breadcrumbs' => [
                ['label' => 'Tableau de bord', 'url' => '/admin'],
                ['label' => 'Réglages', 'url' => '/admin/settings'],
                ['label' => 'Permaliens', 'url' => null],
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
            $this->context->flashToast('La structure des permaliens a été enregistrée.', 'success');
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
                $this->context->flashToast(sprintf('Redirection 301 créée : %s → %s.', $source, $target), 'success');
            } else {
                $this->context->flashToast('Redirection ignorée (source identique à la cible ou boucle détectée).', 'warning');
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
        $this->context->flashToast('La redirection a été supprimée.', 'success');

        return Response::redirect('/admin/settings/permalinks');
    }

    /**
     * Global SEO settings (`GET /admin/settings/seo`).
     */
    public function seo(Request $request): Response
    {
        $imageId = $this->seoSettings->defaultImageId();
        $defaultImage = ($imageId !== null && $this->media !== null) ? $this->media->find($imageId) : null;

        $html = $this->view->render('admin::settings/seo', $this->context->build($request, 'Référencement', [
            'separator' => $this->seoSettings->separator(),
            'titleTemplate' => $this->seoSettings->titleTemplate(),
            'defaultDescription' => $this->seoSettings->defaultDescription(),
            'defaultImageId' => $imageId,
            'defaultImageUrl' => $defaultImage?->url(),
            'globalNoindex' => $this->seoSettings->globalNoindex(),
            'robotsCustom' => $this->seoSettings->robotsCustom(),
            'primaryCtaInContent' => true,
            'breadcrumbs' => [
                ['label' => 'Tableau de bord', 'url' => '/admin'],
                ['label' => 'Réglages', 'url' => '/admin/settings'],
                ['label' => 'Référencement', 'url' => null],
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
            $this->context->flashToast('Les réglages de référencement ont été enregistrés.', 'success');
        }

        return Response::redirect('/admin/settings/seo');
    }
}
