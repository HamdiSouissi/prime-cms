<?php

declare(strict_types=1);

namespace Prime\Database\Schema;

use Prime\Database\Identifier;

/**
 * Fluent column definition built inside a Schema Blueprint callback.
 */
final class ColumnDefinition
{
    private bool $nullable = false;

    private bool $hasDefault = false;

    private mixed $default = null;

    private bool $defaultIsRaw = false;

    private bool $unsigned = false;

    private bool $autoIncrement = false;

    private bool $primary = false;

    private bool $unique = false;

    private ?string $uniqueName = null;

    private bool $index = false;

    private ?string $indexName = null;

    private ?string $comment = null;

    private ?string $after = null;

    private bool $onUpdateCurrent = false;

    public function __construct(
        private readonly string $name,
        private readonly string $sqlType,
        bool $unsigned = false,
        bool $autoIncrement = false,
        bool $primary = false,
    ) {
        Identifier::validateSimple($name);
        $this->unsigned = $unsigned;
        $this->autoIncrement = $autoIncrement;
        $this->primary = $primary;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;

        return $this;
    }

    public function default(mixed $value): self
    {
        $this->hasDefault = true;
        $this->default = $value;
        $this->defaultIsRaw = false;

        return $this;
    }

    public function useCurrent(): self
    {
        $this->hasDefault = true;
        $this->default = 'CURRENT_TIMESTAMP';
        $this->defaultIsRaw = true;

        return $this;
    }

    public function useCurrentOnUpdate(): self
    {
        $this->onUpdateCurrent = true;

        return $this;
    }

    public function unsigned(bool $unsigned = true): self
    {
        $this->unsigned = $unsigned;

        return $this;
    }

    public function autoIncrement(bool $autoIncrement = true): self
    {
        $this->autoIncrement = $autoIncrement;

        return $this;
    }

    public function primary(): self
    {
        $this->primary = true;

        return $this;
    }

    public function unique(?string $name = null): self
    {
        $this->unique = true;
        $this->uniqueName = $name !== null ? Identifier::validateSimple($name) : null;

        return $this;
    }

    public function index(?string $name = null): self
    {
        $this->index = true;
        $this->indexName = $name !== null ? Identifier::validateSimple($name) : null;

        return $this;
    }

    public function comment(string $comment): self
    {
        $this->comment = $comment;

        return $this;
    }

    public function after(string $column): self
    {
        $this->after = Identifier::validateSimple($column);

        return $this;
    }

    public function isUnique(): bool
    {
        return $this->unique;
    }

    public function uniqueName(): ?string
    {
        return $this->uniqueName;
    }

    public function isIndexed(): bool
    {
        return $this->index;
    }

    public function indexName(): ?string
    {
        return $this->indexName;
    }

    /**
     * Compile the column SQL definition (used inside CREATE TABLE or ALTER TABLE ADD COLUMN).
     */
    public function toSql(bool $includeAfter = false): string
    {
        $parts = [Identifier::quoteSimple($this->name), $this->sqlType];

        if ($this->unsigned && !str_contains(strtoupper($this->sqlType), 'UNSIGNED')) {
            $parts[] = 'UNSIGNED';
        }

        $parts[] = $this->nullable ? 'NULL' : 'NOT NULL';

        if ($this->hasDefault) {
            $parts[] = 'DEFAULT ' . $this->formatDefault($this->default, $this->defaultIsRaw);
        }

        if ($this->onUpdateCurrent) {
            $parts[] = 'ON UPDATE CURRENT_TIMESTAMP';
        }

        if ($this->autoIncrement) {
            $parts[] = 'AUTO_INCREMENT';
        }

        if ($this->primary) {
            $parts[] = 'PRIMARY KEY';
        }

        if ($this->comment !== null) {
            $parts[] = "COMMENT '" . $this->escapeString($this->comment) . "'";
        }

        if ($includeAfter && $this->after !== null) {
            $parts[] = 'AFTER ' . Identifier::quoteSimple($this->after);
        }

        return implode(' ', $parts);
    }

    private function formatDefault(mixed $value, bool $raw): string
    {
        if ($raw) {
            return (string) $value;
        }

        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'" . $this->escapeString((string) $value) . "'";
    }

    private function escapeString(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "''"], $value);
    }
}
