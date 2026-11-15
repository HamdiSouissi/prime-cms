<?php

declare(strict_types=1);

namespace Prime\Hooks\Exception;

use InvalidArgumentException;

/**
 * Thrown when a hook name does not follow the lowercase dot-notation convention.
 */
final class InvalidHookNameException extends InvalidArgumentException
{
}
