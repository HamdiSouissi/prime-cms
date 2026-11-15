<?php

declare(strict_types=1);

namespace Prime\Database\Query;

use Closure;
use InvalidArgumentException;
use Prime\Database\Connection;
use Prime\Database\Exception\UnsafeQueryException;
use Prime\Database\Identifier;

/**
 * Fluent, prepared-only SQL query builder with strict identifier, operator,
 * and direction validation plus automatic table prefixing.
 */
final class QueryBuilder
{
    /**
     * Whitelisted comparison operators for WHERE, JOIN, and HAVING clauses.
     *
     * @var list<string>
     */
    private const ALLOWED_OPERATORS = [
        '=',
        '!=',
        '<>',
        '<',
        '<=',
        '>',
        '>=',
        'LIKE',
        'NOT LIKE',
    ];

    /**
     * Whitelisted ORDER BY directions.
     *
     * @var list<string>
     */
    private const ALLOWED_DIRECTIONS = ['ASC', 'DESC'];

    private readonly string $tableSql;

    private readonly string $prefixedTable;

    /**
     * Map of unprefixed table names to their active SQL reference (prefixed name or alias).
     *
     * @var array<string, string>
     */
    private array $tableMap = [];

    /**
     * @var list<string|Expression>
     */
    private array $columns = ['*'];

    private bool $distinct = false;

    /**
     * @var list<array{type: string, table: string, first: string, operator: string, second: string}>
     */
    private array $joins = [];

    /**
     * @var list<array{boolean: string, sql: string}>
     */
    private array $wheres = [];

    /**
     * @var list<mixed>
     */
    private array $whereBindings = [];

    /**
     * @var list<string|Expression>
     */
    private array $groups = [];

    /**
     * @var list<array{boolean: string, sql: string}>
     */
    private array $havings = [];

    /**
     * @var list<mixed>
     */
    private array $havingBindings = [];

    /**
     * @var list<array{column: string|Expression, direction: string}>
     */
    private array $orders = [];

    private ?int $limit = null;

    private ?int $offset = null;

    private bool $allowAll = false;

    public function __construct(
        private readonly Connection $connection,
        string $table,
    ) {
        $parsed = Identifier::parseTable($table, $connection->prefix());
        $this->tableSql = $parsed['sql'];
        $this->prefixedTable = $parsed['prefixed'];

        if ($parsed['alias'] !== null) {
            $this->tableMap[$parsed['alias']] = $parsed['alias'];
        } else {
            $this->tableMap[$parsed['raw']] = $parsed['prefixed'];
        }
    }

    /**
     * Define columns to select.
     *
     * @param string|Expression|list<string|Expression> ...$columns
     */
    public function select(string|Expression|array ...$columns): self
    {
        $flat = [];
        foreach ($columns as $column) {
            if (is_array($column)) {
                foreach ($column as $item) {
                    $flat[] = $item;
                }
            } else {
                $flat[] = $column;
            }
        }

        if ($flat !== []) {
            foreach ($flat as $col) {
                if (is_string($col)) {
                    $this->quoteColumn($col, allowStar: true);
                }
            }
            $this->columns = $flat;
        }

        return $this;
    }

    public function distinct(bool $distinct = true): self
    {
        $this->distinct = $distinct;

        return $this;
    }

    /**
     * Add an INNER JOIN clause.
     */
    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self
    {
        $upperType = strtoupper(trim($type));
        if (!in_array($upperType, ['INNER', 'LEFT'], true)) {
            throw new InvalidArgumentException(sprintf('Unsupported JOIN type [%s].', $type));
        }

        $parsed = Identifier::parseTable($table, $this->connection->prefix());
        if ($parsed['alias'] !== null) {
            $this->tableMap[$parsed['alias']] = $parsed['alias'];
        } else {
            $this->tableMap[$parsed['raw']] = $parsed['prefixed'];
        }

        $validatedOperator = $this->validateOperator($operator);

        $this->joins[] = [
            'type' => $upperType,
            'table' => $parsed['sql'],
            'first' => $this->quoteColumn($first),
            'operator' => $validatedOperator,
            'second' => $this->quoteColumn($second),
        ];

        return $this;
    }

