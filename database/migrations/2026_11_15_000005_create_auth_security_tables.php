<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('remember_tokens', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->char('selector', 24)->unique();
            $table->char('validator_hash', 64);
            $table->dateTime('expires_at')->index();
            $table->dateTime('last_used_at')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('CASCADE');
        });

        $schema->create('password_resets', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->char('token_hash', 64)->unique();
            $table->dateTime('expires_at')->index();
            $table->dateTime('used_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('CASCADE');
        });

        $schema->create('rate_limits', static function (Blueprint $table): void {
            $table->char('key', 64)->primary();
            $table->integer('hits')->default(0);
            $table->dateTime('reset_at')->index();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('rate_limits');
        $schema->dropIfExists('password_resets');
        $schema->dropIfExists('remember_tokens');
    }
};
