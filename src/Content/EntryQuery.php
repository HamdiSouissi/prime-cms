<?php

declare(strict_types=1);

namespace Prime\Content;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use Prime\Auth\User;
use Prime\Database\Connection;

/**
 * Fluent read/query API for content entries (`pc_entries`) with FULLTEXT + LIKE search,
 * taxonomy & meta filters, strict visibility rules, pagination, and constant-query eager loading.
 */
final class EntryQuery
{
    /**
     * Whitelisted ORDER BY columns mapped to SQL expressions on table alias `e`.
     *
     * @var array<string, string>
     */
    private const ALLOWED_ORDER_COLUMNS = [
        'published_at' => 'e.published_at',
        'created_at' => 'e.created_at',
        'updated_at' => 'e.updated_at',
        'title' => 'e.title',
        'menu_order' => 'e.menu_order',
        'id' => 'e.id',
        'status' => 'e.status',
    ];

    /**
     * Whitelisted comparison operators for `whereMeta`.
     *
     * @var list<string>
     */
    private const ALLOWED_META_OPERATORS = ['=', '!=', '<', '<=', '>', '>=', 'LIKE'];

    /** @var list<string> */
    private array $types = [];

    /** @var ?list<string> */
    private ?array $statuses = null;

    private bool $includeTrash = false;

    private bool $includeFuture = false;

    private ?User $privateViewer = null;

    private ?int $adminVisibleAuthorId = null;

    private ?int $authorId = null;

    private ?int $parentId = null;

    private ?string $month = null;

    private ?string $after = null;

    private ?string $before = null;

    private ?string $search = null;

    /** @var list<array{taxonomy: string, values: list<int|string>}> */
    private array $termFilters = [];

    /** @var list<array{key: string, operator: string, value: mixed}> */
    private array $metaFilters = [];

    /** @var list<array{column: string, direction: 'ASC'|'DESC'}> */
    private array $orders = [];

    private ?int $limit = null;

    private ?int $offset = null;

    private bool $eagerMeta = true;

    private bool $eagerTerms = true;

    private bool $eagerAuthors = true;

    public function __construct(
        private readonly Connection $db,
        private readonly EntryRepository $repository,
    ) {
    }

    /**
     * Create a fresh query instance (factory helper for DI / templates / plugins).
     */
    public function newQuery(): self
    {
        return new self($this->db, $this->repository);
    }

    /**
     * Filter by one or more content types.
     *
     * @param string|list<string> $types
     */
    public function type(string|array $types): self
    {
        $clone = clone $this;
        $list = is_array($types) ? $types : [$types];
        $clone->types = array_values(array_filter(array_map('trim', $list), static fn (string $t): bool => $t !== ''));

        return $clone;
    }

    /**
     * Filter by a single explicit status (disables the default published-only filter).
     */
    public function status(string $status): self
    {
        return $this->statuses([$status]);
    }

    /**
     * Filter by multiple explicit statuses.
     *
     * @param list<string> $statuses
     */
    public function statuses(array $statuses): self
    {
        $clone = clone $this;
        foreach ($statuses as $status) {
            if (!in_array($status, Entry::ALL_STATUSES, true)) {
                throw new InvalidArgumentException(sprintf('Statut inconnu [%s].', $status));
            }
        }
        $clone->statuses = $statuses;
        if (in_array(Entry::STATUS_TRASH, $clone->statuses, true)) {
            $clone->includeTrash = true;
        }

        return $clone;
    }

    /**
     * Query across all statuses (admin listings), optionally excluding or including trash.
     */
    public function anyStatus(bool $includeTrash = false): self
    {
        $clone = clone $this;
        $clone->statuses = [];
        $clone->includeTrash = $includeTrash;
        $clone->includeFuture = true;

        return $clone;
    }

    public function includeFuture(bool $include = true): self
    {
        $clone = clone $this;
        $clone->includeFuture = $include;

        return $clone;
    }

    /**
     * Allow private entries visible to `$user` (own entries or `content.edit_any`).
     */
    public function includePrivateFor(?User $user): self
    {
        $clone = clone $this;
        $clone->privateViewer = $user;

        return $clone;
    }

    /**
     * Restrict admin listing visibility for users who lack `edit_any` (contributors / authors):
     * they only see their own entries (any status) plus published entries from other authors.
     */
    public function visibleInAdminFor(User $user, ContentType $type): self
    {
        $clone = clone $this;
        $clone->adminVisibleAuthorId = $user->can($type->capability('edit_any')) ? null : $user->id;

        return $clone;
    }

