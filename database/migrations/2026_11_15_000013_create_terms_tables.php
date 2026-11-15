<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('terms', static function (Blueprint $table): void {
            $table->id();
            $table->string('taxonomy', 40);
            $table->string('name', 191);
            $table->string('slug', 191);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('parent_id')->default(0);
            $table->integer('menu_order')->default(0);
            $table->integer('count')->default(0);
            $table->timestamps();

            $table->unique(['taxonomy', 'slug'], 'uniq_terms_taxonomy_slug');
            $table->index(['taxonomy', 'parent_id'], 'idx_terms_taxonomy_parent');
        });

        $schema->create('entry_terms', static function (Blueprint $table): void {
            $table->foreignId('entry_id');
            $table->foreignId('term_id');
            $table->integer('position')->default(0);

            $table->primary(['entry_id', 'term_id']);
            $table->index('term_id', 'idx_entry_terms_term');

            $table->foreign('entry_id', 'fk_entry_terms_entry')
                ->references('id')
                ->on('entries')
                ->cascadeOnDelete();
            $table->foreign('term_id', 'fk_entry_terms_term')
                ->references('id')
                ->on('terms')
                ->cascadeOnDelete();
        });

        // Seed the protected default category "Non classé".
        $connection = $schema->connection();
        $exists = $connection->table('terms')
            ->where('taxonomy', '=', 'category')
            ->where('slug', '=', 'non-classe')
            ->exists();

        if (!$exists) {
            $nowUtc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $connection->table('terms')->insert([
                'taxonomy' => 'category',
                'name' => 'Non classé',
                'slug' => 'non-classe',
                'description' => 'Catégorie par défaut des articles.',
                'parent_id' => 0,
                'menu_order' => 0,
                'count' => 0,
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('entry_terms');
        $schema->dropIfExists('terms');
    }
};
