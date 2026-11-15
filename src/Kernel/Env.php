<?php

declare(strict_types=1);

namespace Prime\Kernel;

/**
 * Lightweight `.env` parser and environment variable reader.
 */
final class Env
{
    /**
     * Load environment variables from a `.env` file without overwriting existing ones.
     */
    public static function load(string $filePath): void
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return;
        }

        foreach (self::parse($content) as $key => $value) {
            self::set($key, $value, false);
        }
    }

    /**
     * Parse raw `.env` file content into a key-value map of strings.
     *
     * @return array<string, string>
     */
    public static function parse(string $content): array
    {
        $variables = [];
        $lines = preg_split('/\r\n|\n|\r/', $content);
        if ($lines === false) {
            return $variables;
        }

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }

            $separatorPos = strpos($line, '=');
            if ($separatorPos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separatorPos));
            if ($key === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                continue;
            }

            $rawValue = ltrim(substr($line, $separatorPos + 1));
            $variables[$key] = self::parseValue($rawValue);
        }

        return $variables;
    }

    /**
     * Retrieve an environment variable with automatic type conversion.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $_ENV)) {
            $value = $_ENV[$key];
        } elseif (array_key_exists($key, $_SERVER)) {
            $value = $_SERVER[$key];
        } else {
            $envValue = getenv($key);
            if ($envValue === false) {
                return $default;
            }
            $value = $envValue;
        }

        if (!is_string($value)) {
            return $value;
        }

        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            '(empty)' => '',
            default => $value,
        };
    }

    /**
     * Check whether an environment variable is already defined.
     */
    public static function has(string $key): bool
    {
        return array_key_exists($key, $_ENV)
            || array_key_exists($key, $_SERVER)
            || getenv($key) !== false;
    }

    /**
     * Define an environment variable, optionally preserving existing definitions.
     */
    public static function set(string $key, string $value, bool $overwrite = false): void
    {
        if (!$overwrite && self::has($key)) {
            return;
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }

    /**
     * Remove an environment variable (primarily useful in tests).
     */
    public static function unset(string $key): void
    {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }

    /**
     * Parse an individual raw value (single-quoted, double-quoted, or unquoted).
     */
    private static function parseValue(string $rawValue): string
    {
        if ($rawValue === '') {
            return '';
        }

        $firstChar = $rawValue[0];

        if ($firstChar === '"') {
            return self::parseDoubleQuoted($rawValue);
        }

        if ($firstChar === "'") {
            return self::parseSingleQuoted($rawValue);
        }

        // Unquoted value: strip inline comment (` #...` or leading `#`)
        if ($firstChar === '#') {
            return '';
        }

        $commentPos = preg_match('/\s+#/', $rawValue, $matches, PREG_OFFSET_CAPTURE);
        if ($commentPos === 1) {
            $rawValue = substr($rawValue, 0, (int) $matches[0][1]);
        }

        return trim($rawValue);
    }

    private static function parseDoubleQuoted(string $rawValue): string
    {
        $length = strlen($rawValue);
        $result = '';
        $escaped = false;

        for ($i = 1; $i < $length; $i++) {
            $char = $rawValue[$i];

            if ($escaped) {
                $result .= match ($char) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    '"' => '"',
                    '\\' => '\\',
                    '$' => '$',
                    default => '\\' . $char,
                };
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                continue;
            }

            if ($char === '"') {
                break;
            }

            $result .= $char;
        }

        return $result;
    }

    private static function parseSingleQuoted(string $rawValue): string
    {
        $closingPos = strpos($rawValue, "'", 1);
        if ($closingPos === false) {
            return substr($rawValue, 1);
        }

        return substr($rawValue, 1, $closingPos - 1);
    }
}
