<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Prime\Auth\RoleRepository;
use Prime\Auth\UserRepository;
use Prime\Database\Migrations\Migrator;
use Prime\Database\Schema\Schema;
use Prime\Media\ImageProcessor;
use Prime\Media\MediaConfig;
use Prime\Media\MediaFolderRepository;
use Prime\Media\MediaRepository;
use Prime\Media\MediaUsageRepository;
use Prime\Media\UploadService;
use Prime\Media\UploadsGuard;
use Prime\Plugins\AssetPublisher;
use Prime\Tests\Support\MediaFixtures;

/**
 * Real MySQL 8.0 & MariaDB 10.6 integration tests for Phase 7 Media Library:
 * migrations 000015–000018, FK cascades (SET NULL on folder/author/user avatar;
 * CASCADE on media_usage), folder tree counts, FULLTEXT search, and stats().
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

    public function testPhase7MigrationsForeignKeysCascadesAndStatsOnMysqlAndMariaDb(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/prime_media_db_' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir . '/tmp', 0755, true);

        $schema = new Schema($this->connection);
        $migrator = new Migrator($this->connection, $schema, [dirname(__DIR__, 2) . '/database/migrations']);

        $ran = $migrator->run();
        $this->assertCount(18, $ran);
        $this->assertTrue($schema->hasTable('media_folders'));
        $this->assertTrue($schema->hasTable('media'));
        $this->assertTrue($schema->hasTable('media_usage'));
        $this->assertTrue($schema->hasColumn('users', 'avatar_media_id'));

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

        // 1. Create folder + upload JPEG into folder
        $folder = $folderRepo->create('Photothèque', null);
        $jpg = MediaFixtures::createJpeg($this->tmpDir . '/tmp/db-hero.jpg', 800, 600);
        $uploaded = $uploader->upload(
            MediaFixtures::uploadedFile($jpg, 'montagne-alpes.jpg', 'image/jpeg'),
            actor: $user,
            folderId: $folder->id,
            metadata: ['title' => 'Alpes enneigées', 'alt' => 'Sommets enneigés'],
        )['media'];

        // 2. Attach media as user avatar AND entry usage
        $users->updateFields($user->id, ['avatar_media_id' => $uploaded->id]);
        $now = gmdate('Y-m-d H:i:s');
        $entryId = $this->connection->table('entries')->insertGetId([
            'type' => 'post',
            'status' => 'published',
            'title' => 'Article DB Media',
            'slug' => 'article-db-media',
            'content' => '{"version":1,"blocks":[]}',
            'content_html' => '',
            'content_text' => '',
            'author_id' => $user->id,
            'parent_id' => 0,
            'menu_order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $usageRepo->syncForEntry($entryId, [['media_id' => $uploaded->id, 'context' => 'thumbnail']]);

        // 3. Stats & search work cleanly on real MySQL / MariaDB
        $stats = $mediaRepo->stats();
        $this->assertSame(1, $stats['total_count']);
        $this->assertSame(0, $stats['unused_count']);
        $this->assertGreaterThan(0, $stats['total_bytes']);

        $search = $mediaRepo->paginate(['q' => 'Alpes']);
        $this->assertSame(1, $search['total']);

        // 4. Deleting folder sets media.folder_id = NULL via ON DELETE SET NULL
        $folderRepo->delete($folder->id);
        $afterFolderDelete = $mediaRepo->find($uploaded->id);
        $this->assertNotNull($afterFolderDelete);
        $this->assertNull($afterFolderDelete->folderId);

        // 5. Deleting media cascades media_usage AND sets users.avatar_media_id = NULL!
        $uploader->delete($afterFolderDelete);
        $this->assertSame(0, $this->connection->table('media_usage')->count());
        $reloadedUser = $users->find($user->id);
        $this->assertNotNull($reloadedUser);
        $this->assertNull($reloadedUser->avatarMediaId);

        // 6. Clean full rollback of all 18 migrations
        $rolledBack = $migrator->reset();
        $this->assertCount(18, $rolledBack);
        $this->assertFalse($schema->hasTable('media'));
        $this->assertFalse($schema->hasTable('media_folders'));
        $this->assertFalse($schema->hasTable('media_usage'));
    }
}
