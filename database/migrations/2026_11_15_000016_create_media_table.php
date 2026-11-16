<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('media', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('folder_id')->nullable();
            $table->foreignId('author_id')->nullable();
            $table->string('disk_path', 255);
            $table->string('filename', 255);
            $table->string('original_name', 255);
            $table->string('extension', 16);
            $table->string('mime_type', 120);
            $table->string('kind', 24)->default('other');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->char('checksum', 64);
            $table->string('alt', 255)->default('');
            $table->string('title', 255)->default('');
            $table->text('caption')->nullable();
            $table->text('description')->nullable();
            $table->decimal('focal_x', 4, 3)->default('0.500');
            $table->decimal('focal_y', 4, 3)->default('0.500');
            $table->json('variants')->nullable();
            $table->timestamps();

            $table->unique('disk_path', 'uniq_media_disk_path');
            $table->index('folder_id', 'idx_media_folder');
            $table->index('author_id', 'idx_media_author');
            $table->index('kind', 'idx_media_kind');
            $table->index('checksum', 'idx_media_checksum');
            $table->index('created_at', 'idx_media_created_at');
            $table->fullText(['original_name', 'title', 'alt', 'caption'], 'ft_media_search');

            $table->foreign('folder_id', 'fk_media_folder')
                ->references('id')
                ->on('media_folders')
                ->onDelete('SET NULL');

            $table->foreign('author_id', 'fk_media_author')
                ->references('id')
                ->on('users')
                ->onDelete('SET NULL');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('media');
    }
};
