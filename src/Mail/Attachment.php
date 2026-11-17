<?php

declare(strict_types=1);

namespace Prime\Mail;

use InvalidArgumentException;

/**
 * A file carried by an e-mail: content already in memory, base64 or on disk, never a path coming
 * straight from a request.
 */
final class Attachment
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    private function __construct(
        public readonly string $filename,
        public readonly string $mime,
        public readonly string $contents,
    ) {
    }

    public static function fromContents(string $contents, string $filename, ?string $mime = null): self
    {
        if (strlen($contents) > self::MAX_BYTES) {
            throw new InvalidArgumentException(sprintf('La pièce jointe « %s » dépasse la taille autorisée.', $filename));
        }

        return new self(
            self::sanitizeName($filename),
            $mime !== null && $mime !== '' ? $mime : self::guessMime($filename),
            $contents,
        );
    }

    public static function fromBase64(string $base64, string $filename, ?string $mime = null): self
    {
        $contents = base64_decode(preg_replace('/\s+/', '', $base64) ?? '', true);

        if (!is_string($contents) || $contents === '') {
            throw new InvalidArgumentException(sprintf('La pièce jointe « %s » n\'est pas encodée en base64.', $filename));
        }

        return self::fromContents($contents, $filename, $mime);
    }

    /**
     * Read a file that lives inside the given directory — the theme and plugin uploads folders, in
     * practice. Anything resolving outside of it is refused.
     */
    public static function fromFile(string $path, string $directory, ?string $mime = null): self
    {
        $root = realpath($directory);
        $real = realpath($path);

        if ($root === false || $real === false || !is_file($real) || !str_starts_with($real, rtrim($root, '/') . '/')) {
            throw new InvalidArgumentException('La pièce jointe demandée est hors de portée ou introuvable.');
        }

        $contents = file_get_contents($real);

        if ($contents === false) {
            throw new InvalidArgumentException(sprintf('Impossible de lire le fichier joint : %s.', $real));
        }

        return self::fromContents($contents, basename($real), $mime);
    }

    public function size(): int
    {
        return strlen($this->contents);
    }

    private static function sanitizeName(string $filename): string
    {
        $name = str_replace(['"', '\\', "\r", "\n"], '', basename(trim($filename)));

        return $name !== '' ? $name : 'piece-jointe';
    }

    private static function guessMime(string $filename): string
    {
        return match (strtolower((string) pathinfo($filename, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'csv' => 'text/csv',
            'txt', 'log' => 'text/plain',
            'zip' => 'application/zip',
            'json' => 'application/json',
            default => 'application/octet-stream',
        };
    }
}
