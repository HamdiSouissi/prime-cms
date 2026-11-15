<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('entries', static function (Blueprint $table): void {
            $table->id();
            $table->string('type', 40);
            $table->string('status', 20)->default('draft');
            $table->string('title', 255)->default('');
            $table->string('slug', 191);
            $table->text('excerpt')->nullable();
            $table->longText('content');
            $table->longText('content_html');
            $table->longText('content_text');
            $table->foreignId('author_id')->nullable();
            $table->unsignedBigInteger('parent_id')->default(0);
            $table->integer('menu_order')->default(0);
            $table->string('template', 100)->nullable();
            $table->dateTime('published_at')->nullable();
            $table->string('previous_status', 20)->nullable();
            $table->dateTime('trashed_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status', 'published_at'], 'idx_entries_type_status_pub');
            $table->index('author_id', 'idx_entries_author');
            $table->index('parent_id', 'idx_entries_parent');
            $table->unique(['type', 'parent_id', 'slug'], 'uniq_entries_type_parent_slug');
            $table->fullText(['title', 'content_text'], 'ft_entries_search');

            $table->foreign('author_id', 'fk_entries_author')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('entries');
    }
};
