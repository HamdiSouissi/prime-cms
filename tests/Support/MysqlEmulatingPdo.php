<?php

declare(strict_types=1);

namespace Prime\Tests\Support;

use PDO;
use PDOStatement;

/**
 * Test-only in-memory SQLite PDO double that translates MySQL/MariaDB
 * `ON DUPLICATE KEY UPDATE` upsert syntax into SQLite's `ON CONFLICT(...) DO UPDATE SET`
 * so production code in `src/` remains 100% free of SQLite-specific branches.
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
        }

        return parent::prepare($query, $options);
    }
}