    /**
     * Filter entries attached to a term by ID, slug, or list of IDs/slugs within `$taxonomy`.
     *
     * @param int|string|list<int|string> $term
     */
    public function whereTerm(string $taxonomy, int|string|array $term): self
    {
        $clone = clone $this;
        $values = is_array($term) ? $term : [$term];
        $clone->termFilters[] = [
            'taxonomy' => trim($taxonomy),
            'values' => $values,
        ];

        return $clone;
    }

    /**
     * Filter entries by a custom metadata key/value condition (`pc_entry_meta`).
     */
    public function whereMeta(string $key, mixed $value, string $operator = '='): self
    {
        $upperOp = strtoupper(trim($operator));
        if (!in_array($upperOp, self::ALLOWED_META_OPERATORS, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported meta operator [%s].', $operator));
        }

        $clone = clone $this;
        $clone->metaFilters[] = [
            'key' => trim($key),
            'operator' => $upperOp,
            'value' => $value,
        ];

        return $clone;
    }

    /**
     * Full-text search on `title` and `content_text` (FULLTEXT IN BOOLEAN MODE on MySQL/MariaDB
     * for queries >= 3 chars, with automatic LIKE fallback for shorter queries or non-MySQL drivers).
     */
    public function search(string $query): self
    {
        $clone = clone $this;
        $trimmed = trim($query);
        $clone->search = $trimmed !== '' ? $trimmed : null;

        return $clone;
    }

    public function author(int $authorId): self
    {
        $clone = clone $this;
        $clone->authorId = $authorId;

        return $clone;
    }

    public function parent(int $parentId): self
    {
        $clone = clone $this;
        $clone->parentId = $parentId;

        return $clone;
    }

    /**
     * Filter by calendar month (`YYYY-MM`).
     */
    public function month(string $yearMonth): self
    {
        $clone = clone $this;
        $clone->month = preg_match('/^\d{4}-\d{2}$/', trim($yearMonth)) === 1 ? trim($yearMonth) : null;

        return $clone;
    }

    /**
     * Keeps entries published (or created, when never published) strictly after the given
     * UTC date or date-time. Unparseable input clears the filter.
     */
    public function after(string $dateTime): self
    {
        $clone = clone $this;
        $clone->after = self::normalizeBound($dateTime);

        return $clone;
    }

    /**
     * Keeps entries published (or created, when never published) strictly before the given
     * UTC date or date-time. Unparseable input clears the filter.
     */
    public function before(string $dateTime): self
    {
        $clone = clone $this;
        $clone->before = self::normalizeBound($dateTime);

        return $clone;
    }

    private static function normalizeBound(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?(Z|[+-]\d{2}:?\d{2})?$/', $value) !== 1) {
            return null;
        }

        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function orderBy(string $column, string $direction = 'DESC'): self
    {
        if (!isset(self::ALLOWED_ORDER_COLUMNS[$column])) {
            throw new InvalidArgumentException(sprintf('Invalid order column [%s].', $column));
        }

        $dir = strtoupper(trim($direction)) === 'ASC' ? 'ASC' : 'DESC';

        $clone = clone $this;
        $clone->orders[] = ['column' => $column, 'direction' => $dir];

        return $clone;
    }

    public function limit(int $limit): self
    {
        $clone = clone $this;
        $clone->limit = max(0, $limit);

        return $clone;
    }

    public function offset(int $offset): self
    {
        $clone = clone $this;
        $clone->offset = max(0, $offset);

        return $clone;
    }

    public function withRelations(bool $meta = true, bool $terms = true, bool $authors = true): self
    {
        $clone = clone $this;
        $clone->eagerMeta = $meta;
        $clone->eagerTerms = $terms;
        $clone->eagerAuthors = $authors;

        return $clone;
    }

    /**
     * Execute the query and return hydrated `Entry` objects in constant query count.
     *
     * @return list<Entry>
     */
    public function get(): array
    {
        [$whereSql, $bindings] = $this->compileWhere();
        $entriesTable = $this->db->prefix() . 'entries';

        $sql = sprintf('SELECT e.* FROM `%s` e%s', $entriesTable, $whereSql);
        $sql .= $this->compileOrderBy();

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }
        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        $rows = $this->db->select($sql, $bindings);
        if ($rows === []) {
            return [];
        }

        $entries = array_map(static fn (array $row): Entry => Entry::fromRow($row), $rows);

        if (!$this->eagerMeta && !$this->eagerTerms && !$this->eagerAuthors) {
            return $entries;
        }

        return $this->repository->hydrateEntries(
            $entries,
            loadMeta: $this->eagerMeta,
            loadTerms: $this->eagerTerms,
            loadAuthors: $this->eagerAuthors,
        );
    }

    public function first(): ?Entry
    {
        $results = $this->limit(1)->get();

        return $results[0] ?? null;
    }

