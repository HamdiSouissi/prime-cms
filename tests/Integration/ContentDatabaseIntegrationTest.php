<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Prime\Admin\Menu\AdminMenu;
use Prime\Admin\Notices;
use Prime\Auth\Capabilities;
use Prime\Auth\User;
use Prime\Content\ContentType;
use Prime\Content\ContentTypeRegistry;
use Prime\Content\Entry;
use Prime\Content\EntryLockRepository;
use Prime\Content\EntryQuery;
use Prime\Content\EntryRepository;
use Prime\Content\EntryService;
use Prime\Content\Exception\ContentConflictException;
use Prime\Content\Exception\ContentException;
use Prime\Content\Exception\ContentForbiddenException;
use Prime\Content\Exception\ContentLockedException;
use Prime\Content\Fields\Field;
use Prime\Content\Fields\FieldGroup;
use Prime\Content\Fields\FieldRegistry;
use Prime\Content\Fields\FieldValidator;
use Prime\Content\RevisionRepository;
use Prime\Content\Taxonomy;
use Prime\Content\TaxonomyRegistry;
use Prime\Content\TermRepository;
use Prime\Database\Connection;
use Prime\Database\Migrations\Migrator;
use Prime\Database\Schema\Schema;
use Prime\Editor\BlockRegistry;
use Prime\Editor\Renderer;
use Prime\Hooks\Hooks;
use Prime\Http\Router;
use Prime\Install\InstallCheckerInterface;
use Prime\Kernel\Application;
use Prime\Kernel\Autoloader;
use Prime\Log\Logger;
use Prime\Plugins\AssetPublisher;
use Prime\Plugins\DependencyResolver;
use Prime\Plugins\FatalLoadGuard;
use Prime\Plugins\ManifestValidator;
use Prime\Plugins\PluginManager;
use Prime\Plugins\PluginRepository;
use Prime\Security\HtmlSanitizer;
use Prime\Settings\Settings;
use Prime\View\View;

/**
 * Real MySQL 8.0 & MariaDB 10.6 integration tests for Phase 6 Content:
 * migrations 000010–000014, FULLTEXT search, UNIQUE(type, parent_id, slug),
 * foreign keys (CASCADE + SET NULL), full 4-role RBAC matrix, C2 tabs/filters/visibility,
 * edit locks lifecycle, autosave & revision restore, trash/restore/C5 409 delete guard,
 * duplication, taxonomies CRUD/default protection/reparenting/counts,
 * EntryQuery 0 N+1 query count proof, and hello-prime plugin lifecycle.
 */
#[Group('database')]
final class ContentDatabaseIntegrationTest extends DatabaseTestCase
{
    private string $tmpRoot = '';

    protected function tearDown(): void
    {
        if ($this->tmpRoot !== '' && is_dir($this->tmpRoot)) {
            AssetPublisher::deleteDirectory($this->tmpRoot);
        }
        $this->tmpRoot = '';
        parent::tearDown();
    }

    public function testContentMigrationsFulltextForeignKeysLifecycleAndQueryOnRealMysql(): void
    {
        $stack = $this->bootContentStack(revisionsMax: 3);
        $admin = $stack['users']['admin'];
        $service = $stack['service'];
        $entryRepo = $stack['entryRepo'];
        $revRepo = $stack['revRepo'];
        $lockRepo = $stack['lockRepo'];
        $query = $stack['query'];

        $schema = new Schema($this->connection);
        foreach (['entries', 'entry_meta', 'terms', 'entry_terms', 'revisions', 'entry_locks'] as $table) {
            $this->assertTrue($schema->hasTable($table), sprintf('Expected table %s to exist.', $table));
        }

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
        $lockRepo->acquire($e2->id, $admin->id);
        $service->trash($e2->id, $admin);
        $service->delete($e2->id, $admin);
        $this->assertSame(0, $this->connection->table('entry_meta')->where('entry_id', '=', $e2->id)->count());
        $this->assertSame(0, $this->connection->table('revisions')->where('entry_id', '=', $e2->id)->count());
        $this->assertSame(0, $this->connection->table('entry_terms')->where('entry_id', '=', $e2->id)->count());
        $this->assertSame(0, $this->connection->table('entry_locks')->where('entry_id', '=', $e2->id)->count());

        // 8. Verify ON DELETE SET NULL preserves entry when author user is deleted
        $this->connection->table('users')->where('id', '=', $admin->id)->delete();
        $orphanEntry = $entryRepo->find($e1->id);
        $this->assertNotNull($orphanEntry);
        $this->assertNull($orphanEntry->authorId);
    }

