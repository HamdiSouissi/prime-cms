<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Prime\Database\Exception\MigrationException;
use Prime\Database\Migrations\Migrator;
use Prime\Database\Query\Expression;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;
use Prime\Hooks\Hooks;
use Prime\Settings\Settings;
use RuntimeException;

#[Group('database')]
final class DatabaseIntegrationTest extends DatabaseTestCase
{
    public function testConnectionUsesStrictModeUtcTimezoneAndFullUtf8mb4(): void
    {
        $version = $this->connection->serverVersion();
        $this->assertNotSame('', $version);

        $tz = (string) $this->connection->scalar('SELECT @@session.time_zone');
        $this->assertSame('+00:00', $tz);

        $sqlMode = strtoupper((string) $this->connection->scalar('SELECT @@session.sql_mode'));
        $this->assertStringContainsString('STRICT_ALL_TABLES', $sqlMode);

        $schema = new Schema($this->connection);
        $schema->create('utf8_probe', static function (Blueprint $table): void {
            $table->id();
            $table->string('label');
        });

        $sample = 'Prime CMS 🚀 Éléphant 日本語 — café';
        $id = $this->connection->table('utf8_probe')->insertGetId(['label' => $sample]);
        $stored = $this->connection->table('utf8_probe')->where('id', $id)->first();

        $this->assertIsArray($stored);
        $this->assertSame($sample, $stored['label']);
    }

