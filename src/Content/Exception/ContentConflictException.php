<?php

declare(strict_types=1);

namespace Prime\Content\Exception;

/**
 * Thrown when a content mutation conflicts with the entry's current lifecycle state
 * (e.g. attempting to permanently delete an entry that is not in the trash — HTTP 409).
 */
final class ContentConflictException extends ContentException
{
}