    public function testCompleteRolePermissionMatrixAcrossAllFourRoles(): void
    {
        $stack = $this->bootContentStack();
        $service = $stack['service'];
        $entryRepo = $stack['entryRepo'];
        $contributor = $stack['users']['contributor'];
        $author = $stack['users']['author'];
        $editor = $stack['users']['editor'];
        $admin = $stack['users']['admin'];

        // --- CONTRIBUTOR ---
        // Can create draft
        $contribDraft = $service->create('post', [
            'title' => 'Brouillon Contrib',
            'status' => Entry::STATUS_DRAFT,
            'content' => '{"version":1,"blocks":[]}',
        ], $contributor);
        $this->assertSame(Entry::STATUS_DRAFT, $contribDraft->status);

        // Can edit own draft
        $contribDraft = $service->update($contribDraft->id, ['title' => 'Brouillon Contrib v2'], $contributor, bypassLock: true);
        $this->assertSame('Brouillon Contrib v2', $contribDraft->title);

        // Cannot publish directly
        $this->assertForbidden(fn () => $service->create('post', [
            'title' => 'Pub interdite',
            'status' => Entry::STATUS_PUBLISHED,
            'content' => '{"version":1,"blocks":[]}',
        ], $contributor));

        // Can submit for review (`pending`), then CANNOT edit while pending (C6)
        $contribPending = $service->update($contribDraft->id, ['status' => Entry::STATUS_PENDING], $contributor, bypassLock: true);
        $this->assertSame(Entry::STATUS_PENDING, $contribPending->status);
        $this->assertForbidden(fn () => $service->update($contribPending->id, ['title' => 'Modif après soumission'], $contributor, bypassLock: true));

        // Cannot trash, restore, or delete own or others' entries
        $this->assertForbidden(fn () => $service->trash($contribPending->id, $contributor));

        // --- AUTHOR ---
        // Can create draft, edit own, publish own, trash own, restore own, delete own trashed
        $authorPost = $service->create('post', [
            'title' => 'Article Auteur',
            'status' => Entry::STATUS_PUBLISHED,
            'content' => '{"version":1,"blocks":[]}',
        ], $author);
        $this->assertSame(Entry::STATUS_PUBLISHED, $authorPost->status);

        $authorPost = $service->update($authorPost->id, ['title' => 'Article Auteur Édité'], $author, bypassLock: true);
        $this->assertSame('Article Auteur Édité', $authorPost->title);

        // Author CANNOT edit, trash, or delete another user's entry
        $this->assertForbidden(fn () => $service->update($contribPending->id, ['title' => 'Vol Auteur'], $author, bypassLock: true));
        $this->assertForbidden(fn () => $service->trash($contribPending->id, $author));

        // Contributor CANNOT edit author's entry
        $this->assertForbidden(fn () => $service->update($authorPost->id, ['title' => 'Vol Contrib'], $contributor, bypassLock: true));

        // Author CAN trash, restore, and permanently delete own entry
        $authorTrashed = $service->trash($authorPost->id, $author);
        $this->assertSame(Entry::STATUS_TRASH, $authorTrashed->status);
        $authorRestored = $service->restore($authorPost->id, $author);
        $this->assertSame(Entry::STATUS_PUBLISHED, $authorRestored->status);
        $service->trash($authorPost->id, $author);
        $service->delete($authorPost->id, $author);
        $this->assertNull($entryRepo->find($authorPost->id));

        // --- EDITOR ---
        // Can edit & publish contributor's pending entry, trash, restore, and permanently delete others' entries
        $publishedByEditor = $service->update($contribPending->id, [
            'title' => 'Validé par Éditeur',
            'status' => Entry::STATUS_PUBLISHED,
        ], $editor, bypassLock: true);
        $this->assertSame(Entry::STATUS_PUBLISHED, $publishedByEditor->status);

        // Once published, contributor STILL cannot edit it (no publish/edit_any)
        $this->assertForbidden(fn () => $service->update($publishedByEditor->id, ['title' => 'Tentative'], $contributor, bypassLock: true));

        $service->trash($publishedByEditor->id, $editor);
        $restoredByEditor = $service->restore($publishedByEditor->id, $editor);
        $this->assertSame(Entry::STATUS_PUBLISHED, $restoredByEditor->status);

        // --- ADMIN ---
        // Full control over any entry including trash + permanent delete
        $adminEdited = $service->update($publishedByEditor->id, ['title' => 'Édité Admin'], $admin, bypassLock: true);
        $this->assertSame('Édité Admin', $adminEdited->title);
        $service->trash($publishedByEditor->id, $admin);
        $service->delete($publishedByEditor->id, $admin);
        $this->assertNull($entryRepo->find($publishedByEditor->id));
    }

