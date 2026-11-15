<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->table('users', static function (Blueprint $table): void {
            $table->unsignedInteger('session_version')->default(1);
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->dateTime('two_factor_confirmed_at')->nullable();
            $table->bigInteger('two_factor_last_step')->nullable();
            $table->dateTime('password_changed_at')->nullable();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->table('users', static function (Blueprint $table): void {
            $table->dropColumn([
                'session_version',
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'two_factor_last_step',
                'password_changed_at',
            ]);
        });
    }
};
