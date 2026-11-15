<?php

declare(strict_types=1);

namespace Prime\Console;

/**
 * Lightweight CLI argument parser supporting `--key=value`, `--key value`,
 * boolean flags (`--force`, `--no-interaction`, `-n`), and positional arguments.
 */
final class ArgParser
{
    /**
     * @param list<string> $args
     * @param list<string> $booleanFlags Option names (without leading `--`) that never consume a following value
     * @return array{options: array<string, string|bool>, positionals: list<string>}
     */
    public static function parse(array $args, array $booleanFlags = []): array
    {
        $options = [];
        $positionals = [];
        $count = count($args);

        for ($i = 0; $i < $count; $i++) {
            $token = $args[$i];

            if ($token === '-n') {
                $options['no-interaction'] = true;
                continue;
            }

            if (str_starts_with($token, '--') && strlen($token) > 2) {
                $body = substr($token, 2);
                $eqPos = strpos($body, '=');

                if ($eqPos !== false) {
                    $key = substr($body, 0, $eqPos);
                    $val = substr($body, $eqPos + 1);
                    $options[$key] = $val;
                    continue;
                }

                if (in_array($body, $booleanFlags, true)) {
                    $options[$body] = true;
                    continue;
                }

                $next = $args[$i + 1] ?? null;
                if ($next !== null && !str_starts_with($next, '-')) {
                    $options[$body] = $next;
                    $i++;
                } else {
                    $options[$body] = true;
                }
                continue;
            }

            $positionals[] = $token;
        }

        return [
            'options' => $options,
            'positionals' => $positionals,
        ];
    }

    /**
     * @param array<string, string|bool> $options
     */
    public static function stringOption(array $options, string $key, string $default = ''): string
    {
        $val = $options[$key] ?? null;

        return is_string($val) ? $val : $default;
    }

    /**
     * @param array<string, string|bool> $options
     */
    public static function hasFlag(array $options, string $key): bool
    {
        if (!array_key_exists($key, $options)) {
            return false;
        }

        $val = $options[$key];
        if (is_bool($val)) {
            return $val;
        }

        return !in_array(strtolower($val), ['0', 'false', 'no', ''], true);
    }
}