    public function testListTabsSearchFiltersAndRoleVisibilityMatchTabCounts(): void
    {
        $stack = $this->bootContentStack();
        $service = $stack['service'];
        $entryRepo = $stack['entryRepo'];
        $termRepo = $stack['termRepo'];
        $query = $stack['query'];
        $contributor = $stack['users']['contributor'];
        $author = $stack['users']['author'];
        $admin = $stack['users']['admin'];

        $catGuides = $termRepo->create('category', 'Guides', 'guides');
        $catNews = $termRepo->create('category', 'Actualités', 'actualites');

        // Seed entries across ALL 6 statuses, authors, categories, and months
        $ePubAdmin = $service->create('post', [
            'title' => 'Publication Admin Phare',
            'status' => Entry::STATUS_PUBLISHED,
            'published_at' => '2026-11-10 10:00:00',
            'terms' => ['category' => [$catGuides->id]],
            'content' => '{"version":1,"blocks":[{"id":"b1","type":"paragraph","data":{"text":"Contenu phare novembre."}}]}',
        ], $admin);

        $eDraftAdmin = $service->create('post', [
            'title' => 'Brouillon Privatif Admin',
            'status' => Entry::STATUS_DRAFT,
            'terms' => ['category' => [$catNews->id]],
            'content' => '{"version":1,"blocks":[]}',
        ], $admin);
        // Backdate created_at to September 2026 to exercise month filter
        $this->connection->table('entries')->where('id', '=', $eDraftAdmin->id)->update(['created_at' => '2026-09-12 09:00:00']);

        $ePrivateAdmin = $service->create('post', [
            'title' => 'Note Interne Privée',
            'status' => Entry::STATUS_PRIVATE,
            'content' => '{"version":1,"blocks":[]}',
        ], $admin);

        $eScheduledAdmin = $service->create('post', [
            'title' => 'Lancement Futur',
            'status' => Entry::STATUS_SCHEDULED,
            'published_at' => '2030-05-01 08:00:00',
            'content' => '{"version":1,"blocks":[]}',
        ], $admin);

        $ePendingContrib = $service->create('post', [
            'title' => 'Soumission Contributeur',
            'status' => Entry::STATUS_PENDING,
            'terms' => ['category' => [$catGuides->id]],
            'content' => '{"version":1,"blocks":[]}',
        ], $contributor);

        $eDraftAuthor = $service->create('post', [
            'title' => 'Brouillon Auteur',
            'status' => Entry::STATUS_DRAFT,
            'content' => '{"version":1,"blocks":[]}',
        ], $author);

        $eTrashedAdmin = $service->create('post', [
            'title' => 'Ancien Article Corbeille',
            'status' => Entry::STATUS_DRAFT,
            'content' => '{"version":1,"blocks":[]}',
        ], $admin);
        $service->trash($eTrashedAdmin->id, $admin);

        // 1. Admin tab counts MUST equal exact EntryQuery row counts for `all` and every status tab
        $adminCounts = $entryRepo->countByStatus('post');
        $this->assertSame(6, $adminCounts['all']);
        $this->assertSame(1, $adminCounts[Entry::STATUS_PUBLISHED]);
        $this->assertSame(2, $adminCounts[Entry::STATUS_DRAFT]);
        $this->assertSame(1, $adminCounts[Entry::STATUS_PENDING]);
        $this->assertSame(1, $adminCounts[Entry::STATUS_SCHEDULED]);
        $this->assertSame(1, $adminCounts[Entry::STATUS_PRIVATE]);
        $this->assertSame(1, $adminCounts[Entry::STATUS_TRASH]);

        $allRows = $query->newQuery()->type('post')->includeFuture(true)->anyStatus(includeTrash: false)->visibleInAdminFor($admin)->get();
        $this->assertCount($adminCounts['all'], $allRows);

        foreach (Entry::ALL_STATUSES as $st) {
            $tabRows = $query->newQuery()->type('post')->includeFuture(true)->status($st)->visibleInAdminFor($admin)->get();
            $this->assertCount($adminCounts[$st], $tabRows, "Row count mismatch for admin tab [{$st}]");
        }

        // 2. Contributor visibility: sees ONLY own entries + published entries (never Admin/Author drafts, private, scheduled, trash)
        $contribCounts = $entryRepo->countByStatus('post', $contributor->id);
        $this->assertSame(2, $contribCounts['all']); // ePubAdmin + ePendingContrib
        $this->assertSame(1, $contribCounts[Entry::STATUS_PUBLISHED]);
        $this->assertSame(1, $contribCounts[Entry::STATUS_PENDING]);
        $this->assertSame(0, $contribCounts[Entry::STATUS_DRAFT]);
        $this->assertSame(0, $contribCounts[Entry::STATUS_PRIVATE]);
        $this->assertSame(0, $contribCounts[Entry::STATUS_TRASH]);

        $contribAll = $query->newQuery()->type('post')->includeFuture(true)->anyStatus(includeTrash: false)->visibleInAdminFor($contributor)->get();
        $this->assertCount($contribCounts['all'], $contribAll);
        $contribIds = array_map(static fn (Entry $e): int => $e->id, $contribAll);
        sort($contribIds);
        $expectedContribIds = [$ePubAdmin->id, $ePendingContrib->id];
        sort($expectedContribIds);
        $this->assertSame($expectedContribIds, $contribIds);

        // Author visibility: sees own draft + published entry (not admin's draft/private or contributor's pending)
        $authorCounts = $entryRepo->countByStatus('post', $author->id);
        $this->assertSame(2, $authorCounts['all']);
        $authorAll = $query->newQuery()->type('post')->includeFuture(true)->anyStatus(includeTrash: false)->visibleInAdminFor($author)->get();
        $this->assertCount($authorCounts['all'], $authorAll);

        // 3. Search, taxonomy, author, and month filters narrow list results accurately
        $searchHits = $query->newQuery()->type('post')->includeFuture(true)->anyStatus(includeTrash: false)->search('Phare')->get();
        $this->assertCount(1, $searchHits);
        $this->assertSame($ePubAdmin->id, $searchHits[0]->id);

        $catHits = $query->newQuery()->type('post')->includeFuture(true)->anyStatus(includeTrash: false)->whereTerm('category', 'guides')->get();
        $this->assertCount(2, $catHits);

        $authorHits = $query->newQuery()->type('post')->includeFuture(true)->anyStatus(includeTrash: false)->author($contributor->id)->get();
        $this->assertCount(1, $authorHits);
        $this->assertSame($ePendingContrib->id, $authorHits[0]->id);

        $monthHits = $query->newQuery()->type('post')->includeFuture(true)->anyStatus(includeTrash: false)->month('2026-09')->get();
        $this->assertCount(1, $monthHits);
        $this->assertSame($eDraftAdmin->id, $monthHits[0]->id);

        // Suppress unused variable notice while proving private/scheduled were created
        $this->assertNotSame($ePrivateAdmin->id, $eScheduledAdmin->id);
        $this->assertSame(Entry::STATUS_DRAFT, $eDraftAuthor->status);
    }

