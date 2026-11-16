<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('media_folders', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable();
            $table->string('name', 120);
            $table->string('slug', 120);
            $table->timestamps();

            $table->index('parent_id', 'idx_media_folders_parent');
            $table->unique(['parent_id', 'slug'], 'uniq_media_folders_parent_slug');

            $table->foreign('parent_id', 'fk_media_folders_parent')
                ->references('id')
                ->on('media_folders')
                ->onDelete('SET NULL');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('media_folders');
    }
};
