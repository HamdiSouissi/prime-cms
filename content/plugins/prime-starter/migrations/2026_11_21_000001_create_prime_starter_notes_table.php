<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('prime_starter_notes', static function (Blueprint $table): void {
            $table->id();
            $table->string('title', 160);
            $table->text('body');
            $table->string('color', 16)->default('sand');
            $table->boolean('pinned')->default(false);
            $table->boolean('public')->default(false);
            $table->unsignedBigInteger('author_id')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->index(['public', 'pinned', 'updated_at'], 'prime_starter_notes_listing');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('prime_starter_notes');
    }
};
