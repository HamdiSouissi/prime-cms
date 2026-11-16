<?php

declare(strict_types=1);

namespace Prime\Site;

use Prime\Auth\Auth;
use Prime\Auth\UserRepository;
use Prime\Content\ContentTypeRegistry;
use Prime\Content\Entry;
use Prime\Content\EntryQuery;
use Prime\Content\EntryRepository;
use Prime\Content\EntryService;
use Prime\Content\TaxonomyRegistry;
use Prime\Content\TermRepository;
use Prime\Database\Connection;
use Prime\Editor\BlockDefinition;
use Prime\Editor\Renderer;
use Prime\Hooks\Hooks;
use Prime\Http\Router;
use Prime\I18n\Translator;
use Prime\Kernel\Container;
use Prime\Kernel\ServiceProvider;
use Prime\Media\MediaRepository;
use Prime\Media\MediaUsageRepository;
use Prime\Settings\Settings;
use Prime\Site\Http\SiteController;
use Prime\Site\Menu\MenuRenderer;
use Prime\Site\Menu\MenuRepository;
use Prime\Site\Seo\RobotsGenerator;
use Prime\Site\Seo\SeoManager;
use Prime\Site\Seo\SeoSettings;
use Prime\Site\Seo\SitemapGenerator;
use Prime\Site\View\EntryViewFactory;
use Prime\Site\Zone\DynamicBlockRegistry;
use Prime\Site\Zone\ZoneRepository;
use Prime\Themes\PublicCsp;
use Prime\Themes\ThemeAssetPublisher;
use Prime\Themes\ThemeAssetRegistry;
use Prime\Themes\ThemeManager;
use Prime\Themes\ThemeOptions;
use Throwable;

/**
 * Registers all Phase 8 public site services (`Prime\Site`), dynamic blocks,
 * automatic 301 redirect tracking, reserved slug hooks, sitemap/robots routes,
 * and the public router fallback.
 */
