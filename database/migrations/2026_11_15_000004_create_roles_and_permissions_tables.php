<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('roles', static function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 191)->unique();
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        $schema->create('role_capabilities', static function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('capability', 100);
            $table->primary(['role_id', 'capability']);
            $table->foreign('role_id')->references('id')->on('roles')->onDelete('CASCADE');
        });

        $schema->create('user_roles', static function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['user_id', 'role_id']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('CASCADE');
            $table->foreign('role_id')->references('id')->on('roles')->onDelete('CASCADE');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('user_roles');
        $schema->dropIfExists('role_capabilities');
        $schema->dropIfExists('roles');
    }
};