    /**
     * Add a LEFT JOIN clause.
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    /**
     * Add an AND WHERE condition or a parenthesized nested WHERE group via Closure.
     */
    public function where(string|Expression|Closure $column, mixed $operator = null, mixed $value = null): self
    {
        if ($column instanceof Closure) {
            return $this->addNestedWhere($column, 'AND');
        }

        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        return $this->addBasicWhere($column, (string) $operator, $value, 'AND');
    }

    /**
     * Add an OR WHERE condition or a parenthesized nested OR WHERE group via Closure.
     */
    public function orWhere(string|Expression|Closure $column, mixed $operator = null, mixed $value = null): self
    {
        if ($column instanceof Closure) {
            return $this->addNestedWhere($column, 'OR');
        }

        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        return $this->addBasicWhere($column, (string) $operator, $value, 'OR');
    }

    /**
     * Add a WHERE column IN (...) condition.
     *
     * @param array<int|string, mixed> $values
     */
    public function whereIn(string|Expression $column, array $values, string $boolean = 'AND', bool $not = false): self
    {
        $bool = $this->validateBoolean($boolean);
        $quotedColumn = $this->compileColumnExpression($column);
        $list = array_values($values);

        if ($list === []) {
            $this->wheres[] = [
                'boolean' => $bool,
                'sql' => $not ? '1 = 1' : '1 = 0',
            ];

            return $this;
        }

        $placeholders = implode(', ', array_fill(0, count($list), '?'));
        $keyword = $not ? 'NOT IN' : 'IN';

        $this->wheres[] = [
            'boolean' => $bool,
            'sql' => sprintf('%s %s (%s)', $quotedColumn, $keyword, $placeholders),
        ];

        foreach ($list as $val) {
            $this->whereBindings[] = $val;
        }

        return $this;
    }

    /**
     * Add a WHERE column NOT IN (...) condition.
     *
     * @param array<int|string, mixed> $values
     */
    public function whereNotIn(string|Expression $column, array $values, string $boolean = 'AND'): self
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    /**
     * Add a WHERE column IS NULL condition.
     */
    public function whereNull(string|Expression $column, string $boolean = 'AND', bool $not = false): self
    {
        $bool = $this->validateBoolean($boolean);
        $quotedColumn = $this->compileColumnExpression($column);
        $operator = $not ? 'IS NOT NULL' : 'IS NULL';

        $this->wheres[] = [
            'boolean' => $bool,
            'sql' => sprintf('%s %s', $quotedColumn, $operator),
        ];

        return $this;
    }

    /**
     * Add a WHERE column IS NOT NULL condition.
     */
    public function whereNotNull(string|Expression $column, string $boolean = 'AND'): self
    {
        return $this->whereNull($column, $boolean, true);
    }

    /**
     * Add a WHERE column BETWEEN ? AND ? condition.
     * Accepts either `whereBetween('age', 18, 65)` or `whereBetween('age', [18, 65])`.
     */
    public function whereBetween(
        string|Expression $column,
        mixed $min,
        mixed $max = null,
        string $boolean = 'AND',
        bool $not = false,
    ): self {
        if (is_array($min) && $max === null) {
            $bounds = array_values($min);
            if (count($bounds) !== 2) {
                throw new InvalidArgumentException('whereBetween() array argument must contain exactly two values.');
            }
            $min = $bounds[0];
            $max = $bounds[1];
        }

        $bool = $this->validateBoolean($boolean);
        $quotedColumn = $this->compileColumnExpression($column);
        $keyword = $not ? 'NOT BETWEEN' : 'BETWEEN';

        $this->wheres[] = [
            'boolean' => $bool,
            'sql' => sprintf('%s %s ? AND ?', $quotedColumn, $keyword),
        ];
        $this->whereBindings[] = $min;
        $this->whereBindings[] = $max;

        return $this;
    }

