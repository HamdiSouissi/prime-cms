<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Database;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Prime\Database\Connection;
use Prime\Database\Exception\InvalidIdentifierException;
use Prime\Database\Exception\UnsafeQueryException;
use Prime\Database\Query\Expression;

final class QueryBuilderTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = new Connection(['prefix' => 'pc_']);
    }

    public function testCompilesFullSelectQueryWithJoinsNestedWheresGroupHavingOrderLimitOffset(): void
    {
        $qb = $this->connection->table('users AS u')
            ->distinct()
            ->select('u.id', 'u.email AS user_email', Expression::raw('COUNT(`p`.`id`) AS `post_count`'))
            ->leftJoin('posts AS p', 'u.id', '=', 'p.author_id')
            ->where('u.status', '=', 'active')
            ->where(static function ($nested): void {
                $nested->where('u.locale', 'fr')
                    ->orWhere('u.locale', 'en');
            })
            ->whereIn('u.id', [1, 2, 3])
            ->whereNotIn('u.status', ['banned', 'deleted'])
            ->whereNull('u.deleted_at')
            ->whereNotNull('u.email')
            ->whereBetween('u.created_at', '2026-01-01', '2026-12-31')
            ->groupBy('u.id', 'u.email')
            ->having(Expression::raw('COUNT(`p`.`id`)'), '>=', 2)
            ->orderBy('u.id', 'DESC')
            ->limit(20)
            ->offset(40);

        $expectedSql = 'SELECT DISTINCT `u`.`id`, `u`.`email` AS `user_email`, COUNT(`p`.`id`) AS `post_count`'
            . ' FROM `pc_users` AS `u`'
            . ' LEFT JOIN `pc_posts` AS `p` ON `u`.`id` = `p`.`author_id`'
            . ' WHERE `u`.`status` = ? AND (`u`.`locale` = ? OR `u`.`locale` = ?)'
            . ' AND `u`.`id` IN (?, ?, ?) AND `u`.`status` NOT IN (?, ?)'
            . ' AND `u`.`deleted_at` IS NULL AND `u`.`email` IS NOT NULL'
            . ' AND `u`.`created_at` BETWEEN ? AND ?'
            . ' GROUP BY `u`.`id`, `u`.`email`'
            . ' HAVING COUNT(`p`.`id`) >= ?'
            . ' ORDER BY `u`.`id` DESC LIMIT 20 OFFSET 40';

        $this->assertSame($expectedSql, $qb->toSql());
        $this->assertSame(
            ['active', 'fr', 'en', 1, 2, 3, 'banned', 'deleted', '2026-01-01', '2026-12-31', 2],
            $qb->bindings(),
        );
    }

    public function testPrefixesUnaliasedJoinedTablesAndSupportsEmptyWhereIn(): void
    {
        $qb = $this->connection->table('users')
            ->select('users.*', 'profiles.bio')
            ->join('profiles', 'users.id', '=', 'profiles.user_id')
            ->whereIn('users.id', [])
            ->whereNotIn('users.status', []);

        $this->assertSame(
            'SELECT `pc_users`.*, `pc_profiles`.`bio` FROM `pc_users` INNER JOIN `pc_profiles` ON `pc_users`.`id` = `pc_profiles`.`user_id` WHERE 1 = 0 AND 1 = 1',
            $qb->toSql(),
        );
        $this->assertSame([], $qb->bindings());
    }

    public function testSupportsAllWhitelistedWhereOperators(): void
    {
        $operators = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE'];

        foreach ($operators as $op) {
            $qb = $this->connection->table('users')->where('name', $op, 'test');
            $this->assertSame('SELECT * FROM `pc_users` WHERE `name` ' . $op . ' ?', $qb->toSql());
            $this->assertSame(['test'], $qb->bindings());
        }
    }

    public function testRejectsSqlInjectionInTableName(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $this->connection->table('users; DROP TABLE pc_users --');
    }

    public function testRejectsSqlInjectionInColumnName(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $this->connection->table('users')->select('id`, (SELECT password FROM pc_users) AS `x');
    }

    public function testRejectsSqlInjectionInWhereColumn(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $this->connection->table('users')->where('1=1 OR id', '=', 1);
    }

    public function testRejectsSqlInjectionInOperator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->connection->table('users')->where('id', '= 1 OR 1=', 1);
    }

    public function testRejectsSqlInjectionInOrderByDirection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->connection->table('users')->orderBy('id', 'ASC; DROP TABLE pc_users');
    }

    public function testRejectsSqlInjectionInAlias(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $this->connection->table('users AS u; --');
    }

    public function testRefusesUpdateWithoutWhereUnlessAllIsCalled(): void
    {
        $this->expectException(UnsafeQueryException::class);
        $this->connection->table('users')->update(['status' => 'inactive']);
    }

    public function testRefusesDeleteWithoutWhereUnlessAllIsCalled(): void
    {
        $this->expectException(UnsafeQueryException::class);
        $this->connection->table('users')->delete();
    }

    public function testRefusesIncrementWithoutWhereUnlessAllIsCalled(): void
    {
        $this->expectException(UnsafeQueryException::class);
        $this->connection->table('users')->increment('login_count');
    }

    public function testCrudAggregatesPaginationAndExplicitAllOnInMemorySqlite(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $conn = new Connection(['prefix' => 'pc_'], pdo: $pdo);
        $conn->statement('CREATE TABLE `pc_products` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `name` TEXT NOT NULL, `price` INTEGER NOT NULL, `stock` INTEGER NOT NULL)');

        $firstId = $conn->table('products')->insertGetId(['name' => 'A', 'price' => 10, 'stock' => 5]);
        $this->assertSame(1, $firstId);

        $conn->table('products')->insert([
            ['name' => 'B', 'price' => 20, 'stock' => 8],
            ['name' => 'C', 'price' => 30, 'stock' => 2],
            ['name' => 'D', 'price' => 40, 'stock' => 10],
        ]);

        $this->assertSame(4, $conn->table('products')->count());
        $this->assertTrue($conn->table('products')->where('name', 'B')->exists());
        $this->assertFalse($conn->table('products')->where('name', 'Z')->exists());
        $this->assertSame(10, $conn->table('products')->min('price'));
        $this->assertSame(40, $conn->table('products')->max('price'));
        $this->assertSame(100, $conn->table('products')->sum('price'));
        $this->assertSame(25.0, $conn->table('products')->avg('price'));
        $this->assertSame('B', $conn->table('products')->find(2)['name'] ?? null);
        $this->assertSame(30, $conn->table('products')->where('name', 'C')->value('price'));
        $this->assertSame([1 => 'A', 2 => 'B'], $conn->table('products')->where('id', '<=', 2)->orderBy('id', 'ASC')->pluck('name', 'id'));

        $page = $conn->table('products')->orderBy('id', 'ASC')->paginate(2, 2);
        $this->assertSame(4, $page['total']);
        $this->assertSame(2, $page['page']);
        $this->assertSame(2, $page['per_page']);
        $this->assertSame(2, $page['total_pages']);
        $this->assertCount(2, $page['items']);
        $this->assertSame('C', $page['items'][0]['name']);

        $conn->table('products')->where('id', 1)->increment('stock', 3);
        $this->assertSame(8, $conn->table('products')->where('id', 1)->value('stock'));
        $conn->table('products')->where('id', 1)->decrement('stock', 2);
        $this->assertSame(6, $conn->table('products')->where('id', 1)->value('stock'));

        $updated = $conn->table('products')->all()->update(['stock' => 1]);
        $this->assertSame(4, $updated);

        $deleted = $conn->table('products')->all()->delete();
        $this->assertSame(4, $deleted);
        $this->assertSame(0, $conn->table('products')->count());
    }
}
