<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('zones', static function (Blueprint $table): void {
            $table->id();
            $table->string('theme', 80);
            $table->string('slug', 80);
            $table->longText('content');
            $table->longText('content_html');
            $table->timestamps();

            $table->unique(['theme', 'slug'], 'uq_zones_theme_slug');
        });

        $schema->create('media_zone_usage', static function (Blueprint $table): void {
            $table->foreignId('media_id');
            $table->foreignId('zone_id');
            $table->string('context', 100)->default('zone_block');

            $table->primary(['media_id', 'zone_id', 'context']);
            $table->index('zone_id', 'idx_media_zone_usage_zone');

            $table->foreign('media_id', 'fk_media_zone_usage_media')
                ->references('id')
                ->on('media')
                ->onDelete('CASCADE');

            $table->foreign('zone_id', 'fk_media_zone_usage_zone')
                ->references('id')
                ->on('zones')
                ->onDelete('CASCADE');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('media_zone_usage');
        $schema->dropIfExists('zones');
    }
};
