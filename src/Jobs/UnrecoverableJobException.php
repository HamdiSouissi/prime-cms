<?php

declare(strict_types=1);

namespace Prime\Jobs;

use RuntimeException;

/**
 * Thrown by a handler when retrying cannot possibly help: a recipient that does not exist, a relay
 * the server refuses, a record the migration already dropped.
 *
 * `JobQueue` parks the job in `pc_jobs` as failed on this exception instead of burning the remaining
 * attempts, and keeps the message for the admin screen.
 */
final class UnrecoverableJobException extends RuntimeException
{
}
