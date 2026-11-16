<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('menus', static function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 120);
            $table->string('location', 80)->nullable();
            $table->timestamps();

            $table->unique('slug', 'uq_menus_slug');
            $table->index('location', 'idx_menus_location');
        });

        $schema->create('menu_items', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_id');
            $table->unsignedBigInteger('parent_id')->default(0);
            $table->unsignedInteger('position')->default(0);
            $table->string('type', 20)->default('url');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('url', 500)->nullable();
            $table->string('label', 255)->default('');
            $table->string('target', 16)->default('_self');
            $table->string('css_class', 255)->nullable();
            $table->timestamps();

            $table->index(['menu_id', 'parent_id', 'position'], 'idx_menu_items_order');
            $table->foreign('menu_id', 'fk_menu_items_menu')
                ->references('id')
                ->on('menus')
                ->onDelete('CASCADE');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('menu_items');
        $schema->dropIfExists('menus');
    }
};
