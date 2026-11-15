<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('plugins', static function (Blueprint $table): void {
            $table->string('slug', 64);
            $table->primary('slug');
            $table->string('version', 64);
            $table->string('status', 20)->default('inactive');
            $table->char('manifest_hash', 64);
            $table->text('last_error')->nullable();
            $table->dateTime('errored_at')->nullable();
            $table->dateTime('installed_at');
            $table->dateTime('activated_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('plugins');
    }
};
