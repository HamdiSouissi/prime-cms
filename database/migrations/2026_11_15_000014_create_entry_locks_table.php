<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('entry_locks', static function (Blueprint $table): void {
            $table->foreignId('entry_id');
            $table->foreignId('user_id');
            $table->dateTime('acquired_at');
            $table->dateTime('heartbeat_at');

            $table->primary('entry_id');
            $table->index('heartbeat_at', 'idx_entry_locks_heartbeat');

            $table->foreign('entry_id', 'fk_entry_locks_entry')
                ->references('id')
                ->on('entries')
                ->cascadeOnDelete();
            $table->foreign('user_id', 'fk_entry_locks_user')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('entry_locks');
    }
};