    public function testEditLocksLifecycleHeartbeatExpiryTakeoverAndSaveRefusal(): void
    {
        $stack = $this->bootContentStack();
        $service = $stack['service'];
        $lockRepo = $stack['lockRepo'];
        $admin = $stack['users']['admin'];
        $editor = $stack['users']['editor'];

        $entry = $service->create('post', [
            'title' => 'Article Verrouillé',
            'status' => Entry::STATUS_DRAFT,
            'content' => '{"version":1,"blocks":[]}',
        ], $admin);

        // 1. Admin acquires lock -> heartbeat succeeds -> NOT locked by other
        $acq = $lockRepo->acquire($entry->id, $admin->id);
        $this->assertTrue($acq['acquired']);
        $this->assertNull($lockRepo->lockedByOther($entry->id, $admin->id));

        $hbAdmin = $lockRepo->heartbeat($entry->id, $admin->id);
        $this->assertSame('ok', $hbAdmin['status']);
        $this->assertSame($admin->id, $hbAdmin['lock']->userId);

        // 2. Editor sees lock held by Admin -> heartbeat fails -> save refused with ContentLockedException
        $otherLock = $lockRepo->lockedByOther($entry->id, $editor->id);
        $this->assertNotNull($otherLock);
        $this->assertSame($admin->id, $otherLock->userId);

        $hbEditor = $lockRepo->heartbeat($entry->id, $editor->id);
        $this->assertSame('lost', $hbEditor['status']);

        $lockedThrown = false;
        try {
            $service->update($entry->id, ['title' => 'Sauvegarde concurrente'], $editor);
        } catch (ContentLockedException) {
            $lockedThrown = true;
        }
        $this->assertTrue($lockedThrown, 'Expected ContentLockedException when saving entry locked by another user.');

        // 3. Simulate 2-minute TTL expiry (>120s) -> Editor acquires lock cleanly without force
        $this->connection->table('entry_locks')
            ->where('entry_id', '=', $entry->id)
            ->update(['heartbeat_at' => gmdate('Y-m-d H:i:s', time() - 135)]);

        $this->assertNull($lockRepo->lockedByOther($entry->id, $editor->id));
        $acqEditor = $lockRepo->acquire($entry->id, $editor->id);
        $this->assertTrue($acqEditor['acquired']);
        $this->assertSame($editor->id, $acqEditor['lock']->userId);

        // 4. Admin forces takeover -> Editor save now refused -> Admin save succeeds
        $takeover = $lockRepo->acquire($entry->id, $admin->id, force: true);
        $this->assertTrue($takeover['acquired']);
        $this->assertSame($admin->id, $takeover['lock']->userId);

        $editorRefused = false;
        try {
            $service->update($entry->id, ['title' => 'Après reprise'], $editor);
        } catch (ContentLockedException) {
            $editorRefused = true;
        }
        $this->assertTrue($editorRefused, 'Expected ContentLockedException for user whose lock was taken over.');

        $savedByAdmin = $service->update($entry->id, ['title' => 'Sauvegardé par Admin'], $admin);
        $this->assertSame('Sauvegardé par Admin', $savedByAdmin->title);
    }

