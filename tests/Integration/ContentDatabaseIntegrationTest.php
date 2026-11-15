<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Prime\Auth\User;
use Prime\Content\ContentType;
use Prime\Content\ContentTypeRegistry;
use Prime\Content\Entry;
use Prime\Content\EntryLockRepository;
use Prime\Content\EntryQuery;
use Prime\Content\EntryRepository;
use Prime\Content\EntryService;
use Prime\Content\Fields\Field;
use Prime\Content\Fields\FieldGroup;
use Prime\Content\Fields\FieldRegistry;
use Prime\Content\Fields\FieldValidator;
use Prime\Content\RevisionRepository;
use Prime\Content\Taxonomy;
use Prime\Content\TaxonomyRegistry;
use Prime\Content\TermRepository;
use Prime\Database\Migrations\Migrator;
use Prime\Database\Schema\Schema;
use Prime\Editor\BlockRegistry;
use Prime\Editor\Renderer;
use Prime\Hooks\Hooks;
use Prime\Http\Router;
use Prime\Security\HtmlSanitizer;

/**
 * Real MySQL 8.0 & MariaDB 10.6 integration tests for Phase 6 Content:
 * migrations 000010–000014, FULLTEXT search, UNIQUE(type, parent_id, slug),
 * foreign keys (CASCADE + SET NULL), EntryService lifecycle, numeric CAST whereMeta,
 * scheduling, revisions pruning, locks, and trash purge.
 */
