<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('media_usage', static function (Blueprint $table): void {
            $table->foreignId('media_id');
            $table->foreignId('entry_id');
            $table->string('context', 100);

            $table->primary(['media_id', 'entry_id', 'context']);
            $table->index('entry_id', 'idx_media_usage_entry');

            $table->foreign('media_id', 'fk_media_usage_media')
                ->references('id')
                ->on('media')
                ->onDelete('CASCADE');

            $table->foreign('entry_id', 'fk_media_usage_entry')
                ->references('id')
                ->on('entries')
                ->onDelete('CASCADE');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('media_usage');
    }
};