    public function testAutosaveSinglePerUserAndRevisionRestore(): void
    {
        $stack = $this->bootContentStack();
        $service = $stack['service'];
        $revRepo = $stack['revRepo'];
        $admin = $stack['users']['admin'];
        $editor = $stack['users']['editor'];

        $v1Blocks = json_encode([
            'version' => 1,
            'blocks' => [['id' => 'p1', 'type' => 'paragraph', 'data' => ['text' => 'Texte initial v1.']]],
        ], JSON_THROW_ON_ERROR);

        $entry = $service->create('post', [
            'title' => 'Titre v1',
            'status' => Entry::STATUS_DRAFT,
            'content' => $v1Blocks,
            'fields' => ['score' => 10],
        ], $admin);

        // Multiple autosaves by Admin overwrite the SAME single autosave row for (entry, admin)
        $service->autosave($entry->id, ['title' => 'Auto Admin 1', 'content' => $v1Blocks], $admin);
        $service->autosave($entry->id, ['title' => 'Auto Admin 2', 'content' => $v1Blocks], $admin);

        // Editor gets their own single autosave row for (entry, editor)
        $service->autosave($entry->id, ['title' => 'Auto Editor 1', 'content' => $v1Blocks], $editor);
        $service->autosave($entry->id, ['title' => 'Auto Editor 2', 'content' => $v1Blocks], $editor);

        $adminAutoRows = $this->connection->table('revisions')
            ->where('entry_id', '=', $entry->id)
            ->where('author_id', '=', $admin->id)
            ->where('is_autosave', '=', 1)
            ->count();
        $editorAutoRows = $this->connection->table('revisions')
            ->where('entry_id', '=', $entry->id)
            ->where('author_id', '=', $editor->id)
            ->where('is_autosave', '=', 1)
            ->count();
        $this->assertSame(1, $adminAutoRows, 'Each user must have at most one autosave row per entry.');
        $this->assertSame(1, $editorAutoRows);

        $latestAdminAuto = $revRepo->latestAutosave($entry->id, $admin->id);
        $this->assertNotNull($latestAdminAuto);
        $this->assertSame('Auto Admin 2', $latestAdminAuto->title);

        // Update entry to v2 then restore revision v1 cleanly
        $v2Blocks = json_encode([
            'version' => 1,
            'blocks' => [['id' => 'p2', 'type' => 'paragraph', 'data' => ['text' => 'Texte modifié v2.']]],
        ], JSON_THROW_ON_ERROR);
        $service->update($entry->id, [
            'title' => 'Titre v2',
            'content' => $v2Blocks,
            'fields' => ['score' => 99],
        ], $admin, bypassLock: true);

        $revisions = $revRepo->forEntry($entry->id, includeAutosaves: false);
        $this->assertGreaterThanOrEqual(2, count($revisions));
        $v1Revision = $revisions[count($revisions) - 1];
        $this->assertSame('Titre v1', $v1Revision->title);

        $restored = $service->restoreRevision($entry->id, $v1Revision->id, $admin);
        $this->assertSame('Titre v1', $restored->title);
        $this->assertStringContainsString('Texte initial v1.', $restored->contentHtml);
        $this->assertSame(10, $restored->meta('score'));
    }

    public function testTrashRestorePastScheduledReturnsDraftAndPermanentDeleteGuard(): void
    {
        $stack = $this->bootContentStack();
        $service = $stack['service'];
        $entryRepo = $stack['entryRepo'];
        $admin = $stack['users']['admin'];

        // 1. Permanent deletion outside trash MUST throw ContentConflictException (C5)
        $draft = $service->create('post', [
            'title' => 'Protégé hors corbeille',
            'status' => Entry::STATUS_DRAFT,
            'content' => '{"version":1,"blocks":[]}',
        ], $admin);

        $conflictThrown = false;
        try {
            $service->delete($draft->id, $admin);
        } catch (ContentConflictException) {
            $conflictThrown = true;
        }
        $this->assertTrue($conflictThrown, 'Deleting a non-trashed entry must throw ContentConflictException.');
        $this->assertNotNull($entryRepo->find($draft->id));

        // 2. Scheduled entry with PAST published_at trashed & restored MUST return as `draft`
        $pastScheduled = $service->create('post', [
            'title' => 'Planifié Expiré',
            'status' => Entry::STATUS_SCHEDULED,
            'published_at' => '2030-01-01 08:00:00',
            'content' => '{"version":1,"blocks":[]}',
        ], $admin);
        $service->trash($pastScheduled->id, $admin);
        // Simulate time passing so scheduled date is now in the past
        $this->connection->table('entries')
            ->where('id', '=', $pastScheduled->id)
            ->update(['published_at' => '2020-01-01 08:00:00']);

        $restoredPast = $service->restore($pastScheduled->id, $admin);
        $this->assertSame(Entry::STATUS_DRAFT, $restoredPast->status);

        // 3. Scheduled entry with FUTURE published_at trashed & restored stays `scheduled`
        $futureScheduled = $service->create('post', [
            'title' => 'Planifié Futur',
            'status' => Entry::STATUS_SCHEDULED,
            'published_at' => '2035-06-15 12:00:00',
            'content' => '{"version":1,"blocks":[]}',
        ], $admin);
        $service->trash($futureScheduled->id, $admin);
        $restoredFuture = $service->restore($futureScheduled->id, $admin);
        $this->assertSame(Entry::STATUS_SCHEDULED, $restoredFuture->status);

        // 4. Trashing draft then deleting succeeds cleanly
        $service->trash($draft->id, $admin);
        $service->delete($draft->id, $admin);
        $this->assertNull($entryRepo->find($draft->id));
    }

