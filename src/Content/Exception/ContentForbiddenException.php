<?php

declare(strict_types=1);

namespace Prime\Content\Exception;

/**
 * Thrown when a user lacks the required capability or ownership for a content operation.
 */
final class ContentForbiddenException extends ContentException
{
}