    /**
     * Add an ORDER BY clause with strict direction validation.
     */
    public function orderBy(string|Expression $column, string $direction = 'ASC'): self
    {
        $upper = strtoupper(trim($direction));
        if (!in_array($upper, self::ALLOWED_DIRECTIONS, true)) {
            throw new InvalidArgumentException(sprintf('Invalid ORDER BY direction [%s]. Expected ASC or DESC.', $direction));
        }

        if (is_string($column)) {
            // Validate early so callers get immediate feedback before execution.
            $this->quoteColumn($column);
        }

        $this->orders[] = [
            'column' => $column,
            'direction' => $upper,
        ];

        return $this;
    }

    /**
     * Add one or more GROUP BY columns.
     *
     * @param string|Expression|list<string|Expression> ...$columns
     */
    public function groupBy(string|Expression|array ...$columns): self
    {
        foreach ($columns as $column) {
            if (is_array($column)) {
                foreach ($column as $item) {
                    if (is_string($item)) {
                        $this->quoteColumn($item);
                    }
                    $this->groups[] = $item;
                }
            } else {
                if (is_string($column)) {
                    $this->quoteColumn($column);
                }
                $this->groups[] = $column;
            }
        }

        return $this;
    }

    /**
     * Add a HAVING condition with strict operator validation.
     */
    public function having(string|Expression $column, string $operator, mixed $value, string $boolean = 'AND'): self
    {
        $bool = $this->validateBoolean($boolean);
        $validatedOperator = $this->validateOperator($operator);
        $quotedColumn = $this->compileColumnExpression($column);

        $this->havings[] = [
            'boolean' => $bool,
            'sql' => sprintf('%s %s ?', $quotedColumn, $validatedOperator),
        ];
        $this->havingBindings[] = $value;

        return $this;
    }

    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new InvalidArgumentException('LIMIT cannot be negative.');
        }

        $this->limit = $limit;

        return $this;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('OFFSET cannot be negative.');
        }

        $this->offset = $offset;

        return $this;
    }

    /**
     * Explicitly allow UPDATE or DELETE statements without a WHERE clause.
     */
    public function all(): self
    {
        $this->allowAll = true;

        return $this;
    }

    /**
     * Compile the current SELECT SQL statement.
     */
    public function toSql(): string
    {
        $selectKeyword = $this->distinct ? 'SELECT DISTINCT ' : 'SELECT ';
        $columns = [];

        foreach ($this->columns as $column) {
            $columns[] = $column instanceof Expression
                ? $column->value()
                : $this->quoteColumn($column, allowStar: true);
        }

        $sql = $selectKeyword . implode(', ', $columns) . ' FROM ' . $this->tableSql;

        if ($this->joins !== []) {
            foreach ($this->joins as $join) {
                $sql .= sprintf(
                    ' %s JOIN %s ON %s %s %s',
                    $join['type'],
                    $join['table'],
                    $join['first'],
                    $join['operator'],
                    $join['second'],
                );
            }
        }

        $sql .= $this->compileWhereClause();

        if ($this->groups !== []) {
            $groupCols = [];
            foreach ($this->groups as $group) {
                $groupCols[] = $this->compileColumnExpression($group);
            }
            $sql .= ' GROUP BY ' . implode(', ', $groupCols);
        }

        if ($this->havings !== []) {
            $sql .= ' HAVING ' . $this->compileClauseList($this->havings);
        }

        if ($this->orders !== []) {
            $orderParts = [];
            foreach ($this->orders as $order) {
                $orderParts[] = $this->compileColumnExpression($order['column']) . ' ' . $order['direction'];
            }
            $sql .= ' ORDER BY ' . implode(', ', $orderParts);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }

        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        return $sql;
    }

    /**
     * Return all parameter bindings matching the compiled SELECT SQL.
     *
     * @return list<mixed>
     */
    public function bindings(): array
    {
        return array_merge($this->whereBindings, $this->havingBindings);
    }

    /**
     * Execute the SELECT query and return all matching rows.
     *
     * @return list<array<string, mixed>>
     */
    public function get(): array
    {
        return $this->connection->select($this->toSql(), $this->bindings());
    }

    /**
     * Execute the query with LIMIT 1 and return the first row or null.
     *
     * @return ?array<string, mixed>
     */
    public function first(): ?array
    {
        $clone = clone $this;
        $clone->limit(1);

        return $this->connection->selectOne($clone->toSql(), $clone->bindings());
    }

    /**
     * Find a single row by its primary key (default `id`).
     *
     * @return ?array<string, mixed>
     */
    public function find(int|string $id, string $column = 'id'): ?array
    {
        $clone = clone $this;
        $clone->where($column, '=', $id);

        return $clone->first();
    }

    /**
     * Fetch a single column value from the first matching row.
     */
    public function value(string $column): mixed
    {
        $clone = clone $this;
        $clone->select($column)->limit(1);

        return $this->connection->scalar($clone->toSql(), $clone->bindings());
    }

    /**
     * Retrieve a single column's values as a flat list, or keyed by `$key`.
     *
     * @return array<int|string, mixed>
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $clone = clone $this;
        $columnsToSelect = $key !== null ? [$key, $column] : [$column];
        $clone->select($columnsToSelect);

        $rows = $clone->get();
        $valueKey = $this->extractColumnResultName($column);
        $indexKey = $key !== null ? $this->extractColumnResultName($key) : null;

        $results = [];
        foreach ($rows as $row) {
            $val = $row[$valueKey] ?? null;
            if ($indexKey !== null) {
                $idx = $row[$indexKey] ?? '';
                $results[is_int($idx) ? $idx : (string) $idx] = $val;
            } else {
                $results[] = $val;
            }
        }

        return $results;
    }

    /**
     * Return the number of matching rows.
     */
    public function count(string $column = '*'): int
    {
        $result = $this->aggregate('COUNT', $column);

        return is_numeric($result) ? (int) $result : 0;
    }

    /**
     * Determine whether at least one matching row exists.
     */
    public function exists(): bool
    {
        $clone = clone $this;
        $clone->select(Expression::raw('1'));
        $clone->orders = [];
        $clone->limit(1);
        $clone->offset = null;

        return $this->connection->scalar($clone->toSql(), $clone->bindings()) !== null;
    }

    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    public function sum(string $column): int|float
    {
        $result = $this->aggregate('SUM', $column);
        if (!is_numeric($result)) {
            return 0;
        }

        return str_contains((string) $result, '.') ? (float) $result : (int) $result;
    }

    public function avg(string $column): ?float
    {
        $result = $this->aggregate('AVG', $column);

        return is_numeric($result) ? (float) $result : null;
    }

    /**
     * Paginate the current query and return items alongside pagination metadata.
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    public function paginate(int $page = 1, int $perPage = 15): array
    {
        $safePage = max(1, $page);
        $safePerPage = max(1, $perPage);

        $countQuery = clone $this;
        $countQuery->orders = [];
        $countQuery->limit = null;
        $countQuery->offset = null;

        if ($this->groups !== [] || $this->distinct) {
            $subSql = $countQuery->toSql();
            $totalVal = $this->connection->scalar(
                sprintf('SELECT COUNT(*) FROM (%s) AS `prime_count_sub`', $subSql),
                $countQuery->bindings(),
            );
            $total = is_numeric($totalVal) ? (int) $totalVal : 0;
        } else {
            $total = $countQuery->count();
        }

        $totalPages = $total === 0 ? 0 : (int) ceil($total / $safePerPage);

        $itemsQuery = clone $this;
        $itemsQuery->limit($safePerPage)->offset(($safePage - 1) * $safePerPage);
        $items = $itemsQuery->get();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $safePage,
            'per_page' => $safePerPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Insert one associative row or a batch list of associative rows.
     *
     * @param array<string, mixed>|list<array<string, mixed>> $values
     */
    public function insert(array $values): bool
    {
        if ($values === []) {
            return true;
        }

        [$sql, $bindings] = $this->compileInsert($values);

        return $this->connection->statement($sql, $bindings);
    }

    /**
     * Insert a single associative row and return its generated auto-increment ID.
     *
     * @param array<string, mixed> $values
     */
    public function insertGetId(array $values): int
    {
        if ($values === []) {
            throw new InvalidArgumentException('Cannot insert an empty row.');
        }

        [$sql, $bindings] = $this->compileInsert([$values]);

        return $this->connection->insert($sql, $bindings);
    }

    /**
     * Update matching rows. Throws UnsafeQueryException if no WHERE clause is set
     * unless `->all()` was called explicitly.
     *
     * @param array<string, mixed> $values
     */
    public function update(array $values): int
    {
        $this->guardUnsafeWrite('UPDATE');

        if ($values === []) {
            throw new InvalidArgumentException('UPDATE values array cannot be empty.');
        }

        $setClauses = [];
        $bindings = [];

        foreach ($values as $column => $value) {
            $quoted = Identifier::quoteSimple($column);
            if ($value instanceof Expression) {
                $setClauses[] = sprintf('%s = %s', $quoted, $value->value());
            } else {
                $setClauses[] = sprintf('%s = ?', $quoted);
                $bindings[] = $value;
            }
        }

        $sql = sprintf(
            'UPDATE `%s` SET %s%s',
            $this->prefixedTable,
            implode(', ', $setClauses),
            $this->compileWhereClause(),
        );

        $allBindings = array_merge($bindings, $this->whereBindings);

        return $this->connection->update($sql, $allBindings);
    }

    /**
     * Atomically increment a numeric column on matching rows.
     *
     * @param array<string, mixed> $extra Additional columns to update simultaneously
     */
    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->adjustColumn($column, '+', $amount, $extra);
    }

    /**
     * Atomically decrement a numeric column on matching rows.
     *
     * @param array<string, mixed> $extra Additional columns to update simultaneously
     */
    public function decrement(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->adjustColumn($column, '-', $amount, $extra);
    }

    /**
     * Delete matching rows. Throws UnsafeQueryException if no WHERE clause is set
     * unless `->all()` was called explicitly.
     */
    public function delete(): int
    {
        $this->guardUnsafeWrite('DELETE');

        $sql = sprintf(
            'DELETE FROM `%s`%s',
            $this->prefixedTable,
            $this->compileWhereClause(),
        );

        return $this->connection->delete($sql, $this->whereBindings);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function adjustColumn(string $column, string $sign, int|float $amount, array $extra): int
    {
        $this->guardUnsafeWrite('UPDATE');

        $quoted = Identifier::quoteSimple($column);
        $setClauses = [sprintf('%s = %s %s ?', $quoted, $quoted, $sign)];
        $bindings = [$amount];

        foreach ($extra as $extraColumn => $value) {
            $quotedExtra = Identifier::quoteSimple($extraColumn);
            if ($value instanceof Expression) {
                $setClauses[] = sprintf('%s = %s', $quotedExtra, $value->value());
            } else {
                $setClauses[] = sprintf('%s = ?', $quotedExtra);
                $bindings[] = $value;
            }
        }

        $sql = sprintf(
            'UPDATE `%s` SET %s%s',
            $this->prefixedTable,
            implode(', ', $setClauses),
            $this->compileWhereClause(),
        );

        return $this->connection->update($sql, array_merge($bindings, $this->whereBindings));
    }

    /**
     * @param array<string, mixed>|list<array<string, mixed>> $values
     * @return array{0: string, 1: list<mixed>}
     */
    private function compileInsert(array $values): array
    {
        $rows = array_is_list($values) ? $values : [$values];

        $firstRow = $rows[0];
        if (!is_array($firstRow) || $firstRow === []) {
            throw new InvalidArgumentException('INSERT rows must be non-empty associative arrays.');
        }

        $columns = array_keys($firstRow);
        $quotedColumns = [];
        foreach ($columns as $col) {
            $quotedColumns[] = Identifier::quoteSimple((string) $col);
        }

        $rowPlaceholders = [];
        $bindings = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Each INSERT row must be an associative array.');
            }

            $placeholders = [];
            foreach ($columns as $col) {
                if (!array_key_exists($col, $row)) {
                    throw new InvalidArgumentException(sprintf('Missing column [%s] in batch INSERT row.', (string) $col));
                }
                $val = $row[$col];
                if ($val instanceof Expression) {
                    $placeholders[] = $val->value();
                } else {
                    $placeholders[] = '?';
                    $bindings[] = $val;
                }
            }
            $rowPlaceholders[] = '(' . implode(', ', $placeholders) . ')';
        }

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES %s',
            $this->prefixedTable,
            implode(', ', $quotedColumns),
            implode(', ', $rowPlaceholders),
        );

        return [$sql, $bindings];
    }

    private function aggregate(string $function, string $column): mixed
    {
        $target = ($function === 'COUNT' && trim($column) === '*')
            ? '*'
            : $this->quoteColumn($column);

        $clone = clone $this;
        $clone->columns = [Expression::raw(sprintf('%s(%s) AS `aggregate`', $function, $target))];
        $clone->orders = [];
        $clone->limit = null;
        $clone->offset = null;

        return $this->connection->scalar($clone->toSql(), $clone->bindings());
    }

    private function addBasicWhere(string|Expression $column, string $operator, mixed $value, string $boolean): self
    {
        $validatedOperator = $this->validateOperator($operator);
        $quotedColumn = $this->compileColumnExpression($column);

        $this->wheres[] = [
            'boolean' => $this->validateBoolean($boolean),
            'sql' => sprintf('%s %s ?', $quotedColumn, $validatedOperator),
        ];
        $this->whereBindings[] = $value;

        return $this;
    }

    private function addNestedWhere(Closure $callback, string $boolean): self
    {
        $nested = new self($this->connection, $this->prefixedTable);
        $nested->tableMap = $this->tableMap;

        $callback($nested);

        if ($nested->wheres !== []) {
            $this->wheres[] = [
                'boolean' => $this->validateBoolean($boolean),
                'sql' => '(' . $this->compileClauseList($nested->wheres) . ')',
            ];
            foreach ($nested->whereBindings as $binding) {
                $this->whereBindings[] = $binding;
            }
        }

        return $this;
    }

    private function compileWhereClause(): string
    {
        if ($this->wheres === []) {
            return '';
        }

        return ' WHERE ' . $this->compileClauseList($this->wheres);
    }

    /**
     * @param list<array{boolean: string, sql: string}> $clauses
     */
    private function compileClauseList(array $clauses): string
    {
        $parts = [];
        foreach ($clauses as $index => $clause) {
            if ($index === 0) {
                $parts[] = $clause['sql'];
            } else {
                $parts[] = $clause['boolean'] . ' ' . $clause['sql'];
            }
        }

        return implode(' ', $parts);
    }

    private function compileColumnExpression(string|Expression $column): string
    {
        return $column instanceof Expression
            ? $column->value()
            : $this->quoteColumn($column);
    }

    private function quoteColumn(string $column, bool $allowStar = false): string
    {
        return Identifier::quoteColumn($column, $this->tableMap, $allowStar);
    }

    private function validateOperator(string $operator): string
    {
        $normalized = strtoupper(trim($operator));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        if (!in_array($normalized, self::ALLOWED_OPERATORS, true)) {
            throw new InvalidArgumentException(sprintf('Disallowed SQL operator [%s].', $operator));
        }

        return $normalized;
    }

    private function validateBoolean(string $boolean): string
    {
        $upper = strtoupper(trim($boolean));
        if ($upper !== 'AND' && $upper !== 'OR') {
            throw new InvalidArgumentException(sprintf('Invalid boolean glue [%s]. Expected AND or OR.', $boolean));
        }

        return $upper;
    }

    private function guardUnsafeWrite(string $operation): void
    {
        if ($this->wheres === [] && !$this->allowAll) {
            throw new UnsafeQueryException(sprintf(
                'Refusing to execute %s on [%s] without a WHERE clause. Call ->all() explicitly if you intend to affect every row.',
                $operation,
                $this->prefixedTable,
            ));
        }
    }

    private function extractColumnResultName(string $column): string
    {
        $trimmed = trim($column);
        if (preg_match('/\s+AS\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $trimmed, $matches) === 1) {
            return $matches[1];
        }

        if (str_contains($trimmed, '.')) {
            $parts = explode('.', $trimmed);

            return end($parts);
        }

        return $trimmed;
    }
}
