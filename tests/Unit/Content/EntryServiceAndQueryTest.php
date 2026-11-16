<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Content;

use PHPUnit\Framework\TestCase;
use Prime\Auth\User;
use Prime\Content\ContentTypeRegistry;
use Prime\Content\Entry;
use Prime\Content\EntryLockRepository;
use Prime\Content\EntryQuery;
use Prime\Content\EntryRepository;
use Prime\Content\EntryService;
use Prime\Content\Exception\ContentForbiddenException;
use Prime\Content\Exception\ContentLockedException;
use Prime\Content\Exception\ContentValidationException;
use Prime\Content\Fields\Field;
use Prime\Content\Fields\FieldGroup;
use Prime\Content\Fields\FieldRegistry;
use Prime\Content\Fields\FieldValidator;
use Prime\Content\RevisionRepository;
use Prime\Content\TaxonomyRegistry;
use Prime\Content\TermRepository;
use Prime\Database\Connection;
use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Schema;
use Prime\Editor\BlockRegistry;
use Prime\Editor\Renderer;
use Prime\Hooks\Hooks;
use Prime\Http\Response;
use Prime\Http\Router;
use Prime\Security\HtmlSanitizer;
use Prime\Tests\Support\MysqlEmulatingPdo;

final class EntryServiceAndQueryTest extends TestCase
{
    private Connection $db;
    private Hooks $hooks;
    private EntryRepository $entries;
    private TermRepository $terms;
    private RevisionRepository $revisions;
    private EntryLockRepository $locks;
    private EntryService $service;
    private EntryQuery $query;

    private User $admin;
    private User $editor;
    private User $author;
    private User $contributor;

    protected function setUp(): void
    {
        $pdo = new MysqlEmulatingPdo('id');
        $this->db = new Connection(['prefix' => 'pc_'], debug: true, pdo: $pdo);
        $schema = new Schema($this->db);

        foreach ([
            '2026_11_15_000002_create_users_table.php',
            '2026_11_15_000010_create_entries_table.php',
            '2026_11_15_000011_create_entry_meta_table.php',
            '2026_11_15_000012_create_revisions_table.php',
            '2026_11_15_000013_create_terms_tables.php',
            '2026_11_15_000014_create_entry_locks_table.php',
        ] as $file) {
            /** @var Migration $m */
            $m = require dirname(__DIR__, 3) . '/database/migrations/' . $file;
            $m->up($schema);
        }

        // Insert 4 users matching the 4 core roles
        $this->admin = $this->seedUser(1, 'Admin Prime', 'admin@example.com', ['*']);
        $this->editor = $this->seedUser(2, 'Claire Éditrice', 'editor@example.com', [
            'content.view', 'content.create', 'content.edit_own', 'content.edit_any',
            'content.publish', 'content.delete_own', 'content.delete_any',
        ]);
        $this->author = $this->seedUser(3, 'Lucas Auteur', 'author@example.com', [
            'content.view', 'content.create', 'content.edit_own', 'content.publish', 'content.delete_own',
        ]);
        $this->contributor = $this->seedUser(4, 'Nadia Contributrice', 'contrib@example.com', [
            'content.view', 'content.create', 'content.edit_own',
        ]);

        $this->hooks = new Hooks();
        $types = new ContentTypeRegistry($this->hooks);
        $taxonomies = new TaxonomyRegistry($this->hooks);
        $sanitizer = new HtmlSanitizer();
        $blocks = new BlockRegistry($this->hooks);
        $renderer = new Renderer($blocks, $sanitizer, $this->hooks);

        $fields = new FieldRegistry($this->hooks);
        $fields->registerGroup(new FieldGroup(
            id: 'test.seo',
            title: 'SEO',
            contentTypes: ['post', 'page'],
            fields: [new Field(name: 'seo_score', type: 'number', label: 'Score', min: 0, max: 100)],
        ));

        $router = new Router();
        $router->get('/custom-route/test', static fn (): Response => Response::text('ok'));

        $this->entries = new EntryRepository($this->db);
        $this->terms = new TermRepository($this->db, $taxonomies);
        $this->revisions = new RevisionRepository($this->db);
        $this->locks = new EntryLockRepository($this->db, ttlSeconds: 120);
        $this->query = new EntryQuery($this->db, $this->entries);

        $this->service = new EntryService(
            entries: $this->entries,
            terms: $this->terms,
            revisions: $this->revisions,
            locks: $this->locks,
            types: $types,
            taxonomies: $taxonomies,
            renderer: $renderer,
            fieldValidator: new FieldValidator($fields, $sanitizer, $this->db),
            hooks: $this->hooks,
            router: $router,
            revisionsMax: 3,
        );
    }

