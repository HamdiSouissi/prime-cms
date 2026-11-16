<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('queue', 64)->default('default');
            $table->string('handler', 191);
            $table->longText('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->dateTime('available_at');
            $table->dateTime('reserved_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->dateTime('created_at');

            $table->index(['queue', 'failed_at', 'reserved_at', 'available_at'], 'idx_jobs_claim');
            $table->index(['failed_at', 'created_at'], 'idx_jobs_failed');
            $table->index('reserved_at', 'idx_jobs_reserved');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('jobs');
    }
};
