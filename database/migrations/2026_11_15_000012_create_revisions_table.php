<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('revisions', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entry_id');
            $table->foreignId('author_id')->nullable();
            $table->boolean('is_autosave')->default(false);
            $table->string('title', 255)->default('');
            $table->text('excerpt')->nullable();
            $table->longText('content');
            $table->longText('meta')->nullable();
            $table->dateTime('created_at');

            $table->index(['entry_id', 'is_autosave', 'created_at'], 'idx_revisions_entry');

            $table->foreign('entry_id', 'fk_revisions_entry')
                ->references('id')
                ->on('entries')
                ->cascadeOnDelete();
            $table->foreign('author_id', 'fk_revisions_author')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('revisions');
    }
};
