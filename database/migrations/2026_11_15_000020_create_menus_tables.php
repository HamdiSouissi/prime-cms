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
            $table->timestamps();

            $table->unique('slug', 'uq_menus_slug');
        });

        $schema->create('menu_items', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_id');
            $table->unsignedBigInteger('parent_id')->default(0);
            $table->unsignedInteger('position')->default(0);
            $table->string('kind', 20)->default('custom');
            $table->unsignedBigInteger('object_id')->nullable();
            $table->string('url', 500)->nullable();
            $table->string('label', 255)->default('');
            $table->string('title_attr', 255)->nullable();
            $table->boolean('open_new_tab')->default(false);
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
