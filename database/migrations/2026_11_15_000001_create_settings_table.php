<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('settings', static function (Blueprint $table): void {
            $table->id();
            $table->string('name', 191)->unique();
            $table->longText('value')->nullable();
            $table->boolean('autoload')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('settings');
    }
};
