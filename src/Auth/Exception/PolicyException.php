<?php

declare(strict_types=1);

namespace Prime\Auth\Exception;

use RuntimeException;

/**
 * Thrown when a security invariant or authorization policy prevents an operation
 * (e.g. disabling self, deleting the last active administrator, or privilege escalation).
 */
final class PolicyException extends RuntimeException
{
}
