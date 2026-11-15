<?php

declare(strict_types=1);

namespace Prime\Database;

use Prime\Database\Exception\InvalidIdentifierException;

/**
 * Strict SQL identifier validator and backtick quoter.
 *
 * Prevents SQL injection through table names, column names, aliases, and schema options.
 */
final class Identifier
{
    private const SIMPLE_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';
    private const QUALIFIED_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/';
    private const CHARSET_PATTERN = '/^[A-Za-z0-9_]+$/';

    /**
     * Validate a single unquoted SQL identifier (table, column, alias, or index name).
     */
    public static function validateSimple(string $name): string
    {
        $trimmed = trim($name);

        if ($trimmed === '' || preg_match(self::SIMPLE_PATTERN, $trimmed) !== 1) {
            throw new InvalidIdentifierException(sprintf('Invalid SQL identifier [%s].', $name));
        }

        return $trimmed;
    }

    /**
     * Validate and quote a single SQL identifier with backticks.
     */
    public static function quoteSimple(string $name): string
    {
        return '`' . self::validateSimple($name) . '`';
    }

    /**
     * Validate a table prefix (empty string allowed for prefix-less tables).
     */
    public static function validatePrefix(string $prefix): string
    {
        if ($prefix === '') {
            return '';
        }

        if (strlen($prefix) > 32 || preg_match('/^[A-Za-z0-9_]+$/', $prefix) !== 1) {
            throw new InvalidIdentifierException(sprintf('Invalid database table prefix [%s].', $prefix));
        }

        return $prefix;
    }

    /**
     * Apply the configured table prefix to a validated table name.
     */
    public static function prefixTable(string $table, string $prefix): string
    {
        $cleanTable = self::validateSimple($table);
        $cleanPrefix = self::validatePrefix($prefix);

        if ($cleanPrefix === '' || str_starts_with($cleanTable, $cleanPrefix)) {
            return $cleanTable;
        }

        return $cleanPrefix . $cleanTable;
    }

    /**
     * Parse and quote a table specification with optional `AS alias`.
     *
     * @return array{raw: string, prefixed: string, alias: ?string, sql: string}
     */
    public static function parseTable(string $tableSpec, string $prefix = ''): array
    {
        $trimmed = trim($tableSpec);
        if ($trimmed === '') {
            throw new InvalidIdentifierException('Table name cannot be empty.');
        }

        $alias = null;
        $tablePart = $trimmed;

        if (preg_match('/^(.+?)\s+AS\s+(.+)$/i', $trimmed, $matches) === 1) {
            $tablePart = trim($matches[1]);
            $alias = self::validateSimple($matches[2]);
        }

        $raw = self::validateSimple($tablePart);
        $prefixed = self::prefixTable($raw, $prefix);

        $sql = '`' . $prefixed . '`';
        if ($alias !== null) {
            $sql .= ' AS `' . $alias . '`';
        }

        return [
            'raw' => $raw,
            'prefixed' => $prefixed,
            'alias' => $alias,
            'sql' => $sql,
        ];
    }

    /**
     * Validate and quote a column reference (optional `table.` prefix and `AS alias`).
     *
     * @param array<string, string> $tableMap Map of unprefixed table names to their SQL names/aliases
     */
    public static function quoteColumn(string $columnSpec, array $tableMap = [], bool $allowStar = false): string
    {
        $trimmed = trim($columnSpec);
        if ($trimmed === '') {
            throw new InvalidIdentifierException('Column identifier cannot be empty.');
        }

        $alias = null;
        $columnPart = $trimmed;

        if (preg_match('/^(.+?)\s+AS\s+(.+)$/i', $trimmed, $matches) === 1) {
            $columnPart = trim($matches[1]);
            $alias = self::validateSimple($matches[2]);
        }

        if ($allowStar && $columnPart === '*') {
            if ($alias !== null) {
                throw new InvalidIdentifierException('Wildcard [*] cannot have an alias.');
            }

            return '*';
        }

        if ($allowStar && preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\.\*$/', $columnPart, $starMatches) === 1) {
            if ($alias !== null) {
                throw new InvalidIdentifierException(sprintf('Wildcard [%s] cannot have an alias.', $columnPart));
            }

            $table = $tableMap[$starMatches[1]] ?? $starMatches[1];

            return '`' . self::validateSimple($table) . '`.*';
        }

        if (preg_match(self::QUALIFIED_PATTERN, $columnPart) !== 1) {
            throw new InvalidIdentifierException(sprintf('Invalid SQL column identifier [%s].', $columnSpec));
        }

        if (str_contains($columnPart, '.')) {
            [$table, $column] = explode('.', $columnPart, 2);
            $resolvedTable = $tableMap[$table] ?? $table;
            $quoted = '`' . self::validateSimple($resolvedTable) . '`.`' . self::validateSimple($column) . '`';
        } else {
            $quoted = '`' . $columnPart . '`';
        }

        if ($alias !== null) {
            $quoted .= ' AS `' . $alias . '`';
        }

        return $quoted;
    }

    /**
     * Validate a MySQL charset or collation name.
     */
    public static function validateCharsetOrCollation(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || preg_match(self::CHARSET_PATTERN, $trimmed) !== 1) {
            throw new InvalidIdentifierException(sprintf('Invalid charset or collation [%s].', $value));
        }

        return $trimmed;
    }

    /**
     * Validate a MySQL database name (for CREATE DATABASE during installation).
     */
    public static function validateDatabaseName(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '' || strlen($trimmed) > 64 || preg_match('/^[A-Za-z0-9_][A-Za-z0-9_-]*$/', $trimmed) !== 1) {
            throw new InvalidIdentifierException(sprintf('Invalid database name [%s].', $name));
        }

        return $trimmed;
    }
}
