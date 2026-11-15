<?php

declare(strict_types=1);

namespace Prime\Database\Schema;

use InvalidArgumentException;
use Prime\Database\Identifier;

/**
 * Declarative MySQL/MariaDB table schema blueprint supporting CREATE TABLE
 * and ALTER TABLE DDL generation.
 */
final class Blueprint
{
    private readonly string $prefixedTable;

    private readonly string $prefix;

    private readonly string $charset;

    private readonly string $collation;

    /**
     * @var list<ColumnDefinition>
     */
    private array $columns = [];

    /**
     * @var list<array{type: string, name: ?string, columns: list<string>}>
     */
    private array $indexes = [];

    /**
     * @var list<ForeignKeyDefinition>
     */
    private array $foreignKeys = [];

    /**
     * @var list<string>
     */
    private array $alterCommands = [];

    public function __construct(
        string $table,
        private readonly bool $creating = true,
        string $prefix = 'pc_',
        string $charset = 'utf8mb4',
        string $collation = 'utf8mb4_unicode_ci',
    ) {
        $this->prefix = Identifier::validatePrefix($prefix);
        $this->prefixedTable = Identifier::prefixTable($table, $this->prefix);
        $this->charset = Identifier::validateCharsetOrCollation($charset);
        $this->collation = Identifier::validateCharsetOrCollation($collation);
    }

    public function tableName(): string
    {
        return $this->prefixedTable;
    }

