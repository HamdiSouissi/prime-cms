<?php

declare(strict_types=1);

namespace Prime\Site\Http;

use Prime\Auth\Auth;
use Prime\Content\Entry;
use Prime\Content\EntryRepository;
use Prime\Content\EntryService;
use Prime\Database\Exception\ConnectionException;
use Prime\Http\Exception\NotFoundException;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Site\PageContext;
use Prime\Site\Seo\RobotsGenerator;
use Prime\Site\Seo\SitemapGenerator;
use Prime\Site\SiteRenderer;
use Prime\Site\UrlGenerator;
use Prime\Site\UrlResolver;
use Prime\Themes\PublicCsp;
use Prime\Themes\ThemeManager;
use Prime\Themes\ThemeOptions;

/**
 * Handles all public website requests (`GET /`, fallback public URLs, authenticated
 * entry & customizer live previews, `/sitemap.xml`, `/sitemap-{type}-{n}.xml`, and `/robots.txt`).
 */
final class SiteController
{
    public function __construct(
        private readonly UrlResolver $resolver,
        private readonly SiteRenderer $renderer,
        private readonly ThemeManager $themes,
        private readonly ThemeOptions $themeOptions,
        private readonly PublicCsp $csp,
        private readonly SitemapGenerator $sitemap,
        private readonly RobotsGenerator $robots,
        private readonly ?EntryRepository $entries = null,
        private readonly ?EntryService $entryService = null,
        private readonly ?UrlGenerator $urls = null,
        private readonly ?Auth $auth = null,
        private readonly ?\Prime\Settings\Settings $settings = null,
    ) {
    }

    /**
     * Resolve and render any public site request (called for `/` and router fallback).
     */
    public function handle(Request $request, bool $rethrowDbConnectionError = false): Response
    {
        // Only GET and HEAD are valid public site methods; other methods on unknown routes return 404
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            throw new NotFoundException('Page introuvable.');
        }

        // Never handle unmatched `/admin` paths as public site pages
        $reqPath = $request->path();
        if ($reqPath === '/admin' || str_starts_with($reqPath, '/admin/')) {
            throw new NotFoundException('Page introuvable.');
        }

        // JSON API clients hitting an unknown route get the standard JSON 404
        if ($request->wantsJson()) {
            throw new NotFoundException('Page introuvable.');
        }

        if ($this->themes->discover() === []) {
            throw new NotFoundException('Page introuvable.');
        }

        $gate = $this->accessGate($request);
        if ($gate !== null) {
            return $gate;
        }

        $isCustomizerPreview = $this->applyCustomizerPreviewIfAuthorized($request);

