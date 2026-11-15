<?php

declare(strict_types=1);

namespace Prime\Database\Exception;

/**
 * Thrown when an UPDATE or DELETE query is attempted without a WHERE clause
 * and without an explicit `->all()` confirmation.
 */
final class UnsafeQueryException extends DatabaseException
{
}
