<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('entry_meta', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entry_id');
            $table->string('meta_key', 191);
            $table->longText('meta_value')->nullable();

            $table->unique(['entry_id', 'meta_key'], 'uniq_entry_meta_key');
            $table->index('meta_key', 'idx_entry_meta_key');

            $table->foreign('entry_id', 'fk_entry_meta_entry')
                ->references('id')
                ->on('entries')
                ->cascadeOnDelete();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('entry_meta');
    }
};