        try {
            try {
                $context = $this->resolveWithOptionalPreview($request, $isCustomizerPreview);
            } catch (ConnectionException $e) {
                if ($rethrowDbConnectionError) {
                    throw $e;
                }
                throw new NotFoundException('Page introuvable.');
            }

            if ($context->isRedirect() && $context->redirectTo !== null) {
                return Response::redirect($context->redirectTo, $context->redirectStatus);
            }

            $html = $this->renderer->render($context);
            $status = $context->isNotFound() ? 404 : $context->httpStatus;

            $response = Response::html($html, $status)
                ->withHeader('Content-Security-Policy', $this->csp->headerValue($request));

            if ($context->isPreview || $context->isCustomizerPreview) {
                $response = $response
                    ->withHeader('X-Robots-Tag', 'noindex, nofollow')
                    ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private');
            }

            return $response;
        } finally {
            if ($isCustomizerPreview) {
                $this->themeOptions->setPreviewOverrides(null);
            }
        }
    }

    /**
     * « Mode maintenance » (503 for everyone but administrators) and « Visibilité du site »
     * (public site reserved to signed-in users), both from the « Général » settings screen.
     */
    private function accessGate(Request $request): ?Response
    {
        if ($this->settings === null) {
            return null;
        }

        try {
            $maintenance = $this->settings->getBool('advanced.maintenance', false);
            $private = $this->settings->getBool('general.privacy', false);
            $message = trim($this->settings->getString('advanced.maintenance_message', ''));
            $siteName = $this->settings->getString('site.name', 'Prime CMS');
        } catch (\Throwable) {
            return null;
        }

        if ($maintenance && !($this->auth?->can('settings.manage', $request) ?? false)) {
            return Response::html($this->maintenancePage($siteName, $message), 503)
                ->withHeader('Retry-After', '600')
                ->withHeader('Cache-Control', 'no-store, private')
                ->withHeader('X-Robots-Tag', 'noindex');
        }

        if ($private && !($this->auth?->check($request) ?? false)) {
            return Response::redirect('/admin/login', 302)->withHeader('Cache-Control', 'no-store, private');
        }

        return null;
    }

    private function maintenancePage(string $siteName, string $message): string
    {
        $locale = function_exists('current_locale') ? current_locale() : 'fr';
        $title = t('site.maintenance.title');
        $body = $message !== '' ? $message : t('site.maintenance.message');
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        return '<!DOCTYPE html><html lang="' . $e($locale) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>' . $e($title . ' — ' . $siteName) . '</title></head>'
            . '<body style="margin:0;min-height:100vh;display:grid;place-items:center;font-family:system-ui,sans-serif;background:#FAF8F6;color:#1A1D29">'
            . '<main style="max-width:520px;padding:32px;text-align:center"><p style="margin:0 0 8px;font-size:13px;letter-spacing:.06em;text-transform:uppercase;color:#C9441F">' . $e($siteName) . '</p>'
            . '<h1 style="margin:0 0 12px;font-size:28px">' . $e($title) . '</h1>'
            . '<p style="margin:0;line-height:1.6;color:#3A3F52">' . nl2br($e($body)) . '</p></main></body></html>';
    }

    /**
     * Render `/sitemap.xml`.
     */
    public function sitemapIndex(Request $request): Response
    {
        try {
            $xml = $this->sitemap->renderIndex();
        } catch (ConnectionException) {
            throw new NotFoundException('Sitemap indisponible.');
        }

        return new Response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'X-Robots-Tag' => 'noindex, follow',
        ]);
    }

    /**
     * Render `/sitemap-{type}-{page}.xml`.
     */
    public function sitemapPage(Request $request, string $type, string $page): Response
    {
        $pageNum = (int) $page;
        if (preg_match('/^[a-z0-9_-]{1,64}$/', $type) !== 1 || $pageNum < 1) {
            throw new NotFoundException('Sitemap introuvable.');
        }

        try {
            $xml = $this->sitemap->renderSubSitemap($type, $pageNum);
        } catch (ConnectionException) {
            throw new NotFoundException('Sitemap indisponible.');
        }

        if ($xml === null) {
            throw new NotFoundException('Sitemap introuvable.');
        }

        return new Response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'X-Robots-Tag' => 'noindex, follow',
        ]);
    }

    /**
     * Render dynamic `/robots.txt` (physical `public/robots.txt` takes priority automatically).
     */
    public function robotsTxt(Request $request): Response
    {
        return new Response($this->robots->generate(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    /**
     * Render an authenticated entry preview using the active theme (called by `/admin/content/{type}/{id}/preview`).
     */
    public function renderEntryPreview(Request $request, Entry $entry): Response
    {
        $path = $this->urls !== null ? $this->urls->entryUrl($entry) : '/';
        $context = new PageContext(
            type: PageContext::TYPE_ENTRY,
            path: $path,
            entry: $entry,
            isPreview: true,
            httpStatus: 200,
        );

        $html = $this->renderer->render($context);

        return Response::html($html, 200)
            ->withHeader('Content-Security-Policy', $this->csp->headerValue($request))
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    }

    private function resolveWithOptionalPreview(Request $request, bool $isCustomizerPreview): PageContext
    {
        // Authenticated entry preview via `?preview=1&entry_id=123` (or `?preview=1` on a draft's URL)
        $previewFlag = $request->query('preview');
        if ($previewFlag === '1' || $previewFlag === 'true') {
            $previewContext = $this->resolveAuthorizedEntryPreview($request);
            if ($previewContext !== null) {
                return $previewContext->withPreview(true, $isCustomizerPreview);
            }
        }

        $context = $this->resolver->resolve($request);
        if ($isCustomizerPreview) {
            return $context->withPreview(true, true);
        }

        return $context;
    }

    /**
     * Resolve an unpublished/draft/scheduled/private entry for preview ONLY if the current
     * authenticated user has edit permission on that entry. Returns null otherwise (=> 404).
     */
    private function resolveAuthorizedEntryPreview(Request $request): ?PageContext
    {
        if ($this->auth === null || $this->entries === null || $this->entryService === null) {
            return null;
        }

        $user = $this->auth->user($request);
        if ($user === null) {
            return null;
        }

        $entry = null;
        $entryId = $request->query('entry_id');
        if (is_numeric($entryId) && (int) $entryId > 0) {
            $entry = $this->entries->find((int) $entryId);
        } else {
            // Try matching draft by URL path slug (post or page)
            $trimmed = trim($request->path(), '/');
            $segments = $trimmed === '' ? [] : explode('/', $trimmed);
            if (count($segments) === 2 && $segments[0] === 'blog') {
                $entry = $this->entries->findBySlug('post', rawurldecode($segments[1]), parentId: 0);
            } elseif (count($segments) === 1 && $segments[0] !== '') {
                $entry = $this->entries->findBySlug('page', rawurldecode($segments[0]), parentId: 0)
                    ?? $this->entries->findBySlug('post', rawurldecode($segments[0]), parentId: 0);
            }
        }

        if ($entry === null) {
            return null;
        }

        $type = $this->entryService->contentTypes()->get($entry->type);
        if ($type === null || !$this->entryService->canEdit($user, $entry, $type)) {
            return null;
        }

        return new PageContext(
            type: PageContext::TYPE_ENTRY,
            path: '/' . trim($request->path(), '/'),
            entry: $entry,
            contentType: $type,
            isPreview: true,
            httpStatus: 200,
        );
    }

    /**
     * Apply temporary draft customizer overrides ONLY for authenticated users with `themes.manage`.
     */
    private function applyCustomizerPreviewIfAuthorized(Request $request): bool
    {
        if ($request->query('customizer_preview') !== '1') {
            return false;
        }

        if ($this->auth === null) {
            return false;
        }

        $user = $this->auth->user($request);
        if ($user === null || !$user->can('themes.manage')) {
            return false;
        }

        $rawOverrides = $request->query('preview_options');
        if (is_string($rawOverrides) && trim($rawOverrides) !== '') {
            $decoded = json_decode($rawOverrides, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $this->themeOptions->setPreviewOverrides($decoded);
            }
        }

        return true;
    }
}
