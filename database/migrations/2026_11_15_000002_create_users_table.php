<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('email', 191)->unique();
            $table->string('password_hash', 255);
            $table->string('display_name', 191);
            $table->string('status', 20)->default('active')->index();
            $table->string('locale', 10)->default('fr');
            $table->dateTime('last_login_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('users');
    }
};