final class SiteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $c = $this->app->container();
        $app = $this->app;

        $c->singleton(PermalinkSettings::class, static function (Container $c): PermalinkSettings {
            $settings = $c->has(Settings::class) ? $c->get(Settings::class) : null;

            return new PermalinkSettings($settings instanceof Settings ? $settings : null);
        });

        $c->singleton(ReservedSlugValidator::class, static function (Container $c): ReservedSlugValidator {
            $types = $c->has(ContentTypeRegistry::class) ? $c->get(ContentTypeRegistry::class) : null;
            $taxonomies = $c->has(TaxonomyRegistry::class) ? $c->get(TaxonomyRegistry::class) : null;
            $router = $c->has(Router::class) ? $c->get(Router::class) : null;

            return new ReservedSlugValidator(
                permalinks: $c->get(PermalinkSettings::class),
                types: $types instanceof ContentTypeRegistry ? $types : null,
                taxonomies: $taxonomies instanceof TaxonomyRegistry ? $taxonomies : null,
                router: $router instanceof Router ? $router : null,
            );
        });

        $c->singleton(RedirectRepository::class, static function (Container $c): RedirectRepository {
            return new RedirectRepository($c->get(Connection::class));
        });

        $c->singleton(UrlGenerator::class, static function (Container $c) use ($app): UrlGenerator {
            $types = $c->has(ContentTypeRegistry::class) ? $c->get(ContentTypeRegistry::class) : null;
            $entries = $c->has(EntryRepository::class) ? $c->get(EntryRepository::class) : null;
            $baseUrl = (string) $app->config()->get('app.url', '');

            return new UrlGenerator(
                permalinks: $c->get(PermalinkSettings::class),
                types: $types instanceof ContentTypeRegistry ? $types : null,
                entries: $entries instanceof EntryRepository ? $entries : null,
                baseUrl: $baseUrl,
            );
        });

        $c->singleton(TemplateResolver::class, static function (Container $c): TemplateResolver {
            $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;

            return new TemplateResolver($hooks instanceof Hooks ? $hooks : null);
        });

        $c->singleton(UrlResolver::class, static function (Container $c): UrlResolver {
            $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;

            return new UrlResolver(
                permalinks: $c->get(PermalinkSettings::class),
                urls: $c->get(UrlGenerator::class),
                types: $c->get(ContentTypeRegistry::class),
                taxonomies: $c->get(TaxonomyRegistry::class),
                entries: $c->get(EntryRepository::class),
                query: $c->get(EntryQuery::class),
                terms: $c->get(TermRepository::class),
                users: $c->get(UserRepository::class),
                redirects: $c->get(RedirectRepository::class),
                hooks: $hooks instanceof Hooks ? $hooks : null,
            );
        });

        $c->singleton(MenuRepository::class, static function (Container $c): MenuRepository {
            $entries = $c->has(EntryRepository::class) ? $c->get(EntryRepository::class) : null;
            $terms = $c->has(TermRepository::class) ? $c->get(TermRepository::class) : null;
            $types = $c->has(ContentTypeRegistry::class) ? $c->get(ContentTypeRegistry::class) : null;

            return new MenuRepository(
                db: $c->get(Connection::class),
                urls: $c->get(UrlGenerator::class),
                entries: $entries instanceof EntryRepository ? $entries : null,
                terms: $terms instanceof TermRepository ? $terms : null,
                types: $types instanceof ContentTypeRegistry ? $types : null,
            );
        });

        $c->singleton(MenuRenderer::class, static function (Container $c): MenuRenderer {
            $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;

            return new MenuRenderer(
                menus: $c->get(MenuRepository::class),
                hooks: $hooks instanceof Hooks ? $hooks : null,
            );
        });

        $c->singleton(ZoneRepository::class, static function (Container $c): ZoneRepository {
            $usage = $c->has(MediaUsageRepository::class) ? $c->get(MediaUsageRepository::class) : null;
            $themes = $c->has(ThemeManager::class) ? $c->get(ThemeManager::class) : null;

            return new ZoneRepository(
                db: $c->get(Connection::class),
                renderer: $c->get(Renderer::class),
                mediaUsage: $usage instanceof MediaUsageRepository ? $usage : null,
                themes: $themes instanceof ThemeManager ? $themes : null,
            );
        });

        $c->singleton(DynamicBlockRegistry::class, static function (Container $c): DynamicBlockRegistry {
            $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;
            $contentTypes = $c->has(ContentTypeRegistry::class) ? $c->get(ContentTypeRegistry::class) : null;
            $menus = $c->has(MenuRepository::class) ? $c->get(MenuRepository::class) : null;
            $media = $c->has(MediaRepository::class) ? $c->get(MediaRepository::class) : null;

            return new DynamicBlockRegistry(
                query: $c->get(EntryQuery::class),
                terms: $c->get(TermRepository::class),
                taxonomies: $c->get(TaxonomyRegistry::class),
                urls: $c->get(UrlGenerator::class),
                menuRenderer: $c->get(MenuRenderer::class),
                hooks: $hooks instanceof Hooks ? $hooks : null,
                contentTypes: $contentTypes instanceof ContentTypeRegistry ? $contentTypes : null,
                menus: $menus instanceof MenuRepository ? $menus : null,
                media: $media instanceof MediaRepository ? $media : null,
            );
        });

        $c->singleton(SeoSettings::class, static function (Container $c): SeoSettings {
            $settings = $c->has(Settings::class) ? $c->get(Settings::class) : null;

            return new SeoSettings($settings instanceof Settings ? $settings : null);
        });

        $c->singleton(SeoManager::class, static function (Container $c): SeoManager {
            $settings = $c->has(Settings::class) ? $c->get(Settings::class) : null;
            $media = $c->has(MediaRepository::class) ? $c->get(MediaRepository::class) : null;
            $themeOptions = $c->has(ThemeOptions::class) ? $c->get(ThemeOptions::class) : null;
            $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;

            return new SeoManager(
                seoSettings: $c->get(SeoSettings::class),
                urls: $c->get(UrlGenerator::class),
                settings: $settings instanceof Settings ? $settings : null,
                media: $media instanceof MediaRepository ? $media : null,
                themeOptions: $themeOptions instanceof ThemeOptions ? $themeOptions : null,
                hooks: $hooks instanceof Hooks ? $hooks : null,
                translator: $c->has(Translator::class) ? $c->get(Translator::class) : null,
            );
        });

        $c->singleton(SitemapGenerator::class, static function (Container $c): SitemapGenerator {
            $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;
            $media = $c->has(MediaRepository::class) ? $c->get(MediaRepository::class) : null;

            return new SitemapGenerator(
                types: $c->get(ContentTypeRegistry::class),
                taxonomies: $c->get(TaxonomyRegistry::class),
                query: $c->get(EntryQuery::class),
                terms: $c->get(TermRepository::class),
                urls: $c->get(UrlGenerator::class),
                hooks: $hooks instanceof Hooks ? $hooks : null,
                media: $media instanceof MediaRepository ? $media : null,
            );
        });

        $c->singleton(RobotsGenerator::class, static function (Container $c) use ($app): RobotsGenerator {
            return new RobotsGenerator(
                seoSettings: $c->get(SeoSettings::class),
                urls: $c->get(UrlGenerator::class),
                publicRoot: $app->publicPath(),
            );
        });

        $c->singleton(EntryViewFactory::class, static function (Container $c): EntryViewFactory {
            $users = $c->has(UserRepository::class) ? $c->get(UserRepository::class) : null;
            $media = $c->has(MediaRepository::class) ? $c->get(MediaRepository::class) : null;
            $query = $c->has(EntryQuery::class) ? $c->get(EntryQuery::class) : null;
            $zones = $c->has(ZoneRepository::class) ? $c->get(ZoneRepository::class) : null;
            $dyn = $c->has(DynamicBlockRegistry::class) ? $c->get(DynamicBlockRegistry::class) : null;

            return new EntryViewFactory(
                urls: $c->get(UrlGenerator::class),
                users: $users instanceof UserRepository ? $users : null,
                media: $media instanceof MediaRepository ? $media : null,
                query: $query instanceof EntryQuery ? $query : null,
                zones: $zones instanceof ZoneRepository ? $zones : null,
                dynamicBlocks: $dyn instanceof DynamicBlockRegistry ? $dyn : null,
            );
        });

        $c->singleton(SiteRenderer::class, static function (Container $c): SiteRenderer {
            $settings = $c->has(Settings::class) ? $c->get(Settings::class) : null;
            $media = $c->has(MediaRepository::class) ? $c->get(MediaRepository::class) : null;
            $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;

            return new SiteRenderer(
                themes: $c->get(ThemeManager::class),
                themeOptions: $c->get(ThemeOptions::class),
                assets: $c->get(ThemeAssetRegistry::class),
                publisher: $c->get(ThemeAssetPublisher::class),
                templates: $c->get(TemplateResolver::class),
                urls: $c->get(UrlGenerator::class),
                menuRenderer: $c->get(MenuRenderer::class),
                zones: $c->get(ZoneRepository::class),
                dynamicBlocks: $c->get(DynamicBlockRegistry::class),
                seo: $c->get(SeoManager::class),
                entryFactory: $c->get(EntryViewFactory::class),
                entryQuery: $c->get(EntryQuery::class),
                settings: $settings instanceof Settings ? $settings : null,
                media: $media instanceof MediaRepository ? $media : null,
                hooks: $hooks instanceof Hooks ? $hooks : null,
            );
        });

        $c->singleton(SiteController::class, function (Container $c): SiteController {
            $entries = $c->has(EntryRepository::class) ? $c->get(EntryRepository::class) : null;
            $entryService = $c->has(EntryService::class) ? $c->get(EntryService::class) : null;
            $auth = null;
            $appKey = $this->app->config()->get('app.key', '');
            if ($c->has(Auth::class) && is_string($appKey) && trim($appKey) !== '') {
                try {
                    $resolvedAuth = $c->get(Auth::class);
                    if ($resolvedAuth instanceof Auth) {
                        $auth = $resolvedAuth;
                    }
                } catch (\Throwable) {
                    $auth = null;
                }
            }

            return new SiteController(
                resolver: $c->get(UrlResolver::class),
                renderer: $c->get(SiteRenderer::class),
                themes: $c->get(ThemeManager::class),
                themeOptions: $c->get(ThemeOptions::class),
                csp: $c->get(PublicCsp::class),
                sitemap: $c->get(SitemapGenerator::class),
                robots: $c->get(RobotsGenerator::class),
                entries: $entries instanceof EntryRepository ? $entries : null,
                entryService: $entryService instanceof EntryService ? $entryService : null,
                urls: $c->get(UrlGenerator::class),
                auth: $auth,
            );
        });
    }

    public function boot(): void
    {
        $c = $this->app->container();

        // 1. Register public SEO routes & Router fallback (lazy controller resolution!)
        if ($c->has(Router::class)) {
            /** @var Router $router */
            $router = $this->app->get(Router::class);
            $router->get('/sitemap.xml', [SiteController::class, 'sitemapIndex'])->name('site.sitemap.index');
            $router->get('/sitemap-{type:[a-z0-9_-]+}-{page:\d+}.xml', [SiteController::class, 'sitemapPage'])
                ->name('site.sitemap.page');
            $router->get('/robots.txt', [SiteController::class, 'robotsTxt'])->name('site.robots');

            $router->setFallback([SiteController::class, 'handle']);
        }

        // 2. Register `dynamic` Prime Editor block via `editor.blocks` filter (lazy, zero DB on boot!)
        if ($c->has(Hooks::class)) {
            /** @var Hooks $hooks */
            $hooks = $this->app->get(Hooks::class);

            $hooks->filter('editor.blocks', function (mixed $blocks) use ($c): mixed {
                if (!is_array($blocks)) {
                    return $blocks;
                }
                $blocks['dynamic'] = new BlockDefinition(
                    type: 'dynamic',
                    title: 'Bloc dynamique',
                    description: 'Insère un bloc dynamique rendu côté serveur (derniers articles, catégories, recherche, menu ou bloc de plugin).',
                    icon: 'sparkles',
                    category: 'widgets',
                    validator: static function (array $data): array {
                        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
                        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,60}$/', $name) !== 1) {
                            return ['data' => $data, 'errors' => ['Identifiant de bloc dynamique invalide.']];
                        }
                        $attrs = is_array($data['attrs'] ?? null) ? $data['attrs'] : [];
                        $cleanAttrs = [];
                        foreach ($attrs as $k => $v) {
                            if (is_string($k) && preg_match('/^[a-z0-9_]{1,40}$/', $k) === 1 && (is_scalar($v) || $v === null)) {
                                $cleanAttrs[$k] = is_string($v) ? mb_substr(trim(strip_tags($v)), 0, 240) : $v;
                            }
                        }

                        return [
                            'data' => [
                                'name' => $name,
                                'attrs' => $cleanAttrs,
                            ],
                            'errors' => [],
                        ];
                    },
                    renderer: static function (array $data) use ($c): string {
                        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
                        $attrs = is_array($data['attrs'] ?? null) ? $data['attrs'] : [];
                        if ($name === '' || !$c->has(DynamicBlockRegistry::class)) {
                            return '';
                        }
                        try {
                            /** @var DynamicBlockRegistry $dyn */
                            $dyn = $c->get(DynamicBlockRegistry::class);

                            return $dyn->render($name, $attrs);
                        } catch (Throwable) {
                            return '';
                        }
                    },
                    textExtractor: static fn (array $data): string => '',
                );

                return $blocks;
            });

            // 3. Inject all Phase 8 reserved slugs into EntryService validation (lazy!)
            $hooks->filter('content.reserved_slugs', static function (mixed $slugs) use ($c): mixed {
                if (!is_array($slugs) || !$c->has(ReservedSlugValidator::class)) {
                    return $slugs;
                }
                try {
                    /** @var ReservedSlugValidator $validator */
                    $validator = $c->get(ReservedSlugValidator::class);

                    return array_values(array_unique(array_merge($slugs, $validator->reservedSlugs())));
                } catch (Throwable) {
                    return $slugs;
                }
            });

            $hooks->filter('content.post_uses_root_slug', static function (mixed $flag) use ($c): bool {
                if (!$c->has(PermalinkSettings::class)) {
                    return (bool) $flag;
                }
                try {
                    /** @var PermalinkSettings $permalinks */
                    $permalinks = $c->get(PermalinkSettings::class);

                    return $permalinks->postStructure() === PermalinkSettings::POST_SLUG_ONLY;
                } catch (Throwable) {
                    return (bool) $flag;
                }
            });

            // 4. Auto-create 301 redirect in `pc_redirects` whenever a published entry's slug or parent changes!
            $hooks->on('content.updated', static function (mixed $updated, mixed $previous) use ($c): void {
                if (!$updated instanceof Entry || !$previous instanceof Entry) {
                    return;
                }
                if ($previous->status !== Entry::STATUS_PUBLISHED || $updated->status !== Entry::STATUS_PUBLISHED) {
                    return;
                }
                if ($previous->slug === $updated->slug && $previous->parentId === $updated->parentId) {
                    return;
                }
                if (!$c->has(UrlGenerator::class) || !$c->has(RedirectRepository::class)) {
                    return;
                }
                try {
                    /** @var UrlGenerator $urls */
                    $urls = $c->get(UrlGenerator::class);
                    /** @var RedirectRepository $redirects */
                    $redirects = $c->get(RedirectRepository::class);

                    $oldUrl = $urls->entryUrl($previous);
                    $newUrl = $urls->entryUrl($updated);
                    if ($oldUrl !== $newUrl) {
                        $redirects->registerRedirect($oldUrl, $newUrl, 301);
                    }
                } catch (Throwable) {
                    // Never block content saving if redirects table is absent in minimal unit tests.
                }
            });
        }
    }
}
