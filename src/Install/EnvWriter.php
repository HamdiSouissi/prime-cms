<?php

declare(strict_types=1);

namespace Prime\Install;

use InvalidArgumentException;

/**
 * Creates or updates a `.env` configuration file while preserving unrelated keys,
 * comments, and blank lines, with safe escaping for special characters.
 */
final class EnvWriter
{
    /**
     * Generate a cryptographically secure 256-bit `APP_KEY` prefixed with `base64:`.
     */
    public function generateAppKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    /**
     * Format a `.env` value with proper double-quote escaping whenever special characters are present.
     */
    public function formatValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $needsQuotes = preg_match('/[\s#"\'\\\\$\r\n\t]/', $value) === 1;
        if (!$needsQuotes) {
            return $value;
        }

        $escaped = str_replace(
            ['\\', '"', '$', "\r", "\n", "\t"],
            ['\\\\', '\\"', '\\$', '\r', '\n', '\t'],
            $value,
        );

        return '"' . $escaped . '"';
    }

    /**
     * Render updated `.env` contents by merging `$values` into optional `$existingContent`
     * while preserving comments, blank lines, and all unrelated keys.
     *
     * @param array<string, string> $values
     */
    public function render(array $values, ?string $existingContent = null): string
    {
        foreach ($values as $key => $_) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                throw new InvalidArgumentException(sprintf('Invalid environment key [%s].', $key));
            }
        }

        if ($existingContent === null || $existingContent === '') {
            $lines = [
                '# Prime CMS — Configuration générée par l\'installateur',
                '',
            ];
            foreach ($values as $key => $value) {
                $lines[] = $key . '=' . $this->formatValue($value);
            }

            return implode("\n", $lines) . "\n";
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $existingContent);
        $rawLines = explode("\n", rtrim($normalized, "\n"));

        // First pass: detect which keys already exist as active (uncommented) assignments.
        $activeKeys = [];
        foreach ($rawLines as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $m) === 1) {
                $activeKeys[$m[1]] = true;
            }
        }

        $writtenKeys = [];
        $outputLines = [];

        foreach ($rawLines as $line) {
            // Active key match
            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $m) === 1) {
                $key = $m[1];
                if (array_key_exists($key, $values)) {
                    if (!isset($writtenKeys[$key])) {
                        $outputLines[] = $key . '=' . $this->formatValue($values[$key]);
                        $writtenKeys[$key] = true;
                    }
                    continue;
                }
            }

            // Commented-out template key match (e.g. `# DB_HOST=db` from `.env.example`)
            if (
                preg_match('/^\s*#\s*([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $m) === 1
                && array_key_exists($m[1], $values)
                && !isset($activeKeys[$m[1]])
                && !isset($writtenKeys[$m[1]])
            ) {
                $key = $m[1];
                $outputLines[] = $key . '=' . $this->formatValue($values[$key]);
                $writtenKeys[$key] = true;
                continue;
            }

            $outputLines[] = $line;
        }

        // Append any remaining keys not present in the existing file.
        $remaining = [];
        foreach ($values as $key => $value) {
            if (!isset($writtenKeys[$key])) {
                $remaining[] = $key . '=' . $this->formatValue($value);
            }
        }

        if ($remaining !== []) {
            $lastIndex = array_key_last($outputLines);
            if ($lastIndex !== null && $outputLines[$lastIndex] !== '') {
                $outputLines[] = '';
            }
            foreach ($remaining as $line) {
                $outputLines[] = $line;
            }
        }

        return implode("\n", $outputLines) . "\n";
    }

    /**
     * Determine whether `.env` can be created or overwritten on disk.
     */
    public function isWritable(string $envPath): bool
    {
        if (file_exists($envPath)) {
            return is_file($envPath) && is_writable($envPath);
        }

        $dir = dirname($envPath);

        return is_dir($dir) && is_writable($dir);
    }

    /**
     * Read existing `.env` (or fallback template such as `.env.example`) and return rendered content.
     *
     * @param array<string, string> $values
     */
    public function buildContent(string $envPath, array $values, ?string $templatePath = null): string
    {
        $existing = null;

        if (is_file($envPath) && is_readable($envPath)) {
            $raw = file_get_contents($envPath);
            if ($raw !== false) {
                $existing = $raw;
            }
        } elseif ($templatePath !== null && is_file($templatePath) && is_readable($templatePath)) {
            $raw = file_get_contents($templatePath);
            if ($raw !== false) {
                $existing = $raw;
            }
        }

        return $this->render($values, $existing);
    }

    /**
     * Write updated `.env` configuration to disk with `0640` permissions when possible.
     * Returns `false` if the destination is not writable.
     *
     * @param array<string, string> $values
     */
    public function write(string $envPath, array $values, ?string $templatePath = null): bool
    {
        if (!$this->isWritable($envPath)) {
            return false;
        }

        $content = $this->buildContent($envPath, $values, $templatePath);
        $written = @file_put_contents($envPath, $content, LOCK_EX);

        if ($written === false) {
            return false;
        }

        @chmod($envPath, 0640);

        return true;
    }
}
