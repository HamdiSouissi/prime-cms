<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('api_tokens', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('CASCADE');
            $table->string('name', 100);
            $table->string('token_prefix', 12)->index('idx_api_tokens_prefix');
            $table->string('token_hash', 64)->unique()->index('idx_api_tokens_hash');
            $table->text('abilities');
            $table->dateTime('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->index('user_id', 'idx_api_tokens_user');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('api_tokens');
    }
};