    public function testCompleteRolePermissionsMatrixAcrossTransitions(): void
    {
        // Contributor can create draft & pending
        $draft = $this->service->create('post', [
            'title' => 'Brouillon de Nadia',
            'status' => Entry::STATUS_DRAFT,
        ], $this->contributor);
        $this->assertSame(Entry::STATUS_DRAFT, $draft->status);

        $pending = $this->service->update($draft->id, ['status' => Entry::STATUS_PENDING], $this->contributor);
        $this->assertSame(Entry::STATUS_PENDING, $pending->status);

        // Contributor CANNOT publish
        try {
            $this->service->update($draft->id, ['status' => Entry::STATUS_PUBLISHED], $this->contributor);
            $this->fail('Contributor must not be allowed to publish.');
        } catch (ContentForbiddenException) {
            $this->addToAssertionCount(1);
        }

        // Contributor CANNOT trash even their own draft
        try {
            $this->service->trash($draft->id, $this->contributor);
            $this->fail('Contributor must not be allowed to trash.');
        } catch (ContentForbiddenException) {
            $this->addToAssertionCount(1);
        }

        // Editor CAN publish contributor's pending entry
        $published = $this->service->update($draft->id, ['status' => Entry::STATUS_PUBLISHED], $this->editor);
        $this->assertSame(Entry::STATUS_PUBLISHED, $published->status);

        // Contributor CANNOT edit their entry once published
        try {
            $this->service->update($published->id, ['title' => 'Modif non autorisée'], $this->contributor);
            $this->fail('Contributor must not edit an already-published entry.');
        } catch (ContentForbiddenException) {
            $this->addToAssertionCount(1);
        }

        // Author CAN create, publish, edit, and delete their own entry
        $authorPost = $this->service->create('post', [
            'title' => 'Article de Lucas',
            'status' => Entry::STATUS_PUBLISHED,
        ], $this->author);
        $this->assertSame(Entry::STATUS_PUBLISHED, $authorPost->status);

        // Author CANNOT edit or delete another author's entry
        try {
            $this->service->update($published->id, ['title' => 'Vol'], $this->author);
            $this->fail('Author must not edit another user entry.');
        } catch (ContentForbiddenException) {
            $this->addToAssertionCount(1);
        }

        try {
            $this->service->trash($published->id, $this->author);
            $this->fail('Author must not trash another user entry.');
        } catch (ContentForbiddenException) {
            $this->addToAssertionCount(1);
        }

        // Editor CANNOT insert raw HTML block (lacks `content.unfiltered_html`)
        try {
            $this->service->create('post', [
                'title' => 'Test HTML éditeur',
                'content' => ['version' => 1, 'blocks' => [['id' => 'h1', 'type' => 'html', 'data' => ['html' => '<b>hi</b>']]]],
            ], $this->editor);
            $this->fail('Editor without content.unfiltered_html must be rejected.');
        } catch (ContentValidationException $e) {
            $this->assertStringContainsString('content.unfiltered_html', implode(' ', $e->flatErrors()));
        }

        // Admin CAN insert raw HTML block
        $adminEntry = $this->service->create('post', [
            'title' => 'Test HTML admin',
            'content' => ['version' => 1, 'blocks' => [['id' => 'h1', 'type' => 'html', 'data' => ['html' => '<div class="ok">Admin</div>']]]],
        ], $this->admin);
        $this->assertStringContainsString('<div class="ok">Admin</div>', $adminEntry->contentHtml);
    }

