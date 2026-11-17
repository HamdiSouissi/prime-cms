<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('mail_log', static function (Blueprint $table): void {
            $table->id();
            $table->string('recipient', 255);
            $table->string('subject', 255);
            $table->string('status', 20)->default('queued');
            $table->string('transport', 32)->default('log');
            $table->string('template', 120)->nullable();
            $table->text('metadata_json')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedBigInteger('job_id')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'idx_mail_log_status_created');
            $table->index('recipient', 'idx_mail_log_recipient');
            $table->index('created_at', 'idx_mail_log_created');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('mail_log');
    }
};
