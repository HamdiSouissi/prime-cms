<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Prime\Auth\RoleRepository;
use Prime\Auth\User;
use Prime\Auth\UserRepository;
use Prime\Content\Entry;
use Prime\Content\Fields\Field;
use Prime\Content\Fields\FieldGroup;
use Prime\Content\Fields\FieldRegistry;
use Prime\Database\Migrations\Migrator;
use Prime\Database\Schema\Schema;
use Prime\Media\Exception\MediaValidationException;
use Prime\Media\ImageProcessor;
use Prime\Media\MediaConfig;
use Prime\Media\MediaFolderRepository;
use Prime\Media\MediaRepository;
use Prime\Media\MediaUsageRepository;
use Prime\Media\UploadService;
use Prime\Media\UploadsGuard;
use Prime\Plugins\AssetPublisher;
use Prime\Tests\Support\MediaFixtures;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Real MySQL 8.0 & MariaDB 10.6 integration tests for Phase 7 Media Library:
 * - Migrations 000015–000018, FK cascades & full rollback
 * - Rejection of all malicious files with zero disk writes & zero DB rows
 * - Folder hierarchy CRUD (create, rename, move, cycle guard, delete reparenting)
 * - Focal-point crop regeneration, file replacement, and full disk + DB deletion
 * - Pagination filters, FULLTEXT search, SHA-256 duplicate detection, and stats()
 * - Multi-context usage tracking (`thumbnail`, `block`, `field:*`, `avatar`)
 */
