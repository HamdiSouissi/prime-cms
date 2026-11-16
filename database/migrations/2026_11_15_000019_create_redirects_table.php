<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('redirects', static function (Blueprint $table): void {
            $table->id();
            $table->string('source_path', 255);
            $table->string('target_path', 255);
            $table->unsignedInteger('status_code')->default(301);
            $table->unsignedInteger('hits')->default(0);
            $table->dateTime('last_hit_at')->nullable();
            $table->timestamps();

            $table->unique('source_path', 'uq_redirects_source_path');
            $table->index('target_path', 'idx_redirects_target_path');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('redirects');
    }
};
