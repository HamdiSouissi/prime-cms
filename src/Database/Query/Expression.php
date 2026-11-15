<?php

declare(strict_types=1);

namespace Prime\Database\Query;

/**
 * Explicit raw SQL fragment wrapper.
 *
 * DANGEROUS: Raw SQL expressions bypass identifier validation, quoting, and
 * parameter binding. NEVER construct an Expression from untrusted user input.
 */
final readonly class Expression
{
    private function __construct(private string $value)
    {
    }

    /**
     * Create a raw SQL expression.
     *
     * WARNING: Only pass static, developer-authored SQL fragments.
     */
    public static function raw(string $sql): self
    {
        return new self($sql);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
