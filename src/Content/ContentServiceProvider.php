<?php

declare(strict_types=1);

namespace Prime\Content;

use Prime\Content\Fields\FieldRegistry;
use Prime\Content\Fields\FieldValidator;
use Prime\Database\Connection;
use Prime\Editor\BlockRegistry;
use Prime\Editor\Renderer;
use Prime\Hooks\Hooks;
use Prime\Http\Router;
use Prime\Jobs\JobQueue;
use Prime\Kernel\ServiceProvider;
use Prime\Media\MediaRepository;
use Prime\Media\MediaUsageRepository;
use Prime\Security\HtmlSanitizer;

/**
 * Registers all Phase 6 Content, Taxonomy, Custom Field, and Prime Editor services.
 */
final class ContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;
        $c = $app->container();

        $c->singleton(HtmlSanitizer::class, static fn (): HtmlSanitizer => new HtmlSanitizer());

        $c->singleton(ContentTypeRegistry::class, static function () use ($c): ContentTypeRegistry {
            $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;

            return new ContentTypeRegistry($hooks instanceof Hooks ? $hooks : null);
        });

        $c->singleton(TaxonomyRegistry::class, static function () use ($c): TaxonomyRegistry {
            $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;

            return new TaxonomyRegistry($hooks instanceof Hooks ? $hooks : null);
        });

        $c->singleton(FieldRegistry::class, static function () use ($c): FieldRegistry {
            $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;

            return new FieldRegistry($hooks instanceof Hooks ? $hooks : null);
        });

        $c->singleton(BlockRegistry::class, static function () use ($c): BlockRegistry {
            $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;
            $media = $c->has(MediaRepository::class) ? $c->get(MediaRepository::class) : null;

            return new BlockRegistry(
                $hooks instanceof Hooks ? $hooks : null,
                $media instanceof MediaRepository ? $media : null,
            );
        });

        $c->singleton(Renderer::class, static function () use ($c): Renderer {
            /** @var BlockRegistry $blocks */
            $blocks = $c->make(BlockRegistry::class);
            /** @var HtmlSanitizer $sanitizer */
            $sanitizer = $c->make(HtmlSanitizer::class);
            $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;

            return new Renderer($blocks, $sanitizer, $hooks instanceof Hooks ? $hooks : null);
        });

        $c->singleton(FieldValidator::class, static function () use ($c): FieldValidator {
            /** @var FieldRegistry $fields */
            $fields = $c->make(FieldRegistry::class);
            /** @var HtmlSanitizer $sanitizer */
            $sanitizer = $c->make(HtmlSanitizer::class);
            $db = $c->has(Connection::class) ? $c->make(Connection::class) : null;

            return new FieldValidator($fields, $sanitizer, $db instanceof Connection ? $db : null);
        });

        $c->singleton(TermRepository::class, static function () use ($c): TermRepository {
            /** @var Connection $db */
            $db = $c->make(Connection::class);
            /** @var TaxonomyRegistry $taxonomies */
            $taxonomies = $c->make(TaxonomyRegistry::class);

            return new TermRepository($db, $taxonomies);
        });

        $c->singleton(EntryRepository::class, static function () use ($c): EntryRepository {
            /** @var Connection $db */
            $db = $c->make(Connection::class);

            return new EntryRepository($db);
        });

        $c->singleton(RevisionRepository::class, static function () use ($c): RevisionRepository {
            /** @var Connection $db */
            $db = $c->make(Connection::class);

            return new RevisionRepository($db);
        });

        $c->singleton(EntryLockRepository::class, static function () use ($app, $c): EntryLockRepository {
            /** @var Connection $db */
            $db = $c->make(Connection::class);
            $ttl = (int) $app->config()->get('content.lock_ttl_seconds', 120);

            return new EntryLockRepository($db, max(10, $ttl));
        });

        $c->singleton(EntryQuery::class, static function () use ($c): EntryQuery {
            /** @var Connection $db */
            $db = $c->make(Connection::class);
            /** @var EntryRepository $repo */
            $repo = $c->make(EntryRepository::class);

            return new EntryQuery($db, $repo);
        });

        $c->singleton(EntryService::class, static function () use ($app, $c): EntryService {
            /** @var EntryRepository $entries */
            $entries = $c->make(EntryRepository::class);
            /** @var TermRepository $terms */
            $terms = $c->make(TermRepository::class);
            /** @var RevisionRepository $revisions */
            $revisions = $c->make(RevisionRepository::class);
            /** @var EntryLockRepository $locks */
            $locks = $c->make(EntryLockRepository::class);
            /** @var ContentTypeRegistry $types */
            $types = $c->make(ContentTypeRegistry::class);
            /** @var TaxonomyRegistry $taxonomies */
            $taxonomies = $c->make(TaxonomyRegistry::class);
            /** @var Renderer $renderer */
            $renderer = $c->make(Renderer::class);
            /** @var FieldValidator $fieldValidator */
            $fieldValidator = $c->make(FieldValidator::class);
            /** @var Hooks $hooks */
            $hooks = $c->make(Hooks::class);
            $router = $c->has(Router::class) ? $c->get(Router::class) : null;

            $revisionsMax = (int) $app->config()->get('content.revisions_max', 25);
            $mediaUsage = $c->has(MediaUsageRepository::class) ? $c->get(MediaUsageRepository::class) : null;
            $fields = $c->has(FieldRegistry::class) ? $c->get(FieldRegistry::class) : null;
            $media = $c->has(MediaRepository::class) ? $c->get(MediaRepository::class) : null;
            $queue = $c->has(JobQueue::class) ? $c->get(JobQueue::class) : null;

            return new EntryService(
                entries: $entries,
                terms: $terms,
                revisions: $revisions,
                locks: $locks,
                types: $types,
                taxonomies: $taxonomies,
                renderer: $renderer,
                fieldValidator: $fieldValidator,
                hooks: $hooks,
                router: $router instanceof Router ? $router : null,
                revisionsMax: max(0, $revisionsMax),
                queue: $queue instanceof JobQueue ? $queue : null,
                mediaUsage: $mediaUsage instanceof MediaUsageRepository ? $mediaUsage : null,
                fields: $fields instanceof FieldRegistry ? $fields : null,
                media: $media instanceof MediaRepository ? $media : null,
            );
        });
    }
}