    public function id(string $name = 'id'): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($name, 'BIGINT', unsigned: true, autoIncrement: true, primary: true));
    }

    public function string(string $name, int $length = 191): ColumnDefinition
    {
        if ($length < 1 || $length > 65535) {
            throw new InvalidArgumentException('VARCHAR length must be between 1 and 65535.');
        }

        return $this->addColumn(new ColumnDefinition($name, sprintf('VARCHAR(%d)', $length)));
    }

    public function char(string $name, int $length = 191): ColumnDefinition
    {
        if ($length < 1 || $length > 255) {
            throw new InvalidArgumentException('CHAR length must be between 1 and 255.');
        }

        return $this->addColumn(new ColumnDefinition($name, sprintf('CHAR(%d)', $length)));
    }

    public function text(string $name): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($name, 'TEXT'));
    }

    public function mediumText(string $name): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($name, 'MEDIUMTEXT'));
    }

    public function longText(string $name): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($name, 'LONGTEXT'));
    }

    public function integer(string $name): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($name, 'INT'));
    }

    public function bigInteger(string $name): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($name, 'BIGINT'));
    }

    public function unsignedBigInteger(string $name): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($name, 'BIGINT', unsigned: true));
    }

    public function tinyInteger(string $name): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($name, 'TINYINT'));
    }

    public function boolean(string $name): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($name, 'TINYINT(1)'));
    }

    public function decimal(string $name, int $precision = 10, int $scale = 2): ColumnDefinition
    {
        if ($precision < 1 || $precision > 65 || $scale < 0 || $scale > $precision) {
            throw new InvalidArgumentException('Invalid DECIMAL precision/scale.');
        }

        return $this->addColumn(new ColumnDefinition($name, sprintf('DECIMAL(%d, %d)', $precision, $scale)));
    }

    public function json(string $name): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($name, 'JSON'));
    }

    public function date(string $name): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($name, 'DATE'));
    }

    public function dateTime(string $name): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($name, 'DATETIME'));
    }

    public function timestamp(string $name): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($name, 'TIMESTAMP'));
    }

    /**
     * Add UTC DATETIME `created_at` and `updated_at` columns.
     */
    public function timestamps(): void
    {
        $this->dateTime('created_at')->useCurrent();
        $this->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
    }

    public function foreignId(string $name): ColumnDefinition
    {
        return $this->unsignedBigInteger($name);
    }

    /**
     * @param string|list<string> $columns
     */
    public function primary(string|array $columns): self
    {
        $this->indexes[] = [
            'type' => 'PRIMARY',
            'name' => null,
            'columns' => $this->normalizeColumns($columns),
        ];

        return $this;
    }

    /**
     * @param string|list<string> $columns
     */
    public function unique(string|array $columns, ?string $name = null): self
    {
        $this->indexes[] = [
            'type' => 'UNIQUE',
            'name' => $name !== null ? Identifier::validateSimple($name) : null,
            'columns' => $this->normalizeColumns($columns),
        ];

        return $this;
    }

    /**
     * @param string|list<string> $columns
     */
    public function index(string|array $columns, ?string $name = null): self
    {
        $this->indexes[] = [
            'type' => 'INDEX',
            'name' => $name !== null ? Identifier::validateSimple($name) : null,
            'columns' => $this->normalizeColumns($columns),
        ];

        return $this;
    }

    /**
     * @param string|list<string> $columns
     */
    public function foreign(string|array $columns, ?string $name = null): ForeignKeyDefinition
    {
        $fk = new ForeignKeyDefinition($this->prefixedTable, $this->prefix, $this->normalizeColumns($columns), $name);
        $this->foreignKeys[] = $fk;

        return $fk;
    }

    /**
     * @param string|list<string> $columns
     */
    public function dropColumn(string|array $columns): self
    {
        foreach ($this->normalizeColumns($columns) as $col) {
            $this->alterCommands[] = 'DROP COLUMN ' . Identifier::quoteSimple($col);
        }

        return $this;
    }

    public function renameColumn(string $from, string $to): self
    {
        $this->alterCommands[] = sprintf(
            'RENAME COLUMN %s TO %s',
            Identifier::quoteSimple($from),
            Identifier::quoteSimple($to),
        );

        return $this;
    }

    public function dropIndex(string $indexName): self
    {
        $this->alterCommands[] = 'DROP INDEX ' . Identifier::quoteSimple($indexName);

        return $this;
    }

    public function dropForeign(string $constraintName): self
    {
        $this->alterCommands[] = 'DROP FOREIGN KEY ' . Identifier::quoteSimple($constraintName);

        return $this;
    }

    /**
     * Compile the blueprint into one or more MySQL/MariaDB DDL SQL statements.
     *
     * @return list<string>
     */
    public function toSql(): array
    {
        $allIndexes = $this->collectAllIndexes();

        if ($this->creating) {
            return [$this->compileCreateTable($allIndexes)];
        }

        return $this->compileAlterTable($allIndexes);
    }

    private function addColumn(ColumnDefinition $column): ColumnDefinition
    {
        $this->columns[] = $column;

        return $column;
    }

    /**
     * @param string|list<string> $columns
     * @return list<string>
     */
    private function normalizeColumns(string|array $columns): array
    {
        $list = is_array($columns) ? $columns : [$columns];
        $validated = [];

        foreach ($list as $col) {
            $validated[] = Identifier::validateSimple($col);
        }

        if ($validated === []) {
            throw new InvalidArgumentException('At least one column name is required.');
        }

        return $validated;
    }

    /**
     * @return list<array{type: string, name: ?string, columns: list<string>}>
     */
    private function collectAllIndexes(): array
    {
        $indexes = $this->indexes;

        foreach ($this->columns as $column) {
            if ($column->isUnique()) {
                $indexes[] = [
                    'type' => 'UNIQUE',
                    'name' => $column->uniqueName(),
                    'columns' => [$column->name()],
                ];
            }

            if ($column->isIndexed()) {
                $indexes[] = [
                    'type' => 'INDEX',
                    'name' => $column->indexName(),
                    'columns' => [$column->name()],
                ];
            }
        }

        return $indexes;
    }

    /**
     * @param list<array{type: string, name: ?string, columns: list<string>}> $indexes
     */
    private function compileCreateTable(array $indexes): string
    {
        $definitions = [];

        foreach ($this->columns as $column) {
            $definitions[] = $column->toSql(includeAfter: false);
        }

        foreach ($indexes as $index) {
            $definitions[] = $this->compileIndexDefinition($index);
        }

        foreach ($this->foreignKeys as $foreignKey) {
            $definitions[] = $foreignKey->toSql();
        }

        return sprintf(
            'CREATE TABLE %s (%s) ENGINE=InnoDB DEFAULT CHARSET=%s COLLATE=%s',
            Identifier::quoteSimple($this->prefixedTable),
            implode(', ', $definitions),
            $this->charset,
            $this->collation,
        );
    }

    /**
     * @param list<array{type: string, name: ?string, columns: list<string>}> $indexes
     * @return list<string>
     */
    private function compileAlterTable(array $indexes): array
    {
        $clauses = [];

        foreach ($this->columns as $column) {
            $clauses[] = 'ADD COLUMN ' . $column->toSql(includeAfter: true);
        }

        foreach ($indexes as $index) {
            $clauses[] = 'ADD ' . $this->compileIndexDefinition($index);
        }

        foreach ($this->foreignKeys as $foreignKey) {
            $clauses[] = 'ADD ' . $foreignKey->toSql();
        }

        foreach ($this->alterCommands as $command) {
            $clauses[] = $command;
        }

        if ($clauses === []) {
            return [];
        }

        return [
            sprintf(
                'ALTER TABLE %s %s',
                Identifier::quoteSimple($this->prefixedTable),
                implode(', ', $clauses),
            ),
        ];
    }

    /**
     * @param array{type: string, name: ?string, columns: list<string>} $index
     */
    private function compileIndexDefinition(array $index): string
    {
        $cols = implode(', ', array_map(static fn (string $c): string => Identifier::quoteSimple($c), $index['columns']));

        if ($index['type'] === 'PRIMARY') {
            return sprintf('PRIMARY KEY (%s)', $cols);
        }

        $prefix = $index['type'] === 'UNIQUE' ? 'uniq' : 'idx';
        $name = $index['name'] ?? $this->defaultIndexName($prefix, $index['columns']);

        if ($index['type'] === 'UNIQUE') {
            return sprintf('UNIQUE KEY %s (%s)', Identifier::quoteSimple($name), $cols);
        }

        return sprintf('INDEX %s (%s)', Identifier::quoteSimple($name), $cols);
    }

    /**
     * @param list<string> $columns
     */
    private function defaultIndexName(string $prefix, array $columns): string
    {
        $name = sprintf('%s_%s_%s', $prefix, $this->prefixedTable, implode('_', $columns));
        if (strlen($name) > 64) {
            $name = substr($name, 0, 55) . '_' . substr(sha1($name), 0, 8);
        }

        return $name;
    }
}