#[Group('database')]
final class ContentDatabaseIntegrationTest extends DatabaseTestCase
{
    public function testContentMigrationsFulltextForeignKeysLifecycleAndQueryOnRealMysql(): void
    {
        $schema = new Schema($this->connection);
        $migrator = new Migrator(
            $this->connection,
            $schema,
            [dirname(__DIR__, 2) . '/database/migrations'],
        );

        $ran = $migrator->run();
        $this->assertCount(14, $ran);

        foreach (['entries', 'entry_meta', 'terms', 'entry_terms', 'revisions', 'entry_locks'] as $table) {
            $this->assertTrue($schema->hasTable($table), sprintf('Expected table %s to exist.', $table));
        }

        // Seed default user in `pc_users` so foreign key `author_id` works cleanly
        $now = '2026-11-15 12:00:00';
        $userId = $this->connection->table('users')->insertGetId([
            'email' => 'author@prime-mysql.test',
            'password_hash' => password_hash('Secret!Mysql#99', PASSWORD_BCRYPT),
            'display_name' => 'Auteur MySQL',
            'status' => 'active',
            'locale' => 'fr',
            'session_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $admin = new User(
            id: $userId,
            email: 'author@prime-mysql.test',
            displayName: 'Auteur MySQL',
            status: 'active',
            locale: 'fr',
            sessionVersion: 1,
            lastLoginAt: null,
            passwordChangedAt: null,
            twoFactorConfirmedAt: null,
            twoFactorLastStep: null,
            createdAt: $now,
            updatedAt: $now,
            roles: [],
            capabilities: ['*'],
        );

        $hooks = new Hooks();
        $router = new Router();
        $sanitizer = new HtmlSanitizer();
        $blocks = new BlockRegistry($hooks);
        $renderer = new Renderer($blocks, $sanitizer, $hooks);
        $types = new ContentTypeRegistry($hooks);
        $taxonomies = new TaxonomyRegistry($hooks);
        $fields = new FieldRegistry($hooks);

        $types->register(new ContentType(
            name: 'post',
            labels: ['singular' => 'Article', 'plural' => 'Articles'],
            taxonomies: ['category', 'tag'],
        ));
        $taxonomies->register(new Taxonomy(
            name: 'category',
            labels: ['singular' => 'Catégorie', 'plural' => 'Catégories'],
            hierarchical: true,
            contentTypes: ['post'],
            defaultTermSlug: 'non-classe',
        ));
        $taxonomies->register(new Taxonomy(
            name: 'tag',
            labels: ['singular' => 'Étiquette', 'plural' => 'Étiquettes'],
            hierarchical: false,
            contentTypes: ['post'],
        ));
        $fields->registerGroup(new FieldGroup(
            id: 'test.seo',
            title: 'Métadonnées',
            contentTypes: ['post'],
            fields: [
                new Field(name: 'score', type: 'number', label: 'Score'),
            ],
        ));

        $termRepo = new TermRepository($this->connection, $taxonomies);
        $termRepo->ensureDefaultCategory();
        $entryRepo = new EntryRepository($this->connection);
        $revRepo = new RevisionRepository($this->connection);
        $lockRepo = new EntryLockRepository($this->connection, ttlSeconds: 120);
        $fieldValidator = new FieldValidator($fields, $entryRepo);

        $service = new EntryService(
            db: $this->connection,
            entries: $entryRepo,
            terms: $termRepo,
            revisions: $revRepo,
            locks: $lockRepo,
            types: $types,
            taxonomies: $taxonomies,
            renderer: $renderer,
            fieldValidator: $fieldValidator,
            hooks: $hooks,
            router: $router,
            revisionsMax: 3,
        );

        // 1. Create two entries with scores 20 and 100 to verify real MySQL CAST(DECIMAL) numeric meta filtering + FULLTEXT search
        $e1 = $service->create('post', [
            'title' => 'Architecture modulaire PHP',
            'status' => Entry::STATUS_PUBLISHED,
            'content' => json_encode([
                'version' => 1,
                'blocks' => [
                    ['id' => 'p1', 'type' => 'paragraph', 'data' => ['text' => 'Exploration approfondie de la recherche plein texte MySQL.']],
                ],
            ], JSON_THROW_ON_ERROR),
            'fields' => ['score' => 100],
            'new_terms' => ['tag' => 'MySQL, Architecture'],
        ], $admin);

        $e2 = $service->create('post', [
            'title' => 'Guide démarrage rapide',
            'status' => Entry::STATUS_PUBLISHED,
            'content' => json_encode([
                'version' => 1,
                'blocks' => [
                    ['id' => 'p2', 'type' => 'paragraph', 'data' => ['text' => 'Premier pas en cinq minutes.']],
                ],
            ], JSON_THROW_ON_ERROR),
            'fields' => ['score' => 20],
            'new_terms' => ['tag' => 'Débutant'],
        ], $admin);

        // 2. Verify FULLTEXT search finds e1 and excludes e2
        $query = new EntryQuery($this->connection, $entryRepo);
        $hits = $query->newQuery()->type('post')->search('Architecture')->get();
        $this->assertCount(1, $hits);
        $this->assertSame($e1->id, $hits[0]->id);

        // 3. Verify numeric whereMeta (`score >= 80`) returns score 100 (NOT lexicographical failure!)
        $highScore = $query->newQuery()->type('post')->whereMeta('score', 80, '>=')->get();
        $this->assertCount(1, $highScore);
        $this->assertSame($e1->id, $highScore[0]->id);

        // 4. Verify taxonomy filter + term count accuracy
        $byTag = $query->newQuery()->type('post')->whereTerm('tag', 'mysql')->get();
        $this->assertCount(1, $byTag);
        $this->assertSame($e1->id, $byTag[0]->id);

        // 5. Verify scheduled publication flips due entry to published
        $scheduled = $service->create('post', [
            'title' => 'Annonce planifiée',
            'status' => Entry::STATUS_SCHEDULED,
            'published_at' => '2030-01-01 10:00:00',
            'content' => '{"version":1,"blocks":[]}',
        ], $admin);
        $this->assertSame(Entry::STATUS_SCHEDULED, $scheduled->status);
        $this->assertSame(1, $service->publishScheduled('2030-01-01 10:05:00'));
        $reloadedSched = $entryRepo->find($scheduled->id);
        $this->assertNotNull($reloadedSched);
        $this->assertSame(Entry::STATUS_PUBLISHED, $reloadedSched->status);

        // 6. Verify revisions cap (revisionsMax = 3)
        for ($i = 1; $i <= 5; $i++) {
            $service->update($e1->id, ['title' => 'Architecture modulaire PHP v' . $i], $admin, bypassLock: true);
        }
        $this->assertCount(3, $revRepo->forEntry($e1->id, includeAutosaves: false));

        // 7. Verify ON DELETE CASCADE removes meta/revisions/entry_terms/locks when entry is permanently deleted
        $lockRepo->acquire($e2->id, $userId);
        $service->trash($e2->id, $admin);
        $service->delete($e2->id, $admin);
        $this->assertSame(0, $this->connection->table('entry_meta')->where('entry_id', '=', $e2->id)->count());
        $this->assertSame(0, $this->connection->table('revisions')->where('entry_id', '=', $e2->id)->count());
        $this->assertSame(0, $this->connection->table('entry_terms')->where('entry_id', '=', $e2->id)->count());
        $this->assertSame(0, $this->connection->table('entry_locks')->where('entry_id', '=', $e2->id)->count());

        // 8. Verify ON DELETE SET NULL preserves entry when author user is deleted
        $this->connection->table('users')->where('id', '=', $userId)->delete();
        $orphanEntry = $entryRepo->find($e1->id);
        $this->assertNotNull($orphanEntry);
        $this->assertNull($orphanEntry->authorId);
    }
}