    public function testSlugsReservedRootPagesAndSiblingUniqueness(): void
    {
        // Reserved root page slugs (both static list & Router-discovered `/custom-route`)
        $p1 = $this->service->create('page', ['title' => 'Admin'], $this->admin);
        $this->assertSame('admin-2', $p1->slug);

        $p2 = $this->service->create('page', ['title' => 'Custom Route'], $this->admin);
        $this->assertSame('custom-route-2', $p2->slug);

        // Duplicate siblings get -2, -3; child under another parent can reuse slug!
        $parentA = $this->service->create('page', ['title' => 'Services'], $this->admin);
        $parentB = $this->service->create('page', ['title' => 'Produits'], $this->admin);
        $dupParent = $this->service->create('page', ['title' => 'Services'], $this->admin);

        $this->assertSame('services', $parentA->slug);
        $this->assertSame('services-2', $dupParent->slug);

        $childA = $this->service->create('page', ['title' => 'Tarifs', 'parent_id' => $parentA->id], $this->admin);
        $childB = $this->service->create('page', ['title' => 'Tarifs', 'parent_id' => $parentB->id], $this->admin);
        $this->assertSame('tarifs', $childA->slug);
        $this->assertSame('tarifs', $childB->slug);
    }

    public function testSchedulingTrashRestoreRevisionsAutosaveAndLocks(): void
    {
        $events = [];
        $this->hooks->on('content.published', static function (Entry $e) use (&$events): void {
            $events[] = 'published:' . $e->id;
        });
        $this->hooks->on('content.trashed', static function (Entry $e) use (&$events): void {
            $events[] = 'trashed:' . $e->id;
        });
        $this->hooks->on('content.restored', static function (Entry $e) use (&$events): void {
            $events[] = 'restored:' . $e->id;
        });

        // Future published_at automatically turns `published` into `scheduled`
        $scheduled = $this->service->create('post', [
            'title' => 'Sortie future',
            'status' => Entry::STATUS_PUBLISHED,
            'published_at' => '2030-01-01 12:00:00',
        ], $this->admin);
        $this->assertSame(Entry::STATUS_SCHEDULED, $scheduled->status);

        // `publishScheduled()` with a future clock publishes it and fires hooks
        $count = $this->service->publishScheduled('2030-01-02 00:00:00');
        $this->assertSame(1, $count);
        $nowPub = $this->entries->find($scheduled->id);
        $this->assertNotNull($nowPub);
        $this->assertSame(Entry::STATUS_PUBLISHED, $nowPub->status);
        $this->assertContains('published:' . $scheduled->id, $events);

        // Trash remembers previous_status and restore brings it back
        $trashed = $this->service->trash($scheduled->id, $this->admin);
        $this->assertSame(Entry::STATUS_TRASH, $trashed->status);
        $this->assertSame(Entry::STATUS_PUBLISHED, $trashed->previousStatus);

        $restored = $this->service->restore($scheduled->id, $this->admin);
        $this->assertSame(Entry::STATUS_PUBLISHED, $restored->status);
        $this->assertContains('trashed:' . $scheduled->id, $events);
        $this->assertContains('restored:' . $scheduled->id, $events);

        // Revisions respect `revisionsMax = 3`, autosave stays strictly 1 row per user
        for ($i = 1; $i <= 5; $i++) {
            $this->service->update($scheduled->id, ['title' => 'Titre v' . $i], $this->admin);
        }
        $revs = $this->revisions->forEntry($scheduled->id);
        $this->assertCount(3, $revs);

        $this->service->autosave($scheduled->id, ['title' => 'Auto 1'], $this->admin);
        $this->service->autosave($scheduled->id, ['title' => 'Auto 2'], $this->admin);
        $allWithAutosave = $this->revisions->forEntry($scheduled->id, includeAutosaves: true);
        $autosaves = array_values(array_filter($allWithAutosave, static fn ($r): bool => $r->isAutosave));
        $this->assertCount(1, $autosaves);
        $this->assertSame('Auto 2', $autosaves[0]->title);

        // Edit locks: editor acquires -> admin cannot save until taking over -> editor gets `lost` on heartbeat
        $acq = $this->locks->acquire($scheduled->id, $this->editor->id);
        $this->assertTrue($acq['acquired']);

        try {
            $this->service->update($scheduled->id, ['title' => 'Conflit'], $this->admin);
            $this->fail('Displaced user must be blocked from saving while locked.');
        } catch (ContentLockedException $e) {
            $this->assertSame($this->editor->id, $e->lockedByUserId);
        }

        // Admin forcefully takes over lock
        $takeover = $this->locks->acquire($scheduled->id, $this->admin->id, force: true);
        $this->assertTrue($takeover['acquired']);

        $hb = $this->locks->heartbeat($scheduled->id, $this->editor->id);
        $this->assertSame('lost', $hb['status']);
        $this->assertNotNull($hb['lock']);
        $this->assertSame($this->admin->id, $hb['lock']->userId);
    }

