<?php

declare(strict_types=1);

namespace Prime\Database\Schema;

use InvalidArgumentException;
use Prime\Database\Identifier;

/**
 * Fluent foreign key constraint builder for Schema Blueprints.
 */
final class ForeignKeyDefinition
{
    /**
     * @var list<string>
     */
    private const ALLOWED_ACTIONS = [
        'CASCADE',
        'SET NULL',
        'RESTRICT',
        'NO ACTION',
        'SET DEFAULT',
    ];

    /**
     * @var list<string>
     */
    private array $columns;

    /**
     * @var list<string>
     */
    private array $referencedColumns = ['id'];

    private ?string $referencedTable = null;

    private ?string $onDelete = null;

    private ?string $onUpdate = null;

    private ?string $name;

    /**
     * @param list<string> $columns
     */
    public function __construct(
        private readonly string $prefixedTable,
        private readonly string $prefix,
        array $columns,
        ?string $name = null,
    ) {
        $validated = [];
        foreach ($columns as $col) {
            $validated[] = Identifier::validateSimple($col);
        }
        if ($validated === []) {
            throw new InvalidArgumentException('Foreign key requires at least one column.');
        }

        $this->columns = $validated;
        $this->name = $name !== null ? Identifier::validateSimple($name) : null;
    }

    /**
     * @param string|list<string> $columns
     */
    public function references(string|array $columns): self
    {
        $list = is_array($columns) ? $columns : [$columns];
        $validated = [];
        foreach ($list as $col) {
            $validated[] = Identifier::validateSimple($col);
        }

        if ($validated === []) {
            throw new InvalidArgumentException('Foreign key references() requires at least one column.');
        }

        $this->referencedColumns = $validated;

        return $this;
    }

    public function on(string $table): self
    {
        $this->referencedTable = Identifier::prefixTable($table, $this->prefix);

        return $this;
    }

    public function onDelete(string $action): self
    {
        $this->onDelete = $this->validateAction($action);

        return $this;
    }

    public function onUpdate(string $action): self
    {
        $this->onUpdate = $this->validateAction($action);

        return $this;
    }

    public function name(string $name): self
    {
        $this->name = Identifier::validateSimple($name);

        return $this;
    }

    public function constraintName(): string
    {
        if ($this->name !== null) {
            return $this->name;
        }

        $generated = sprintf('fk_%s_%s', $this->prefixedTable, implode('_', $this->columns));
        if (strlen($generated) > 64) {
            $generated = substr($generated, 0, 55) . '_' . substr(sha1($generated), 0, 8);
        }

        return $generated;
    }

    public function toSql(): string
    {
        if ($this->referencedTable === null) {
            throw new InvalidArgumentException('Foreign key definition is missing ->on($table).');
        }

        $localCols = implode(', ', array_map(static fn (string $c): string => Identifier::quoteSimple($c), $this->columns));
        $refCols = implode(', ', array_map(static fn (string $c): string => Identifier::quoteSimple($c), $this->referencedColumns));

        $sql = sprintf(
            'CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s)',
            Identifier::quoteSimple($this->constraintName()),
            $localCols,
            Identifier::quoteSimple($this->referencedTable),
            $refCols,
        );

        if ($this->onDelete !== null) {
            $sql .= ' ON DELETE ' . $this->onDelete;
        }

        if ($this->onUpdate !== null) {
            $sql .= ' ON UPDATE ' . $this->onUpdate;
        }

        return $sql;
    }

    private function validateAction(string $action): string
    {
        $normalized = strtoupper(trim($action));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        if (!in_array($normalized, self::ALLOWED_ACTIONS, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported foreign key action [%s].', $action));
        }

        return $normalized;
    }
}
