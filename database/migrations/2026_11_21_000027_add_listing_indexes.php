<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

/**
 * Phase 12 (performance): indexes for the queries measured on 1 000 entries.
 *
 * - admin content list and dashboard activity sort by `updated_at` within a type;
 * - the month filter of the content list reads `created_at` within a type;
 * - « Outils › File d'attente » and the plugin uninstall count jobs by `handler`.
 */
return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->table('entries', static function (Blueprint $table): void {
            $table->index(['type', 'updated_at'], 'idx_entries_type_updated');
            $table->index(['type', 'created_at'], 'idx_entries_type_created');
        });
        $schema->table('jobs', static function (Blueprint $table): void {
            $table->index('handler', 'idx_jobs_handler');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->table('entries', static function (Blueprint $table): void {
            $table->dropIndex('idx_entries_type_updated');
            $table->dropIndex('idx_entries_type_created');
        });
        $schema->table('jobs', static function (Blueprint $table): void {
            $table->dropIndex('idx_jobs_handler');
        });
    }
};
