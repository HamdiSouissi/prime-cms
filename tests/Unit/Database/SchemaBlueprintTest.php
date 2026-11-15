<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Database;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prime\Database\Exception\InvalidIdentifierException;
use Prime\Database\Schema\Blueprint;

final class SchemaBlueprintTest extends TestCase
{
    public function testCompilesCreateTableWithAllColumnTypesModifiersIndexesAndForeignKeys(): void
    {
        $blueprint = new Blueprint('entries', creating: true, prefix: 'pc_', charset: 'utf8mb4', collation: 'utf8mb4_unicode_ci');

        $blueprint->id();
        $blueprint->string('title', 191)->comment("Entry's main title");
        $blueprint->char('lang', 2)->default('fr');
        $blueprint->text('excerpt')->nullable();
        $blueprint->mediumText('summary')->nullable();
        $blueprint->longText('content')->nullable();
        $blueprint->integer('views')->unsigned()->default(0);
        $blueprint->bigInteger('external_id')->nullable();
        $blueprint->unsignedBigInteger('parent_id')->nullable();
        $blueprint->tinyInteger('priority')->default(1);
        $blueprint->boolean('published')->default(true)->index();
        $blueprint->decimal('price', 10, 2)->default(0.0);
        $blueprint->json('meta')->nullable();
        $blueprint->date('publish_date')->nullable();
        $blueprint->dateTime('published_at')->nullable();
        $blueprint->timestamp('synced_at')->nullable();
        $blueprint->timestamps();
        $blueprint->foreignId('author_id');

        $blueprint->unique(['title', 'lang'], 'uniq_entries_title_lang');
        $blueprint->index('publish_date');
        $blueprint->foreign('author_id')
            ->references('id')
            ->on('users')
            ->onDelete('cascade')
            ->onUpdate('restrict');

        $sql = $blueprint->toSql();
        $this->assertCount(1, $sql);

        $ddl = $sql[0];
        $this->assertStringStartsWith('CREATE TABLE `pc_entries` (', $ddl);
        $this->assertStringContainsString('`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', $ddl);
        $this->assertStringContainsString("`title` VARCHAR(191) NOT NULL COMMENT 'Entry''s main title'", $ddl);
        $this->assertStringContainsString("`lang` CHAR(2) NOT NULL DEFAULT 'fr'", $ddl);
        $this->assertStringContainsString('`excerpt` TEXT NULL', $ddl);
        $this->assertStringContainsString('`summary` MEDIUMTEXT NULL', $ddl);
        $this->assertStringContainsString('`content` LONGTEXT NULL', $ddl);
        $this->assertStringContainsString('`views` INT UNSIGNED NOT NULL DEFAULT 0', $ddl);
        $this->assertStringContainsString('`external_id` BIGINT NULL', $ddl);
        $this->assertStringContainsString('`parent_id` BIGINT UNSIGNED NULL', $ddl);
        $this->assertStringContainsString('`priority` TINYINT NOT NULL DEFAULT 1', $ddl);
        $this->assertStringContainsString('`published` TINYINT(1) NOT NULL DEFAULT 1', $ddl);
        $this->assertStringContainsString('`price` DECIMAL(10, 2) NOT NULL DEFAULT 0', $ddl);
        $this->assertStringContainsString('`meta` JSON NULL', $ddl);
        $this->assertStringContainsString('`publish_date` DATE NULL', $ddl);
        $this->assertStringContainsString('`published_at` DATETIME NULL', $ddl);
        $this->assertStringContainsString('`synced_at` TIMESTAMP NULL', $ddl);
        $this->assertStringContainsString('`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', $ddl);
        $this->assertStringContainsString('`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', $ddl);
        $this->assertStringContainsString('`author_id` BIGINT UNSIGNED NOT NULL', $ddl);
        $this->assertStringContainsString('UNIQUE KEY `uniq_entries_title_lang` (`title`, `lang`)', $ddl);
        $this->assertStringContainsString('INDEX `idx_pc_entries_published` (`published`)', $ddl);
        $this->assertStringContainsString('INDEX `idx_pc_entries_publish_date` (`publish_date`)', $ddl);
        $this->assertStringContainsString('CONSTRAINT `fk_pc_entries_author_id` FOREIGN KEY (`author_id`) REFERENCES `pc_users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT', $ddl);
        $this->assertStringEndsWith(') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', $ddl);
    }

    public function testCompilesAlterTableOperations(): void
    {
        $blueprint = new Blueprint('users', creating: false, prefix: 'pc_');
        $blueprint->string('bio', 255)->nullable()->after('display_name');
        $blueprint->renameColumn('display_name', 'full_name');
        $blueprint->dropColumn(['legacy_col']);
        $blueprint->dropIndex('idx_pc_users_status');
        $blueprint->dropForeign('fk_pc_users_role_id');

        $sql = $blueprint->toSql();
        $this->assertCount(1, $sql);
        $this->assertSame(
            'ALTER TABLE `pc_users` ADD COLUMN `bio` VARCHAR(255) NULL AFTER `display_name`, RENAME COLUMN `display_name` TO `full_name`, DROP COLUMN `legacy_col`, DROP INDEX `idx_pc_users_status`, DROP FOREIGN KEY `fk_pc_users_role_id`',
            $sql[0],
        );
    }

    public function testRejectsInvalidColumnIdentifierInBlueprint(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $blueprint = new Blueprint('users', creating: true);
        $blueprint->string('email; DROP TABLE pc_users');
    }

    public function testRejectsInvalidForeignKeyAction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $blueprint = new Blueprint('posts', creating: true);
        $blueprint->foreign('user_id')->references('id')->on('users')->onDelete('DROP ALL');
    }
}