    public function count(): int
    {
        [$whereSql, $bindings] = $this->compileWhere();
        $entriesTable = $this->db->prefix() . 'entries';

        $rows = $this->db->select(
            sprintf('SELECT COUNT(*) AS aggregate FROM `%s` e%s', $entriesTable, $whereSql),
            $bindings,
        );

        return isset($rows[0]['aggregate']) ? (int) $rows[0]['aggregate'] : 0;
    }

    /**
     * Paginate results with total count and constant-query relation hydration.
     *
     * @return array{items: list<Entry>, total: int, page: int, per_page: int, last_page: int}
     */
    public function paginate(int $page = 1, int $perPage = 20): array
    {
        $safePerPage = max(1, min(200, $perPage));
        $total = $this->count();
        $lastPage = max(1, (int) ceil($total / $safePerPage));
        $safePage = max(1, min($page, $lastPage));
        $offset = ($safePage - 1) * $safePerPage;

        $items = $total > 0
            ? $this->limit($safePerPage)->offset($offset)->get()
            : [];

        return [
            'items' => $items,
            'total' => $total,
            'page' => $safePage,
            'per_page' => $safePerPage,
            'last_page' => $lastPage,
        ];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function compileWhere(): array
    {
        $clauses = [];
        $bindings = [];
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        // 1. Types
        if ($this->types !== []) {
            $placeholders = implode(', ', array_fill(0, count($this->types), '?'));
            $clauses[] = sprintf('e.type IN (%s)', $placeholders);
            $bindings = [...$bindings, ...$this->types];
        }

        // 2. Visibility / statuses
        if ($this->statuses === null) {
            // Default public query: published entries with published_at <= now()
            // plus optionally private entries accessible to `privateViewer`.
            $pubClause = $this->includeFuture
                ? 'e.status = ?'
                : '(e.status = ? AND (e.published_at IS NULL OR e.published_at <= ?))';
            $pubBindings = $this->includeFuture ? [Entry::STATUS_PUBLISHED] : [Entry::STATUS_PUBLISHED, $now];

            if ($this->privateViewer !== null) {
                if ($this->privateViewer->can('content.edit_any')) {
                    $clauses[] = sprintf('(%s OR e.status = ?)', $pubClause);
                    $bindings = [...$bindings, ...$pubBindings, Entry::STATUS_PRIVATE];
                } else {
                    $clauses[] = sprintf('(%s OR (e.status = ? AND e.author_id = ?))', $pubClause);
                    $bindings = [...$bindings, ...$pubBindings, Entry::STATUS_PRIVATE, $this->privateViewer->id];
                }
            } else {
                $clauses[] = $pubClause;
                $bindings = [...$bindings, ...$pubBindings];
            }
        } elseif ($this->statuses === []) {
            // `anyStatus(...)`
            if (!$this->includeTrash) {
                $clauses[] = 'e.status != ?';
                $bindings[] = Entry::STATUS_TRASH;
            }
        } else {
            $placeholders = implode(', ', array_fill(0, count($this->statuses), '?'));
            $clauses[] = sprintf('e.status IN (%s)', $placeholders);
            $bindings = [...$bindings, ...$this->statuses];
        }

        // 3. Admin per-role visibility (contributors / authors see own entries + published entries)
        if ($this->adminVisibleAuthorId !== null) {
            $clauses[] = '(e.author_id = ? OR e.status = ?)';
            $bindings[] = $this->adminVisibleAuthorId;
            $bindings[] = Entry::STATUS_PUBLISHED;
        }

        // 4. Author, parent, month
        if ($this->authorId !== null) {
            $clauses[] = 'e.author_id = ?';
            $bindings[] = $this->authorId;
        }

        if ($this->parentId !== null) {
            $clauses[] = 'e.parent_id = ?';
            $bindings[] = $this->parentId;
        }

        if ($this->after !== null) {
            $clauses[] = 'COALESCE(e.published_at, e.created_at) > ?';
            $bindings[] = $this->after;
        }

        if ($this->before !== null) {
            $clauses[] = 'COALESCE(e.published_at, e.created_at) < ?';
            $bindings[] = $this->before;
        }

        if ($this->month !== null) {
            $start = $this->month . '-01 00:00:00';
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $start, new DateTimeZone('UTC'));
            if ($dt !== false) {
                $end = $dt->modify('+1 month')->format('Y-m-d H:i:s');
                $clauses[] = 'COALESCE(e.published_at, e.created_at) >= ? AND COALESCE(e.published_at, e.created_at) < ?';
                $bindings[] = $start;
                $bindings[] = $end;
            }
        }

        // 4. Taxonomy term filters (EXISTS subquery preserves clean pagination & zero duplicate rows)
        $etTable = $this->db->prefix() . 'entry_terms';
        $tTable = $this->db->prefix() . 'terms';
        foreach ($this->termFilters as $filter) {
            if ($filter['values'] === []) {
                continue;
            }
            $allInt = true;
            foreach ($filter['values'] as $val) {
                if (!is_int($val)) {
                    $allInt = false;
                    break;
                }
            }

            $col = $allInt ? 't.id' : 't.slug';
            $ph = implode(', ', array_fill(0, count($filter['values']), '?'));
            $clauses[] = sprintf(
                'EXISTS (SELECT 1 FROM `%s` et INNER JOIN `%s` t ON t.id = et.term_id WHERE et.entry_id = e.id AND t.taxonomy = ? AND %s IN (%s))',
                $etTable,
                $tTable,
                $col,
                $ph,
            );
            $bindings[] = $filter['taxonomy'];
            foreach ($filter['values'] as $val) {
                $bindings[] = $val;
            }
        }

        // 5. Custom metadata filters (EXISTS subquery on JSON-encoded `pc_entry_meta`)
        $metaTable = $this->db->prefix() . 'entry_meta';
        foreach ($this->metaFilters as $mf) {
            $op = $mf['operator'];
            if ($op === 'LIKE') {
                $clauses[] = sprintf(
                    'EXISTS (SELECT 1 FROM `%s` em WHERE em.entry_id = e.id AND em.meta_key = ? AND em.meta_value LIKE ?)',
                    $metaTable,
                );
                $bindings[] = $mf['key'];
                $bindings[] = (string) $mf['value'];
            } elseif ((is_int($mf['value']) || is_float($mf['value'])) && in_array($op, ['<', '<=', '>', '>='], true)) {
                $clauses[] = sprintf(
                    'EXISTS (SELECT 1 FROM `%s` em WHERE em.entry_id = e.id AND em.meta_key = ? AND CAST(em.meta_value AS DECIMAL(20, 6)) %s ?)',
                    $metaTable,
                    $op,
                );
                $bindings[] = $mf['key'];
                $bindings[] = $mf['value'];
            } else {
                $encoded = json_encode($mf['value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $clauses[] = sprintf(
                    'EXISTS (SELECT 1 FROM `%s` em WHERE em.entry_id = e.id AND em.meta_key = ? AND em.meta_value %s ?)',
                    $metaTable,
                    $op,
                );
                $bindings[] = $mf['key'];
                $bindings[] = is_string($encoded) ? $encoded : (string) $mf['value'];
            }
        }

        // 6. Search (MySQL/MariaDB FULLTEXT IN BOOLEAN MODE for >= 3 chars, LIKE fallback otherwise)
        if ($this->search !== null) {
            $booleanQuery = $this->buildFulltextBooleanQuery($this->search);
            if ($this->isMysqlDriver() && mb_strlen($this->search) >= 3 && $booleanQuery !== '') {
                $clauses[] = 'MATCH(e.title, e.content_text) AGAINST(? IN BOOLEAN MODE)';
                $bindings[] = $booleanQuery;
            } else {
                $like = '%' . $this->search . '%';
                $clauses[] = '(e.title LIKE ? OR e.content_text LIKE ?)';
                $bindings[] = $like;
                $bindings[] = $like;
            }
        }

        if ($clauses === []) {
            return ['', []];
        }

        return [' WHERE ' . implode(' AND ', $clauses), $bindings];
    }

    private function compileOrderBy(): string
    {
        if ($this->orders === []) {
            return ' ORDER BY COALESCE(e.published_at, e.created_at) DESC, e.id DESC';
        }

        $parts = [];
        foreach ($this->orders as $order) {
            $expr = self::ALLOWED_ORDER_COLUMNS[$order['column']] ?? 'e.id';
            $parts[] = $expr . ' ' . $order['direction'];
        }
        $parts[] = 'e.id DESC';

        return ' ORDER BY ' . implode(', ', $parts);
    }

    private function isMysqlDriver(): bool
    {
        try {
            $driver = strtolower((string) $this->db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME));

            return $driver === 'mysql';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Build a safe MySQL FULLTEXT boolean query (`+word1* +word2*`) stripped of syntax operators.
     */
    private function buildFulltextBooleanQuery(string $input): string
    {
        $sanitized = (string) preg_replace('/[+\-><\(\)~*"@]+/', ' ', $input);
        $tokens = preg_split('/\s+/u', trim($sanitized)) ?: [];
        $terms = [];

        foreach ($tokens as $token) {
            if (mb_strlen($token) >= 2) {
                $terms[] = '+' . $token . '*';
            }
        }

        return implode(' ', array_slice($terms, 0, 12));
    }
}