    public function testDuplicationCopiesContentMetaTermsAndResetsToDraft(): void
    {
        $stack = $this->bootContentStack();
        $service = $stack['service'];
        $termRepo = $stack['termRepo'];
        $admin = $stack['users']['admin'];

        $cat = $termRepo->create('category', 'Technologie', 'technologie');

        $original = $service->create('post', [
            'title' => 'Original Complet',
            'status' => Entry::STATUS_PUBLISHED,
            'content' => json_encode([
                'version' => 1,
                'blocks' => [['id' => 'p1', 'type' => 'paragraph', 'data' => ['text' => 'Paragraphe source.']]],
            ], JSON_THROW_ON_ERROR),
            'fields' => ['score' => 77],
            'terms' => ['category' => [$cat->id]],
            'new_terms' => ['tag' => 'PHP8'],
        ], $admin);

        $copy = $service->duplicate($original->id, $admin);

        $this->assertNotSame($original->id, $copy->id);
        $this->assertSame(Entry::STATUS_DRAFT, $copy->status);
        $this->assertNull($copy->publishedAt);
        $this->assertStringContainsString('Original Complet', $copy->title);
        $this->assertNotSame($original->slug, $copy->slug);
        $this->assertSame(77, $copy->meta('score'));
        $this->assertStringContainsString('Paragraphe source.', $copy->contentHtml);
        $this->assertSame($original->termIds(), $copy->termIds());
    }

    public function testTaxonomiesCrudDefaultProtectionReparentingAndCounts(): void
    {
        $stack = $this->bootContentStack();
        $service = $stack['service'];
        $termRepo = $stack['termRepo'];
        $admin = $stack['users']['admin'];

        // 1. Default category `non-classe` CANNOT be deleted
        $defaultCat = $termRepo->findBySlug('category', 'non-classe');
        $this->assertNotNull($defaultCat);

        $protected = false;
        try {
            $termRepo->delete($defaultCat->id);
        } catch (ContentException) {
            $protected = true;
        }
        $this->assertTrue($protected, 'Default term `non-classe` must be protected against deletion.');

        // 2. Hierarchical CRUD: parent -> child -> grandchild + update
        $parent = $termRepo->create('category', 'Développement', 'developpement');
        $child = $termRepo->create('category', 'Backend', 'backend', parentId: $parent->id);
        $grandChild = $termRepo->create('category', 'PHP', 'php', parentId: $child->id);

        $updatedChild = $termRepo->update($child->id, name: 'Backend & API', slug: 'backend-api', parentId: $parent->id);
        $this->assertSame('Backend & API', $updatedChild->name);
        $this->assertSame('backend-api', $updatedChild->slug);

        // 3. Counts reflect attached non-trashed entries accurately
        $entry = $service->create('post', [
            'title' => 'Article Backend',
            'status' => Entry::STATUS_PUBLISHED,
            'terms' => ['category' => [$updatedChild->id]],
            'content' => '{"version":1,"blocks":[]}',
        ], $admin);

        $reloadedChild = $termRepo->find($updatedChild->id);
        $this->assertNotNull($reloadedChild);
        $this->assertSame(1, $reloadedChild->count);

        // Trashing entry decrements count back to 0, restoring increments back to 1
        $service->trash($entry->id, $admin);
        $this->assertSame(0, $termRepo->find($updatedChild->id)?->count);
        $service->restore($entry->id, $admin);
        $this->assertSame(1, $termRepo->find($updatedChild->id)?->count);

        // 4. Deleting middle node (`child`) reparents `grandChild` cleanly to `$parent->id`!
        $termRepo->delete($updatedChild->id);
        $this->assertNull($termRepo->find($updatedChild->id));
        $reparented = $termRepo->find($grandChild->id);
        $this->assertNotNull($reparented);
        $this->assertSame($parent->id, $reparented->parentId);
    }

