<?php

declare(strict_types=1);

namespace Prime\Content\Exception;

/**
 * Thrown when an entry is actively locked for editing by another user.
 */
final class ContentLockedException extends ContentException
{
    public function __construct(
        public readonly int $entryId,
        public readonly int $lockedByUserId,
        public readonly string $lockedByName,
        public readonly string $acquiredAt,
        string $message = 'Ce contenu est actuellement verrouillé par un autre utilisateur.',
    ) {
        parent::__construct($message);
    }
}
