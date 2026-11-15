<?php

declare(strict_types=1);

namespace Prime\Security\Exception;

use RuntimeException;

/**
 * Thrown when encryption or decryption fails (missing key, invalid format, or tampered ciphertext).
 */
final class EncryptionException extends RuntimeException
{
}
