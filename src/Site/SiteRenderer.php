<?php

declare(strict_types=1);

namespace Prime\Site;

use Prime\Content\EntryQuery;
use Prime\Hooks\Hooks;
use Prime\Media\MediaRepository;
use Prime\Settings\Settings;
use Prime\Site\Menu\MenuRenderer;
use Prime\Site\Seo\SeoManager;
use Prime\Site\View\EntryViewFactory;
use Prime\Site\View\SiteView;
use Prime\Site\Zone\DynamicBlockRegistry;
use Prime\Site\Zone\ZoneRepository;
use Prime\Themes\ThemeAssetPublisher;
use Prime\Themes\ThemeAssetRegistry;
use Prime\Themes\ThemeManager;
use Prime\Themes\ThemeOptions;

/**
 * Renders any `PageContext` through the active theme (with single-level child -> parent
 * template inheritance, `layouts/base.php` wrapper, and plugin fallback templates).
 */
final class SiteRenderer
{
    public function __construct(
        private readonly ThemeManager $themes,
        private readonly ThemeOptions $themeOptions,
        private readonly ThemeAssetRegistry $assets,
        private readonly ThemeAssetPublisher $publisher,
        private readonly TemplateResolver $templates,
        private readonly UrlGenerator $urls,
        private readonly MenuRenderer $menuRenderer,
        private readonly ZoneRepository $zones,
        private readonly DynamicBlockRegistry $dynamicBlocks,
        private readonly SeoManager $seo,
        private readonly EntryViewFactory $entryFactory,
        private readonly EntryQuery $entryQuery,
        private readonly ?Settings $settings = null,
        private readonly ?MediaRepository $media = null,
        private readonly ?Hooks $hooks = null,
    ) {
    }

    /**
     * Render `$context` into complete HTML using the active theme.
     */
    public function render(PageContext $context): string
    {
        $activeTheme = $this->themes->active();
        $parentTheme = $this->themes->activeParent();

        $themeBaseDirs = [$activeTheme->path];
        $themeTemplateDirs = [$activeTheme->path . DIRECTORY_SEPARATOR . 'templates'];
        if ($parentTheme !== null) {
            $themeBaseDirs[] = $parentTheme->path;
            $themeTemplateDirs[] = $parentTheme->path . DIRECTORY_SEPARATOR . 'templates';
        }

        // Keep compiled theme options CSS synchronized even when published within the same process
        if ($activeTheme->manifest !== null) {
            $optionsCssUrl = $this->themeOptions->publishedCssUrl($activeTheme->manifest, $parentTheme?->manifest);
            if ($optionsCssUrl !== null) {
                $this->assets->style('theme-options', $optionsCssUrl);
            }
        }

        $site = new SiteView(
            context: $context,
            activeTheme: $activeTheme,
            parentTheme: $parentTheme,
            themeBaseDirs: $themeBaseDirs,
            themeOptions: $this->themeOptions,
            assets: $this->assets,
            publisher: $this->publisher,
            templates: $this->templates,
            urls: $this->urls,
            menuRenderer: $this->menuRenderer,
            zones: $this->zones,
            dynamicBlocks: $this->dynamicBlocks,
            seo: $this->seo,
            entryFactory: $this->entryFactory,
            entryQuery: $this->entryQuery,
            settings: $this->settings,
            media: $this->media,
            hooks: $this->hooks,
        );

        // Batch-hydrate `$entry` and `$entries` (ZERO N+1!)
        $entryView = $context->entry !== null ? $this->entryFactory->makeOne($context->entry) : null;
        $entriesViews = $context->entries !== [] ? $this->entryFactory->makeMany($context->entries) : [];
        $site->bindViews($entryView, $entriesViews);

        $resolved = $this->templates->resolve($context, $themeTemplateDirs);
        $vars = [
            'site' => $site,
            'context' => $context,
            'entry' => $entryView,
            'entries' => $entriesViews,
            'term' => $context->term,
            'taxonomy' => $context->taxonomy,
            'author' => $context->author,
            'contentType' => $context->contentType,
            'searchQuery' => $context->searchQuery,
        ];

        $contentHtml = $resolved !== null
            ? SiteView::renderPhpFile($resolved['file'], $vars)
            : $this->fallbackInnerHtml($site, $context, $entryView);

        // If the template already output a full HTML document (`<!DOCTYPE` / `<html`), return directly
        if (stripos($contentHtml, '<!doctype') !== false || stripos($contentHtml, '<html') !== false) {
            return $this->injectPreviewBannerIfNeeded($contentHtml, $context);
        }

        // Otherwise wrap inside theme's `templates/layouts/base.php` (child -> parent)
        $layoutFile = $this->templates->resolveRelative('templates/layouts/base.php', $themeBaseDirs);
        if ($layoutFile !== null) {
            $html = SiteView::renderPhpFile($layoutFile, array_merge($vars, [
                'content' => $contentHtml,
                'templateCandidate' => $resolved['candidate'] ?? null,
            ]));

            return $this->injectPreviewBannerIfNeeded($html, $context);
        }

        return $this->fallbackDocument($site, $contentHtml, $context);
    }

    private function injectPreviewBannerIfNeeded(string $html, PageContext $context): string
    {
        if (!$context->isPreview || $context->isCustomizerPreview) {
            return $html;
        }

        $editUrl = $context->entry !== null
            ? htmlspecialchars($context->entry->editUrl(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            : '/admin';

        $banner = '<div class="prime-preview-banner" role="status" aria-live="polite">'
            . '<div class="prime-preview-banner__inner">'
            . '<span class="prime-preview-banner__badge">Aperçu</span>'
            . '<span class="prime-preview-banner__text">Vous consultez un aperçu privé non indexé par les moteurs de recherche.</span>'
            . '<a class="prime-preview-banner__link" href="' . $editUrl . '">Retourner à l\'éditeur</a>'
            . '</div></div>';

        $pos = stripos($html, '<body');
        if ($pos !== false) {
            $endTag = strpos($html, '>', $pos);
            if ($endTag !== false) {
                return substr($html, 0, $endTag + 1) . "\n" . $banner . substr($html, $endTag + 1);
            }
        }

        return $banner . "\n" . $html;
    }

    private function fallbackInnerHtml(SiteView $site, PageContext $context, ?View\EntryView $entry): string
    {
        if ($context->isNotFound()) {
            return '<section class="site-container"><h1>Page introuvable</h1><p>Le contenu demandé est introuvable.</p></section>';
        }
        if ($entry !== null) {
            return '<article class="site-container"><h1>' . $entry->title() . '</h1>' . $entry->content() . '</article>';
        }

        return '<section class="site-container"><h1>' . $site->name() . '</h1></section>';
    }

    private function fallbackDocument(SiteView $site, string $contentHtml, PageContext $context): string
    {
        $html = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . $site->head()
            . '</head><body class="' . $site->bodyClass() . '"><main id="main-content">'
            . $contentHtml
            . '</main>' . $site->foot() . '</body></html>';

        return $this->injectPreviewBannerIfNeeded($html, $context);
    }
}