    public function testEntryQueryZeroNPlusOneConstantSqlCountFor2Vs20Entries(): void
    {
        $stack = $this->bootContentStack();
        $service = $stack['service'];
        $termRepo = $stack['termRepo'];
        $query = $stack['query'];
        $admin = $stack['users']['admin'];

        $cat = $termRepo->create('category', 'Performance', 'performance');
        $tag = $termRepo->create('tag', 'SQL', 'sql');

        $seedEntry = static function (int $i) use ($service, $admin, $cat, $tag): void {
            $service->create('post', [
                'title' => 'Article Perf #' . $i,
                'status' => Entry::STATUS_PUBLISHED,
                'fields' => ['score' => $i * 5],
                'terms' => ['category' => [$cat->id], 'tag' => [$tag->id]],
                'content' => '{"version":1,"blocks":[{"id":"p1","type":"paragraph","data":{"text":"Contenu perf."}}]}',
            ], $admin);
        };

        // Seed 2 entries and count SQL queries executed by EntryQuery with meta + terms + authors
        $seedEntry(1);
        $seedEntry(2);

        $this->connection->clearQueryLog();
        $twoEntries = $query->newQuery()
            ->type('post')
            ->status(Entry::STATUS_PUBLISHED)
            ->withRelations(meta: true, terms: true, authors: true)
            ->get();
        $sqlCountFor2 = count($this->connection->queryLog());

        $this->assertCount(2, $twoEntries);
        $scores = array_map(static fn (Entry $e): mixed => $e->meta('score'), $twoEntries);
        sort($scores);
        $this->assertSame([5, 10], $scores);
        $this->assertNotEmpty($twoEntries[0]->terms);
        $this->assertNotNull($twoEntries[0]->authorName);

        // Seed 18 more entries (20 total) and assert IDENTICAL SQL query count (0 N+1)
        for ($i = 3; $i <= 20; $i++) {
            $seedEntry($i);
        }

        $this->connection->clearQueryLog();
        $twentyEntries = $query->newQuery()
            ->type('post')
            ->status(Entry::STATUS_PUBLISHED)
            ->withRelations(meta: true, terms: true, authors: true)
            ->get();
        $sqlCountFor20 = count($this->connection->queryLog());

        $this->assertCount(20, $twentyEntries);
        foreach ($twentyEntries as $item) {
            $this->assertNotNull($item->meta('score'));
            $this->assertCount(2, $item->terms);
            $this->assertNotNull($item->authorName);
        }
        $this->assertSame(
            $sqlCountFor2,
            $sqlCountFor20,
            sprintf('Expected identical SQL count for 2 vs 20 entries (0 N+1), got %d vs %d.', $sqlCountFor2, $sqlCountFor20),
        );
    }