#[Group('database')]
final class MediaDatabaseIntegrationTest extends DatabaseTestCase
{
    private string $tmpDir = '';

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            AssetPublisher::deleteDirectory($this->tmpDir);
        }
        $this->tmpDir = '';
        parent::tearDown();
    }

    public function testMigrationsSchemaForeignKeyCascadesAndFullRollback(): void
    {
        [$migrator, $schema, $users, $user, $config, $mediaRepo, $folderRepo, $usageRepo, $uploader] = $this->bootstrapMediaStack();

        $this->assertTrue($schema->hasTable('media_folders'));
        $this->assertTrue($schema->hasTable('media'));
        $this->assertTrue($schema->hasTable('media_usage'));
        $this->assertTrue($schema->hasColumn('users', 'avatar_media_id'));

        $folder = $folderRepo->create('Photothèque', null);
        $jpg = MediaFixtures::createJpeg($this->tmpDir . '/tmp/db-hero.jpg', 800, 600);
        $uploaded = $uploader->upload(
            MediaFixtures::uploadedFile($jpg, 'montagne-alpes.jpg', 'image/jpeg'),
            actor: $user,
            folderId: $folder->id,
            metadata: ['title' => 'Alpes enneigées', 'alt' => 'Sommets enneigés'],
        )['media'];

        $users->updateFields($user->id, ['avatar_media_id' => $uploaded->id]);
        $entryId = $this->insertEntry($user->id, 'Article DB FK');
        $usageRepo->syncForEntry($entryId, [['media_id' => $uploaded->id, 'context' => 'thumbnail']]);

        // Deleting folder sets media.folder_id = NULL via ON DELETE SET NULL
        $folderRepo->delete($folder->id);
        $afterFolderDelete = $mediaRepo->find($uploaded->id);
        $this->assertNotNull($afterFolderDelete);
        $this->assertNull($afterFolderDelete->folderId);

        // Deleting media cascades pc_media_usage AND sets pc_users.avatar_media_id = NULL
        $uploader->delete($afterFolderDelete);
        $this->assertSame(0, $this->connection->table('media_usage')->count());
        $this->assertNull($users->find($user->id)?->avatarMediaId);
        $this->assertSame([], $this->listUploadedFiles($config));

        // Clean full rollback of all core migrations
        $rolledBack = $migrator->reset();
        $this->assertCount($this->coreMigrationCount(), $rolledBack);
        $this->assertFalse($schema->hasTable('media'));
        $this->assertFalse($schema->hasTable('media_folders'));
        $this->assertFalse($schema->hasTable('media_usage'));
    }

    public function testMaliciousUploadsRejectedOnRealDatabaseWithZeroDiskWritesAndZeroRows(): void
    {
        [, , , $user, $config, $mediaRepo, , , $uploader] = $this->bootstrapMediaStack();

        $polyglot = MediaFixtures::createPolyglotJpegWithPhp($this->tmpDir . '/tmp/polyglot.jpg');
        $phpAsJpg = $this->tmpDir . '/tmp/shell.jpg';
        file_put_contents($phpAsJpg, "<?php echo 'pwn'; ?>");
        $doublePhp = MediaFixtures::createJpeg($this->tmpDir . '/tmp/d1.jpg', 100, 100);
        $doubleHtml = MediaFixtures::createTransparentPng($this->tmpDir . '/tmp/d2.png', 100, 100);
        $fakePdf = $this->tmpDir . '/tmp/fake.pdf';
        file_put_contents($fakePdf, 'Not a PDF');
        $svg = $this->tmpDir . '/tmp/evil.svg';
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $empty = $this->tmpDir . '/tmp/empty.jpg';
        file_put_contents($empty, '');
        $bomb = MediaFixtures::createPixelBombPng($this->tmpDir . '/tmp/bomb.png', 10000, 10000);

        $cases = [
            [$polyglot, 'polyglot.jpg', 'image/jpeg'],
            [$phpAsJpg, 'renamed.jpg', 'image/jpeg'],
            [$doublePhp, 'photo.php.jpg', 'image/jpeg'],
            [$doubleHtml, 'script.html.png', 'image/png'],
            [$fakePdf, 'fake.pdf', 'application/pdf'],
            [$svg, 'evil.svg', 'image/svg+xml'],
            [$empty, 'empty.jpg', 'image/jpeg'],
            [$bomb, 'bomb.png', 'image/png'],
        ];

        foreach ($cases as [$tmpPath, $name, $mime]) {
            $thrown = false;
            try {
                $uploader->upload(MediaFixtures::uploadedFile($tmpPath, $name, $mime), actor: $user);
            } catch (MediaValidationException) {
                $thrown = true;
            }
            $this->assertTrue($thrown, "Expected MediaValidationException for [{$name}]");
            $this->assertSame([], $this->listUploadedFiles($config), "Zero disk files allowed after rejected [{$name}]");
            $this->assertSame(0, $mediaRepo->countAll(), "Zero DB rows allowed after rejected [{$name}]");
        }
    }

    public function testFoldersHierarchyCreateRenameMoveCycleGuardAndReparenting(): void
    {
        [, , , $user, , $mediaRepo, $folderRepo, , $uploader] = $this->bootstrapMediaStack();

        $root = $folderRepo->create('Presse', null);
        $child = $folderRepo->create('2026', $root->id);
        $grandChild = $folderRepo->create('Photos HD', $child->id);

        // Rename & move
        $renamed = $folderRepo->update($child->id, 'Édition 2026', true, $root->id);
        $this->assertSame('Édition 2026', $renamed->name);

        $movedToRoot = $folderRepo->update($grandChild->id, null, true, null);
        $this->assertNull($movedToRoot->parentId);
        $movedBack = $folderRepo->update($grandChild->id, null, true, $child->id);
        $this->assertSame($child->id, $movedBack->parentId);

        // Circular parent must throw MediaValidationException
        $cycleRejected = false;
        try {
            $folderRepo->update($root->id, 'Presse', true, $grandChild->id);
        } catch (MediaValidationException) {
            $cycleRejected = true;
        }
        $this->assertTrue($cycleRejected);

        $jpg = MediaFixtures::createJpeg($this->tmpDir . '/tmp/press.jpg', 400, 300);
        $media = $uploader->upload(
            MediaFixtures::uploadedFile($jpg, 'press-kit.jpg', 'image/jpeg'),
            actor: $user,
            folderId: $child->id,
        )['media'];

        // Delete middle folder -> BOTH media and grandchild folder reparent up to $root
        $folderRepo->delete($child->id);
        $this->assertSame($root->id, $mediaRepo->find($media->id)?->folderId);
        $this->assertSame($root->id, $folderRepo->find($grandChild->id)?->parentId);

        // Delete root -> media and grandchild reparent to NULL
        $folderRepo->delete($root->id);
        $this->assertNull($mediaRepo->find($media->id)?->folderId);
        $this->assertNull($folderRepo->find($grandChild->id)?->parentId);
    }

    public function testFocalPointUpdateRegenerationFileReplacementAndCompleteDeletion(): void
    {
        [, , , $user, $config, $mediaRepo, , , $uploader] = $this->bootstrapMediaStack();

        $jpg1 = MediaFixtures::createJpeg($this->tmpDir . '/tmp/focal.jpg', 800, 400);
        $media = $uploader->upload(
            MediaFixtures::uploadedFile($jpg1, 'focal.jpg', 'image/jpeg'),
            actor: $user,
            metadata: ['title' => 'Image focale', 'alt' => 'Alt initial'],
        )['media'];

        $leftUpdated = $uploader->updateMetadata($media, ['focal_x' => 0.1, 'focal_y' => 0.5], $user);
        $leftThumbPath = $config->uploadsAbsolutePath() . '/' . ($leftUpdated->variants['thumbnail']['path'] ?? '');
        $leftMd5 = md5_file($leftThumbPath);

        $rightUpdated = $uploader->updateMetadata($leftUpdated, ['focal_x' => 0.9, 'focal_y' => 0.5], $user);
        $rightThumbPath = $config->uploadsAbsolutePath() . '/' . ($rightUpdated->variants['thumbnail']['path'] ?? '');
        $this->assertNotSame($leftMd5, md5_file($rightThumbPath), 'Changing focal_x must regenerate a distinct cropped thumbnail.');

        // Replace preserves ID, title, alt and swaps files cleanly
        $oldPaths = $rightUpdated->allRelativePaths();
        $jpg2 = MediaFixtures::createJpeg($this->tmpDir . '/tmp/focal-v2.jpg', 1000, 600);
        $replaced = $uploader->replace(
            $rightUpdated,
            MediaFixtures::uploadedFile($jpg2, 'focal-v2.jpg', 'image/jpeg'),
            $user,
        );
        $this->assertSame($media->id, $replaced->id);
        $this->assertSame('Image focale', $replaced->title);
        $this->assertSame('Alt initial', $replaced->alt);
        $this->assertSame(1000, $replaced->width);
        $this->assertSame(600, $replaced->height);

        $newPaths = $replaced->allRelativePaths();
        foreach ($oldPaths as $oldRel) {
            if (!in_array($oldRel, $newPaths, true)) {
                $this->assertFileDoesNotExist($config->uploadsAbsolutePath() . '/' . $oldRel);
            }
        }

        // Delete removes every disk file and DB row
        $uploader->delete($replaced, $user);
        $this->assertNull($mediaRepo->find($media->id));
        $this->assertSame([], $this->listUploadedFiles($config));
    }

    public function testPaginationFiltersFulltextSearchDuplicateChecksumAndStats(): void
    {
        [, , , $user, , $mediaRepo, $folderRepo, , $uploader] = $this->bootstrapMediaStack();

        $folder = $folderRepo->create('Brochures', null);
        $jpg = MediaFixtures::createJpeg($this->tmpDir . '/tmp/alpes.jpg', 600, 400);
        $pdf = MediaFixtures::createPdf($this->tmpDir . '/tmp/tarifs.pdf');

        $r1 = $uploader->upload(
            MediaFixtures::uploadedFile($jpg, 'alpes-hiver.jpg', 'image/jpeg'),
            actor: $user,
            folderId: $folder->id,
            metadata: ['title' => 'Alpes enneigées', 'alt' => 'Montagnes'],
        );
        $this->assertNull($r1['duplicate']);

        // Uploading identical binary detects SHA-256 duplicate
        $r1Dup = $uploader->upload(
            MediaFixtures::uploadedFile($jpg, 'copie-identique.jpg', 'image/jpeg'),
            actor: $user,
        );
        $this->assertNotNull($r1Dup['duplicate']);
        $this->assertSame($r1['media']->id, $r1Dup['duplicate']->id);

        $r2 = $uploader->upload(
            MediaFixtures::uploadedFile($pdf, 'grille-tarifs.pdf', 'application/pdf'),
            actor: $user,
            metadata: ['title' => 'Tarifs 2026'],
        );

        $this->assertSame(1, $mediaRepo->paginate(['q' => 'Alpes'])['total']);
        $this->assertSame(1, $mediaRepo->paginate(['kind' => 'document'])['total']);
        $this->assertSame(1, $mediaRepo->paginate(['folder_id' => $folder->id])['total']);
        $this->assertSame(2, $mediaRepo->paginate(['unclassified' => true])['total']);
        $this->assertSame(3, $mediaRepo->paginate(['unused' => true])['total']);
        $this->assertSame(3, $mediaRepo->paginate(['author_id' => $user->id])['total']);

        $stats = $mediaRepo->stats();
        $this->assertSame(3, $stats['total_count']);
        $this->assertSame(3, $stats['unused_count']);
        $this->assertSame(2, $stats['by_kind']['image']['count'] ?? 0);
        $this->assertSame(1, $stats['by_kind']['document']['count'] ?? 0);
        $this->assertGreaterThan(0, $stats['total_bytes']);
        $this->assertNotEmpty($r2['media']->diskPath);
    }

    public function testMediaUsagesAcrossThumbnailBlocksCustomFieldsAndUserAvatar(): void
    {
        [, , $users, $user, , $mediaRepo, , $usageRepo, $uploader] = $this->bootstrapMediaStack();

        $imgSrc = MediaFixtures::createJpeg($this->tmpDir . '/tmp/u1.jpg', 600, 400);
        $pdfSrc = MediaFixtures::createPdf($this->tmpDir . '/tmp/u2.pdf');

        $img = $uploader->upload(MediaFixtures::uploadedFile($imgSrc, 'u1.jpg', 'image/jpeg'), actor: $user)['media'];
        $pdf = $uploader->upload(MediaFixtures::uploadedFile($pdfSrc, 'u2.pdf', 'application/pdf'), actor: $user)['media'];

        $users->updateFields($user->id, ['avatar_media_id' => $img->id]);

        $entryId = $this->insertEntry($user->id, 'Article usages multiples');
        $fieldRegistry = new FieldRegistry();
        $fieldRegistry->registerGroup(new FieldGroup(
            id: 'db.test_fields',
            title: 'Champs test',
            contentTypes: ['post'],
            fields: [
                new Field(name: 'client_photo', type: 'media', label: 'Photo du client', mediaKinds: ['image']),
                new Field(name: 'docs', type: 'media', label: 'Documents', multiple: true),
            ],
        ));

        $entry = new Entry(
            id: $entryId,
            type: 'post',
            status: Entry::STATUS_PUBLISHED,
            title: 'Article usages multiples',
            slug: 'article-usages-multiples',
            excerpt: null,
            content: json_encode([
                'version' => 1,
                'blocks' => [
                    ['id' => 'b1', 'type' => 'image', 'data' => ['mediaId' => $img->id, 'alt' => 'Img']],
                    ['id' => 'b2', 'type' => 'file', 'data' => ['mediaId' => $pdf->id, 'url' => $pdf->url(), 'label' => 'Doc']],
                ],
            ], JSON_THROW_ON_ERROR),
            contentHtml: '',
            contentText: '',
            authorId: $user->id,
            parentId: 0,
            menuOrder: 0,
            template: null,
            publishedAt: null,
            previousStatus: null,
            trashedAt: null,
            createdAt: gmdate('Y-m-d H:i:s'),
            updatedAt: gmdate('Y-m-d H:i:s'),
            meta: [
                '_featured_media_id' => $img->id,
                'client_photo' => $img->id,
                'docs' => [$pdf->id],
            ],
        );

        $usageRepo->syncFromEntry($entry, $fieldRegistry);

        $imgContexts = array_column($usageRepo->findByMedia($img->id), 'context');
        $this->assertContains('thumbnail', $imgContexts);
        $this->assertContains('block', $imgContexts);
        $this->assertContains('field:client_photo', $imgContexts);
        $this->assertContains('avatar', $imgContexts);

        $pdfContexts = array_column($usageRepo->findByMedia($pdf->id), 'context');
        $this->assertContains('block', $pdfContexts);
        $this->assertContains('field:docs', $pdfContexts);

        $stats = $mediaRepo->stats();
        $this->assertSame(2, $stats['total_count']);
        $this->assertSame(0, $stats['unused_count']);
    }

    /**
     * @return array{
     *   0: Migrator,
     *   1: Schema,
     *   2: UserRepository,
     *   3: User,
     *   4: MediaConfig,
     *   5: MediaRepository,
     *   6: MediaFolderRepository,
     *   7: MediaUsageRepository,
     *   8: UploadService
     * }
     */
    private function bootstrapMediaStack(): array
    {
        $this->tmpDir = sys_get_temp_dir() . '/prime_media_db_' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir . '/tmp', 0755, true);

        $schema = new Schema($this->connection);
        $migrator = new Migrator($this->connection, $schema, [dirname(__DIR__, 2) . '/database/migrations']);
        $ran = $migrator->run();
        $this->assertCount($this->coreMigrationCount(), $ran);

        $roles = new RoleRepository($this->connection);
        $users = new UserRepository($this->connection, $roles);
        $adminRole = $roles->findBySlug('administrator');
        $this->assertNotNull($adminRole);

        $user = $users->create(
            email: 'media-db@prime.test',
            displayName: 'Media Admin',
            plainPassword: 'Prime!Secret#2026',
            roleIds: [$adminRole->id],
        );

        /** @var array<string, mixed> $cfgArr */
        $cfgArr = require dirname(__DIR__, 2) . '/config/media.php';
        $config = new MediaConfig($this->tmpDir, $cfgArr);
        $mediaRepo = new MediaRepository($this->connection);
        $folderRepo = new MediaFolderRepository($this->connection);
        $usageRepo = new MediaUsageRepository($this->connection);
        $uploader = new UploadService($config, $mediaRepo, $folderRepo, new ImageProcessor($config), new UploadsGuard($config));

        return [$migrator, $schema, $users, $user, $config, $mediaRepo, $folderRepo, $usageRepo, $uploader];
    }

    private function insertEntry(int $authorId, string $title): int
    {
        $now = gmdate('Y-m-d H:i:s');

        return $this->connection->table('entries')->insertGetId([
            'type' => 'post',
            'status' => 'published',
            'title' => $title,
            'slug' => 'entry-' . bin2hex(random_bytes(4)),
            'content' => '{"version":1,"blocks":[]}',
            'content_html' => '',
            'content_text' => '',
            'author_id' => $authorId,
            'parent_id' => 0,
            'menu_order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return list<string>
     */
    private function listUploadedFiles(MediaConfig $config): array
    {
        $root = $config->uploadsAbsolutePath();
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        /** @var SplFileInfo $file */
        foreach ($it as $file) {
            if ($file->isFile() && $file->getFilename() !== '.htaccess') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