    public function testTransactionsCommitRollbackAndNestedSavepoints(): void
    {
        $schema = new Schema($this->connection);
        $schema->create('ledger', static function (Blueprint $table): void {
            $table->id();
            $table->string('entry');
        });

        // Outer transaction commits, inner nested savepoint rolls back cleanly.
        $this->connection->transaction(function (): void {
            $this->connection->table('ledger')->insert(['entry' => 'outer-kept']);

            try {
                $this->connection->transaction(function (): void {
                    $this->connection->table('ledger')->insert(['entry' => 'inner-rolled-back']);
                    throw new RuntimeException('Abort inner savepoint');
                });
            } catch (RuntimeException) {
                // Expected: outer transaction continues intact.
            }

            $this->connection->table('ledger')->insert(['entry' => 'outer-after-savepoint']);
        });

        $entries = $this->connection->table('ledger')->orderBy('id')->pluck('entry');
        $this->assertSame(['outer-kept', 'outer-after-savepoint'], $entries);

        // Full rollback removes everything inside the failed transaction.
        try {
            $this->connection->transaction(function (): void {
                $this->connection->table('ledger')->insert(['entry' => 'never-committed']);
                throw new RuntimeException('Abort full transaction');
            });
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame(2, $this->connection->table('ledger')->count());
    }

    public function testQueryBuilderCrudJoinsAggregatesAndPaginationOnRealDatabase(): void
    {
        $schema = new Schema($this->connection);
        $schema->create('authors', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        $schema->create('articles', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('author_id');
            $table->foreign('author_id')->references('id')->on('authors')->onDelete('CASCADE');
            $table->string('title');
            $table->string('status')->default('draft');
            $table->integer('views')->default(0);
            $table->dateTime('published_at')->nullable();
        });

        $aliceId = $this->connection->table('authors')->insertGetId(['name' => 'Alice']);
        $bobId = $this->connection->table('authors')->insertGetId(['name' => 'Bob']);

        // Batch insert
        $inserted = $this->connection->table('articles')->insert([
            ['author_id' => $aliceId, 'title' => 'First', 'status' => 'published', 'views' => 10, 'published_at' => '2026-11-01 10:00:00'],
            ['author_id' => $aliceId, 'title' => 'Second', 'status' => 'published', 'views' => 30, 'published_at' => '2026-11-02 10:00:00'],
            ['author_id' => $bobId, 'title' => 'Draft', 'status' => 'draft', 'views' => 5, 'published_at' => null],
            ['author_id' => $bobId, 'title' => 'Third', 'status' => 'published', 'views' => 20, 'published_at' => '2026-11-03 10:00:00'],
        ]);
        $this->assertTrue($inserted);
        $this->assertSame(4, $this->connection->table('articles')->count());

        // Increment & decrement
        $this->connection->table('articles')->where('title', 'First')->increment('views', 5);
        $this->connection->table('articles')->where('title', 'Second')->decrement('views', 5);

        // Aggregates
        $this->assertSame(3, $this->connection->table('articles')->where('status', 'published')->count());
        $this->assertSame(60.0, (float) $this->connection->table('articles')->where('status', 'published')->sum('views'));
        $this->assertSame(20.0, (float) $this->connection->table('articles')->where('status', 'published')->avg('views'));
        $this->assertSame(5, (int) $this->connection->table('articles')->min('views'));
        $this->assertSame(25, (int) $this->connection->table('articles')->max('views'));

        // Join + groupBy + having + Expression::raw
        $perAuthor = $this->connection->table('articles')
            ->select(['authors.name', Expression::raw('COUNT(*) AS published_count')])
            ->join('authors', 'articles.author_id', '=', 'authors.id')
            ->where('articles.status', 'published')
            ->groupBy('authors.name')
            ->having('published_count', '>=', 2)
            ->get();

        $this->assertCount(1, $perAuthor);
        $this->assertSame('Alice', $perAuthor[0]['name']);
        $this->assertSame(2, (int) $perAuthor[0]['published_count']);

        // Pagination
        $page1 = $this->connection->table('articles')
            ->whereNotNull('published_at')
            ->orderBy('published_at', 'DESC')
            ->paginate(page: 1, perPage: 2);

        $this->assertSame(3, $page1['total']);
        $this->assertSame(2, $page1['total_pages']);
        $this->assertCount(2, $page1['items']);
        $this->assertSame('Third', $page1['items'][0]['title']);

        // Delete with where + FK cascade when deleting author
        $deletedDrafts = $this->connection->table('articles')->whereNull('published_at')->delete();
        $this->assertSame(1, $deletedDrafts);

        $this->connection->table('authors')->where('id', $aliceId)->delete();
        $this->assertSame(1, $this->connection->table('articles')->count());
    }

    public function testSchemaCreateAlterRenameAndDropWorkOnRealDatabase(): void
    {
        $schema = new Schema($this->connection);
        $this->assertFalse($schema->hasTable('items'));

        $schema->create('items', static function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->decimal('price', 10, 2)->default('0.00');
            $table->boolean('active')->default(true)->index();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        $this->assertTrue($schema->hasTable('items'));
        $this->assertTrue($schema->hasColumn('items', 'slug'));
        $this->assertTrue($schema->hasColumn('items', 'meta'));

        $schema->table('items', static function (Blueprint $table): void {
            $table->string('sku', 64)->nullable()->after('slug');
            $table->index('sku');
        });
        $this->assertTrue($schema->hasColumn('items', 'sku'));

        $schema->table('items', static function (Blueprint $table): void {
            $table->dropColumn('sku');
        });
        $this->assertFalse($schema->hasColumn('items', 'sku'));

        $schema->rename('items', 'catalog_items');
        $this->assertFalse($schema->hasTable('items'));
        $this->assertTrue($schema->hasTable('catalog_items'));

        $schema->dropIfExists('catalog_items');
        $this->assertFalse($schema->hasTable('catalog_items'));
    }

    public function testMigratorRunRollbackResetFreshAndPartialFailurePreservesEarlierMigrations(): void
    {
        $schema = new Schema($this->connection);
        $migrator = new Migrator($this->connection, $schema, [dirname(__DIR__, 2) . '/database/migrations']);

        // Run core migrations
        $ran = $migrator->run();
        $this->assertCount(23, $ran);
        $this->assertTrue($schema->hasTable('settings'));
        $this->assertTrue($schema->hasTable('plugins'));
        $this->assertTrue($schema->hasColumn('migrations', 'source'));
        $this->assertTrue($schema->hasTable('users'));
        $this->assertTrue($schema->hasColumn('users', 'session_version'));
        $this->assertTrue($schema->hasColumn('users', 'two_factor_secret'));
        $this->assertTrue($schema->hasTable('roles'));
        $this->assertTrue($schema->hasTable('role_capabilities'));
        $this->assertTrue($schema->hasTable('user_roles'));
        $this->assertTrue($schema->hasTable('remember_tokens'));
        $this->assertTrue($schema->hasTable('password_resets'));
        $this->assertTrue($schema->hasTable('rate_limits'));
        $this->assertSame(4, $this->connection->table('roles')->count());

        // Idempotent second run
        $this->assertSame([], $migrator->run());

        // Status reflects all core migrations as ran in batch 1
        $status = $migrator->status();
        $this->assertCount(23, $status);
        $this->assertSame('ran', $status[0]['status']);
        $this->assertSame(1, $status[0]['batch']);

        // Rollback removes all batch-1 migrations cleanly
        $rolledBack = $migrator->rollback(1);
        $this->assertCount(23, $rolledBack);
        $this->assertFalse($schema->hasTable('roles'));
        $this->assertFalse($schema->hasTable('users'));
        $this->assertFalse($schema->hasTable('settings'));

        // Fresh recreates cleanly
        $freshRan = $migrator->fresh();
        $this->assertCount(23, $freshRan);
        $this->assertTrue($schema->hasTable('settings'));
        $this->assertTrue($schema->hasTable('users'));
        $this->assertTrue($schema->hasTable('roles'));

        // Partial failure test: migration 1 succeeds, migration 2 fails → migration 1 stays recorded!
        $tempDir = sys_get_temp_dir() . '/prime_mig_fail_' . uniqid('', true);
        mkdir($tempDir, 0755, true);

        try {
            file_put_contents($tempDir . '/2026_11_15_100001_create_good_table.php', <<<'PHP'
<?php
declare(strict_types=1);
use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;
return new class () extends Migration {
    public function up(Schema $schema): void {
        $schema->create('good_mig', static function (Blueprint $table): void {
            $table->id();
        });
    }
    public function down(Schema $schema): void {
        $schema->dropIfExists('good_mig');
    }
};
PHP);

            file_put_contents($tempDir . '/2026_11_15_100002_broken_migration.php', <<<'PHP'
<?php
declare(strict_types=1);
use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Schema;
return new class () extends Migration {
    public function up(Schema $schema): void {
        $schema->drop('definitely_missing_table_for_test');
    }
    public function down(Schema $schema): void {}
};
PHP);

            $migratorWithBad = new Migrator($this->connection, $schema, [$tempDir]);
            $failed = false;

            try {
                $migratorWithBad->run();
            } catch (MigrationException) {
                $failed = true;
            }

            $this->assertTrue($failed);
            // First migration stayed recorded in pc_migrations, broken one was NOT recorded.
            $this->assertTrue($schema->hasTable('good_mig'));
            $recorded = $this->connection->table('migrations')->pluck('migration');
            $this->assertContains('2026_11_15_100001_create_good_table', $recorded);
            $this->assertNotContains('2026_11_15_100002_broken_migration', $recorded);
        } finally {
            foreach (glob($tempDir . '/*.php') ?: [] as $file) {
                unlink($file);
            }
            rmdir($tempDir);
        }
    }

    public function testSettingsAutoloadSingleQueryCacheAndTypedValuesOnRealDatabase(): void
    {
        $schema = new Schema($this->connection);
        $migrator = new Migrator($this->connection, $schema, [dirname(__DIR__, 2) . '/database/migrations']);
        $migrator->run();

        $hooks = new Hooks();
        $updatedKeys = [];
        $hooks->on('settings.updated', static function (string $name) use (&$updatedKeys): void {
            $updatedKeys[] = $name;
        });

        $writer = new Settings($this->connection, $hooks);
        $writer->set('site_name', 'Atelier Prime', autoload: true);
        $writer->set('items_per_page', 24, autoload: true);
        $writer->set('maintenance', false, autoload: true);
        $writer->set('features', ['seo' => true, 'media' => ['webp']], autoload: true);
        $writer->set('heavy_blob', 'not-autoloaded', autoload: false);

        $this->assertSame(['site_name', 'items_per_page', 'maintenance', 'features', 'heavy_blob'], $updatedKeys);

        // Fresh Settings instance must satisfy all autoload reads in a SINGLE SQL query.
        $this->connection->clearQueryLog();
        $reader = new Settings($this->connection);

        $this->assertSame('Atelier Prime', $reader->get('site_name'));
        $this->assertSame(24, $reader->get('items_per_page'));
        $this->assertFalse($reader->get('maintenance'));
        $this->assertSame(['seo' => true, 'media' => ['webp']], $reader->get('features'));
        $this->assertTrue($reader->has('site_name'));
        $this->assertCount(1, $this->connection->queryLog());

        // Non-autoload setting fetches on demand.
        $this->assertSame('not-autoloaded', $reader->get('heavy_blob'));
        $this->assertCount(2, $this->connection->queryLog());

        // Delete removes setting
        $reader->delete('heavy_blob');
        $this->assertFalse($reader->has('heavy_blob'));
    }
}