    public function testHelloPrimePluginTestimonialCptTaxonomyFieldsBlockAndUninstallOnMysql(): void
    {
        $schema = new Schema($this->connection);
        $migrator = new Migrator(
            $this->connection,
            $schema,
            [dirname(__DIR__, 2) . '/database/migrations'],
        );
        $migrator->run();

        $app = new Application(dirname(__DIR__, 2), loadEnv: false);
        $app->config()->set('app.env', 'testing');
        $app->config()->set('app.debug', true);
        $app->config()->set('app.key', 'base64:' . base64_encode(str_repeat('m', 32)));
        $app->boot();

        $app->container()->instance(InstallCheckerInterface::class, new class () implements InstallCheckerInterface {
            public function isInstalled(): bool
            {
                return true;
            }
        });
        $app->container()->instance(Connection::class, $this->connection);

        $this->tmpRoot = sys_get_temp_dir() . '/prime-mysql-hp-' . bin2hex(random_bytes(5));
        mkdir($this->tmpRoot . '/public-assets', 0755, true);
        mkdir($this->tmpRoot . '/logs', 0755, true);
        mkdir($this->tmpRoot . '/storage', 0755, true);

        $autoloader = new Autoloader();
        $autoloader->register();

        $manager = new PluginManager(
            app: $app,
            pluginsPath: dirname(__DIR__, 2) . '/content/plugins',
            autoloader: $autoloader,
            repository: new PluginRepository($this->connection, $schema),
            validator: new ManifestValidator(),
            resolver: new DependencyResolver(),
            assetPublisher: new AssetPublisher($this->tmpRoot . '/public-assets', '/assets/plugins'),
            fatalGuard: new FatalLoadGuard($this->tmpRoot . '/storage/loading.json'),
            migrator: $migrator,
            hooks: $app->get(Hooks::class),
            router: $app->get(Router::class),
            views: $app->get(View::class),
            adminMenu: $app->get(AdminMenu::class),
            capabilities: $app->get(Capabilities::class),
            settings: $app->get(Settings::class),
            db: $this->connection,
            notices: $app->get(Notices::class),
            logger: new Logger($this->tmpRoot . '/logs'),
        );

        $manager->activate('hello-prime');

        /** @var ContentTypeRegistry $types */
        $types = $app->get(ContentTypeRegistry::class);
        /** @var TaxonomyRegistry $taxonomies */
        $taxonomies = $app->get(TaxonomyRegistry::class);
        /** @var FieldRegistry $fields */
        $fields = $app->get(FieldRegistry::class);
        /** @var BlockRegistry $blocks */
        $blocks = $app->get(BlockRegistry::class);
        /** @var EntryService $entryService */
        $entryService = $app->get(EntryService::class);
        /** @var EntryRepository $entryRepo */
        $entryRepo = $app->get(EntryRepository::class);
        /** @var TermRepository $termRepo */
        $termRepo = $app->get(TermRepository::class);

        $this->assertTrue($types->has('testimonial'));
        $this->assertTrue($taxonomies->has('testimonial_source'));
        $this->assertNotEmpty($fields->forContentType('testimonial'));
        $this->assertTrue($blocks->has('hello-prime/highlight'));

        $now = '2026-11-15 12:00:00';
        $adminId = $this->connection->table('users')->insertGetId([
            'email' => 'hp-admin@prime-mysql.test',
            'password_hash' => password_hash('Secret!Mysql#99', PASSWORD_BCRYPT),
            'display_name' => 'Admin HP',
            'status' => 'active',
            'locale' => 'fr',
            'session_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $admin = $this->makeUser($adminId, 'hp-admin@prime-mysql.test', 'Admin HP', ['*'], $now);

        $testimonial = $entryService->create('testimonial', [
            'title' => 'Témoignage MySQL',
            'status' => Entry::STATUS_PUBLISHED,
            'content' => json_encode([
                'version' => 1,
                'blocks' => [
                    ['id' => 'hl1', 'type' => 'hello-prime/highlight', 'data' => ['badge' => 'Vérifié', 'text' => 'Solide sur MySQL.']],
                ],
            ], JSON_THROW_ON_ERROR),
            'fields' => ['author_role' => 'Lead Dev', 'rating' => 5, 'featured' => true],
            'new_terms' => ['testimonial_source' => 'GitHub'],
        ], $admin);

        $this->assertStringContainsString('pc-block--hello-highlight', $testimonial->contentHtml);
        $this->assertSame('Lead Dev', $testimonial->meta('author_role'));
        $this->assertCount(1, $termRepo->allForTaxonomy('testimonial_source'));

        // Deactivate & uninstall -> complete database cleanup (entries, meta, terms)
        $manager->deactivate('hello-prime');
        $manager->uninstall('hello-prime', deleteFiles: false);

        $this->assertNull($entryRepo->find($testimonial->id));
        $this->assertSame(0, $this->connection->table('entries')->where('type', '=', 'testimonial')->count());
        $this->assertSame(0, $this->connection->table('terms')->where('taxonomy', '=', 'testimonial_source')->count());
    }

    /**
     * @return array{
     *   service: EntryService,
     *   entryRepo: EntryRepository,
     *   termRepo: TermRepository,
     *   revRepo: RevisionRepository,
     *   lockRepo: EntryLockRepository,
     *   query: EntryQuery,
     *   users: array{admin: User, editor: User, author: User, contributor: User}
     * }
     */
    private function bootContentStack(int $revisionsMax = 10): array
    {
        $schema = new Schema($this->connection);
        $migrator = new Migrator(
            $this->connection,
            $schema,
            [dirname(__DIR__, 2) . '/database/migrations'],
        );
        $migrator->run();

        $now = '2026-11-15 12:00:00';
        $pw = password_hash('Secret!Mysql#99', PASSWORD_BCRYPT);

        $insertUser = function (string $email, string $name) use ($pw, $now): int {
            return $this->connection->table('users')->insertGetId([
                'email' => $email,
                'password_hash' => $pw,
                'display_name' => $name,
                'status' => 'active',
                'locale' => 'fr',
                'session_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        };

        $adminId = $insertUser('admin@prime-mysql.test', 'Admin MySQL');
        $editorId = $insertUser('editor@prime-mysql.test', 'Editor MySQL');
        $authorId = $insertUser('author@prime-mysql.test', 'Author MySQL');
        $contribId = $insertUser('contrib@prime-mysql.test', 'Contrib MySQL');

        $users = [
            'admin' => $this->makeUser($adminId, 'admin@prime-mysql.test', 'Admin MySQL', ['*'], $now),
            'editor' => $this->makeUser($editorId, 'editor@prime-mysql.test', 'Editor MySQL', [
                'dashboard.view', 'content.view', 'content.create', 'content.edit_own',
                'content.edit_any', 'content.publish', 'content.delete_own', 'content.delete_any',
            ], $now),
            'author' => $this->makeUser($authorId, 'author@prime-mysql.test', 'Author MySQL', [
                'dashboard.view', 'content.view', 'content.create', 'content.edit_own',
                'content.publish', 'content.delete_own',
            ], $now),
            'contributor' => $this->makeUser($contribId, 'contrib@prime-mysql.test', 'Contrib MySQL', [
                'dashboard.view', 'content.view', 'content.create', 'content.edit_own',
            ], $now),
        ];

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
        $fieldValidator = new FieldValidator($fields, $sanitizer);

        $service = new EntryService(
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
            revisionsMax: $revisionsMax,
        );

        return [
            'service' => $service,
            'entryRepo' => $entryRepo,
            'termRepo' => $termRepo,
            'revRepo' => $revRepo,
            'lockRepo' => $lockRepo,
            'query' => new EntryQuery($this->connection, $entryRepo),
            'users' => $users,
        ];
    }

    /**
     * @param list<string> $capabilities
     */
    private function makeUser(int $id, string $email, string $name, array $capabilities, string $now): User
    {
        return new User(
            id: $id,
            email: $email,
            displayName: $name,
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
            capabilities: $capabilities,
        );
    }

    private function assertForbidden(callable $fn): void
    {
        $thrown = false;
        try {
            $fn();
        } catch (ContentForbiddenException) {
            $thrown = true;
        }
        $this->assertTrue($thrown, 'Expected ContentForbiddenException.');
    }
}
