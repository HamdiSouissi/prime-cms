<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('migrations') || $schema->hasColumn('migrations', 'source')) {
            return;
        }

        $schema->table('migrations', static function (Blueprint $table): void {
            $table->string('source', 64)->default('core');
            $table->index('source', 'idx_pc_migrations_source');
        });
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('migrations') || !$schema->hasColumn('migrations', 'source')) {
            return;
        }

        $schema->table('migrations', static function (Blueprint $table): void {
            $table->dropIndex('idx_pc_migrations_source');
            $table->dropColumn('source');
        });
    }
};
