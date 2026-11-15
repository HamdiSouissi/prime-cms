<?php

declare(strict_types=1);

namespace Prime\Database\Exception;

/**
 * Thrown when a SQL table, column, alias, or charset identifier fails strict validation.
 */
final class InvalidIdentifierException extends DatabaseException
{
}
