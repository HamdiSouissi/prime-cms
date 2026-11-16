<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->table('users', static function (Blueprint $table): void {
            $table->foreignId('avatar_media_id')->nullable();
            $table->index('avatar_media_id', 'idx_users_avatar_media');
            $table->foreign('avatar_media_id', 'fk_users_avatar_media')
                ->references('id')
                ->on('media')
                ->onDelete('SET NULL');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->table('users', static function (Blueprint $table): void {
            $table->dropForeign('fk_users_avatar_media');
            $table->dropIndex('idx_users_avatar_media');
            $table->dropColumn('avatar_media_id');
        });
    }
};
