<?php

declare(strict_types=1);

namespace Prime\Tests\Support;

use PDO;
use PDOStatement;

/**
 * Test-only in-memory SQLite PDO double that translates MySQL/MariaDB
 * `ON DUPLICATE KEY UPDATE`, `information_schema` inspection queries, and Blueprint
 * `CREATE TABLE` DDL into SQLite equivalents so production code in `src/` remains
 * 100% free of SQLite-specific branches.
 */
final class MysqlEmulatingPdo extends PDO
{
    public function __construct(private readonly string $conflictColumn = 'name')
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /**
     * @param array<int|string, mixed> $options
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (stripos($query, 'ON DUPLICATE KEY UPDATE') !== false) {
            $query = (string) preg_replace(
                '/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i',
                sprintf('ON CONFLICT(`%s`) DO UPDATE SET', $this->conflictColumn),
                $query,
                1,
            );
        } elseif (stripos($query, 'FROM information_schema.TABLES') !== false && stripos($query, 'COUNT(*)') !== false) {
            $query = "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?";
        } elseif (stripos($query, 'FROM information_schema.COLUMNS') !== false && stripos($query, 'COUNT(*)') !== false) {
            $query = 'SELECT COUNT(*) FROM pragma_table_info(?) WHERE name = ?';
        } elseif (stripos($query, 'CREATE TABLE') !== false) {
            // Translate MySQL Blueprint DDL into SQLite DDL for in-memory unit tests
            $query = (string) preg_replace('/\s+ENGINE\s*=\s*InnoDB.*$/is', '', $query);
            $query = (string) preg_replace('/`id`\s+BIGINT\s+UNSIGNED\s+NOT\s+NULL\s+AUTO_INCREMENT(?:\s+PRIMARY\s+KEY)?/i', '`id` INTEGER PRIMARY KEY AUTOINCREMENT', $query);
            $query = (string) preg_replace('/,\s*PRIMARY\s+KEY\s*\(`id`\)/i', '', $query);
            $query = (string) preg_replace('/\bUNSIGNED\b/i', '', $query);
            $query = (string) preg_replace('/\s+ON\s+UPDATE\s+CURRENT_TIMESTAMP/i', '', $query);
            $query = (string) preg_replace('/,\s*FULLTEXT\s+KEY\s+`[^`]+`\s*\([^)]+\)/i', '', $query);
            $query = (string) preg_replace('/,\s*UNIQUE\s+KEY\s+`[^`]+`\s*\(([^)]+)\)/i', ', UNIQUE ($1)', $query);
            $query = (string) preg_replace('/,\s*INDEX\s+`[^`]+`\s*\([^)]+\)/i', '', $query);
        }

        return parent::prepare($query, $options);
    }
}