    public function testEntryQueryVisibilityFiltersAndConstantQueryCount(): void
    {
        $catTech = $this->terms->create('category', 'Tech');
        $tagPhp = $this->terms->create('tag', 'PHP');

        for ($i = 1; $i <= 10; $i++) {
            $this->service->create('post', [
                'title' => sprintf('Article Prime #%d', $i),
                'status' => Entry::STATUS_PUBLISHED,
                'published_at' => '2025-06-01 10:00:00',
                'content' => [
                    'version' => 1,
                    'blocks' => [['id' => 'p1', 'type' => 'paragraph', 'data' => ['text' => 'Contenu searchable numéro ' . $i]]],
                ],
                'fields' => ['seo_score' => $i * 10],
                'terms' => [
                    'category' => [$catTech->id],
                    'tag' => $i % 2 === 0 ? [$tagPhp->id] : [],
                ],
            ], $this->admin);
        }

        // Add 1 private post by author & 1 draft post
        $this->service->create('post', [
            'title' => 'Secret de Lucas',
            'status' => Entry::STATUS_PRIVATE,
            'published_at' => '2025-06-02 10:00:00',
        ], $this->author);

        $this->service->create('post', [
            'title' => 'Brouillon invisible',
            'status' => Entry::STATUS_DRAFT,
        ], $this->admin);

        // Default public query sees ONLY the 10 published entries
        $this->assertSame(10, $this->query->newQuery()->type('post')->count());

        // Private viewer sees their own private entry too (11)
        $this->assertSame(11, $this->query->newQuery()->type('post')->includePrivateFor($this->author)->count());
        // Contributor does NOT see author's private entry (still 10)
        $this->assertSame(10, $this->query->newQuery()->type('post')->includePrivateFor($this->contributor)->count());
        // Editor (`content.edit_any`) sees all private entries (11)
        $this->assertSame(11, $this->query->newQuery()->type('post')->includePrivateFor($this->editor)->count());

        // Filter by term, meta, and search
        $tagged = $this->query->newQuery()->type('post')->whereTerm('tag', 'php')->get();
        $this->assertCount(5, $tagged);

        $highScore = $this->query->newQuery()->type('post')->whereMeta('seo_score', 80, '>=')->get();
        $this->assertCount(3, $highScore);

        $found = $this->query->newQuery()->type('post')->search('numéro 7')->get();
        $this->assertCount(1, $found);
        $this->assertSame('Article Prime #7', $found[0]->title);

        // CONSTANT QUERY COUNT CHECK: fetching 2 entries vs 10 entries with full meta, terms, authors
        $this->db->clearQueryLog();
        $small = $this->query->newQuery()->type('post')->limit(2)->get();
        $queriesFor2 = count($this->db->queryLog());

        $this->db->clearQueryLog();
        $large = $this->query->newQuery()->type('post')->limit(10)->get();
        $queriesFor10 = count($this->db->queryLog());

        $this->assertCount(2, $small);
        $this->assertCount(10, $large);
        $this->assertSame($queriesFor2, $queriesFor10, 'EntryQuery must execute a strictly constant number of SQL queries.');
        $this->assertLessThanOrEqual(4, $queriesFor10);
        // Verify eager-loaded relations are populated
        $this->assertSame('Tech', $large[0]->terms('category')[0]->name);
        $this->assertNotNull($large[0]->meta('seo_score'));
        $this->assertSame('Admin Prime', $large[0]->authorName);
    }

    /**
     * @param list<string> $capabilities
     */
    private function seedUser(int $id, string $name, string $email, array $capabilities): User
    {
        $now = '2026-11-15 10:00:00';
        $this->db->table('users')->insert([
            'id' => $id,
            'email' => $email,
            'password_hash' => 'hash',
            'display_name' => $name,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new User(
            id: $id,
            email: $email,
            displayName: $name,
            status: User::STATUS_ACTIVE,
            locale: 'fr',
            sessionVersion: 1,
            lastLoginAt: null,
            passwordChangedAt: null,
            twoFactorConfirmedAt: null,
            twoFactorLastStep: null,
            createdAt: $now,
            updatedAt: $now,
            roles: [],
            capabilities: $capabilities,
        );
    }
}
