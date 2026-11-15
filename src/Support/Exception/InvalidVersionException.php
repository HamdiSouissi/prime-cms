<?php

declare(strict_types=1);

namespace Prime\Support\Exception;

use InvalidArgumentException;

/**
 * Thrown when a SemVer 2.0 version string or constraint expression is syntactically invalid.
 */
final class InvalidVersionException extends InvalidArgumentException
{
}
