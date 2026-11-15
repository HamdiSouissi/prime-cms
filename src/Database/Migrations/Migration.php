<?php

declare(strict_types=1);

namespace Prime\Database\Migrations;

use Prime\Database\Schema\Schema;

/**
 * Base class returned by versioned migration files in `database/migrations/`.
 */
abstract class Migration
{
    /**
     * Apply schema changes forward.
     */
    abstract public function up(Schema $schema): void;

    /**
     * Revert schema changes applied by `up()`.
     */
    abstract public function down(Schema $schema): void;
}
