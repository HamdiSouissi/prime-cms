<?php

declare(strict_types=1);

namespace Prime\Mail;

/**
 * One reply read from the server: the three-digit code and every line that came with it.
 *
 * Multi-line replies (`250-AUTH`, `250-SIZE`, `250 help`) keep their lines so capability parsing has
 * something to work on; `text` is the human-readable part an administrator should see.
 */
final class SmtpResponse
{
    /**
     * @param list<string> $lines
     */
    public function __construct(
        public readonly int $code,
        public readonly array $lines = [],
    ) {
    }

    public static function parse(string $raw): self
    {
        $raw = rtrim(str_replace(["\r\n", "\r"], "\n", $raw), "\n");
        $lines = $raw === '' ? [] : explode("\n", $raw);
        $code = 0;

        if ($lines !== []) {
            $code = (int) substr(trim($lines[0]), 0, 3);
        }

        return new self($code, $lines);
    }

    public function text(): string
    {
        return implode(' ', array_map(
            static fn (string $line): string => trim((string) preg_replace('/^\d{3}[- ]?/', '', $line)),
            $this->lines,
        ));
    }

    /**
     * @return list<string>
     */
    public function verbs(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $line): ?string => preg_match('/^\d{3}-([A-Z][A-Z0-9-]*)/i', $line, $m) === 1 ? strtoupper($m[1]) : null,
            $this->lines,
        )));
    }

    public function isPositive(): bool
    {
        return $this->code >= 200 && $this->code < 400;
    }

    public function isTransient(): bool
    {
        return $this->code >= 400 && $this->code < 500;
    }

    /**
     * Whether this reply advertises a capability, e.g. `AUTH PLAIN` or `STARTTLS`.
     */
    public function advertises(string $verb): bool
    {
        foreach ($this->lines as $line) {
            if (stripos($line, $verb) !== false) {
                return true;
            }
        }

        return false;
    }
}
