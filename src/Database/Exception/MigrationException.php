<?php

declare(strict_types=1);

namespace Prime\Database\Exception;

/**
 * Thrown when a database migration fails to load, lock, execute, or roll back.
 */
final class MigrationException extends DatabaseException
{
}
