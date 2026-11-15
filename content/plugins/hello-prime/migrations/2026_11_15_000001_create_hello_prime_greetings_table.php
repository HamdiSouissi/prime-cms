<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('hello_prime_greetings', static function (Blueprint $table): void {
            $table->id();
            $table->string('author', 80);
            $table->string('message', 280);
            $table->dateTime('created_at');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('hello_prime_greetings');
    }
};
