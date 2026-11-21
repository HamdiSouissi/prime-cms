<?php

declare(strict_types=1);

namespace Prime\Content;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Prime\Content\Exception\ContentException;
use Prime\Database\Connection;
use Prime\Support\Str;

/**
 * Database repository for taxonomy terms (`pc_terms` & `pc_entry_terms`).
 */
final class TermRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly TaxonomyRegistry $taxonomies,
    ) {
    }

    public function find(int $id): ?Term
    {
        $row = $this->db->table('terms')->find($id);

        return $row !== null ? Term::fromRow($row) : null;
    }

    /**
     * @param list<int> $ids
     * @return array<int, Term>
     */
    public function findManyById(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $terms = [];
        foreach ($this->db->table('terms')->whereIn('id', $ids)->get() as $row) {
            $term = Term::fromRow($row);
            $terms[$term->id] = $term;
        }

        return $terms;
    }

    public function findBySlug(string $taxonomy, string $slug): ?Term
    {
        $row = $this->db->table('terms')
            ->where('taxonomy', '=', $taxonomy)
            ->where('slug', '=', $slug)
            ->first();

        return $row !== null ? Term::fromRow($row) : null;
    }

    /**
     * @return list<Term>
     */
    public function allForTaxonomy(string $taxonomy, string $search = ''): array
    {
        $qb = $this->db->table('terms')
            ->where('taxonomy', '=', $taxonomy)
            ->orderBy('menu_order', 'ASC')
            ->orderBy('name', 'ASC');

        if (trim($search) !== '') {
            $needle = '%' . trim($search) . '%';
            $qb->where(static function ($sub) use ($needle): void {
                $sub->where('name', 'LIKE', $needle)
                    ->orWhere('slug', 'LIKE', $needle);
            });
        }

        return array_map(static fn (array $row): Term => Term::fromRow($row), $qb->get());
    }

    /**
     * Return all terms in depth-first hierarchical order with indentation depth.
     *
     * @return list<array{term: Term, depth: int}>
     */
    public function flatTreeForTaxonomy(string $taxonomy): array
    {
        $terms = $this->allForTaxonomy($taxonomy);
        $byId = [];
        $childrenMap = [];

        foreach ($terms as $term) {
            $byId[$term->id] = $term;
            $childrenMap[$term->parentId][] = $term;
        }

        $result = [];
        $visited = [];

        $walk = static function (int $parentId, int $depth) use (&$walk, &$result, &$visited, $childrenMap): void {
            foreach ($childrenMap[$parentId] ?? [] as $child) {
                if (isset($visited[$child->id])) {
                    continue;
                }
                $visited[$child->id] = true;
                $result[] = ['term' => $child, 'depth' => $depth];
                $walk($child->id, $depth + 1);
            }
        };

        $walk(0, 0);

        // Append any orphans whose parent_id points to a missing term
        foreach ($terms as $term) {
            if (!isset($visited[$term->id])) {
                $result[] = ['term' => $term, 'depth' => 0];
            }
        }

        return $result;
    }

    /**
     * Create a new term with automatic unique slug generation inside its taxonomy.
     */
    public function create(
        string $taxonomy,
        string $name,
        ?string $slug = null,
        ?string $description = null,
        int $parentId = 0,
        int $menuOrder = 0,
    ): Term {
        $taxDef = $this->taxonomies->get($taxonomy);
        if ($taxDef === null) {
            throw new InvalidArgumentException(sprintf('Taxonomie inconnue : "%s".', $taxonomy));
        }

        $cleanName = trim($name);
        if ($cleanName === '' || mb_strlen($cleanName) > 191) {
            throw new ContentException('Le nom du terme est obligatoire (191 caractères maximum).');
        }

        $resolvedParent = 0;
        if ($taxDef->hierarchical && $parentId > 0) {
            $parent = $this->find($parentId);
            if ($parent === null || $parent->taxonomy !== $taxonomy) {
                throw new ContentException('Le terme parent sélectionné est introuvable dans cette taxonomie.');
            }
            $resolvedParent = $parent->id;
        }

        $baseSlug = Str::slug($slug !== null && trim($slug) !== '' ? $slug : $cleanName);
        if ($baseSlug === '') {
            $baseSlug = 'terme';
        }
        $uniqueSlug = $this->uniqueSlug($taxonomy, $baseSlug);

        $now = $this->nowUtc();
        $id = $this->db->table('terms')->insertGetId([
            'taxonomy' => $taxonomy,
            'name' => $cleanName,
            'slug' => $uniqueSlug,
            'description' => $description !== null && trim($description) !== '' ? trim($description) : null,
            'parent_id' => $resolvedParent,
            'menu_order' => $menuOrder,
            'count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new Term(
            id: $id,
            taxonomy: $taxonomy,
            name: $cleanName,
            slug: $uniqueSlug,
            description: $description !== null && trim($description) !== '' ? trim($description) : null,
            parentId: $resolvedParent,
            menuOrder: $menuOrder,
            count: 0,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * Update an existing term's attributes (preventing cycles in hierarchical trees).
     */
    public function update(
        int $id,
        string $name,
        ?string $slug = null,
        ?string $description = null,
        int $parentId = 0,
        int $menuOrder = 0,
    ): Term {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new ContentException('Terme introuvable.');
        }

        $taxDef = $this->taxonomies->get($existing->taxonomy);
        $cleanName = trim($name);
        if ($cleanName === '' || mb_strlen($cleanName) > 191) {
            throw new ContentException('Le nom du terme est obligatoire (191 caractères maximum).');
        }

        // Preserve default term's slug so protected default category remains identifiable
        $isProtectedDefault = $taxDef !== null
            && $taxDef->defaultTermSlug !== null
            && $existing->slug === $taxDef->defaultTermSlug;

        if ($isProtectedDefault) {
            $uniqueSlug = $existing->slug;
        } else {
            $baseSlug = Str::slug($slug !== null && trim($slug) !== '' ? $slug : $cleanName);
            if ($baseSlug === '') {
                $baseSlug = 'terme';
            }
            $uniqueSlug = $this->uniqueSlug($existing->taxonomy, $baseSlug, $existing->id);
        }

        $resolvedParent = 0;
        if ($taxDef !== null && $taxDef->hierarchical && $parentId > 0) {
            if ($parentId === $existing->id) {
                throw new ContentException('Un terme ne peut pas être son propre parent.');
            }
            $parent = $this->find($parentId);
            if ($parent === null || $parent->taxonomy !== $existing->taxonomy) {
                throw new ContentException('Le terme parent sélectionné est invalide.');
            }
            if ($this->isDescendant($existing->taxonomy, $existing->id, $parentId)) {
                throw new ContentException('Impossible de déplacer un terme sous l\'un de ses propres sous-termes.');
            }
            $resolvedParent = $parentId;
        }

        $now = $this->nowUtc();
        $cleanDesc = $description !== null && trim($description) !== '' ? trim($description) : null;

        $this->db->table('terms')
            ->where('id', '=', $existing->id)
            ->update([
                'name' => $cleanName,
                'slug' => $uniqueSlug,
                'description' => $cleanDesc,
                'parent_id' => $resolvedParent,
                'menu_order' => $menuOrder,
                'updated_at' => $now,
            ]);

        return new Term(
            id: $existing->id,
            taxonomy: $existing->taxonomy,
            name: $cleanName,
            slug: $uniqueSlug,
            description: $cleanDesc,
            parentId: $resolvedParent,
            menuOrder: $menuOrder,
            count: $existing->count,
            createdAt: $existing->createdAt,
            updatedAt: $now,
        );
    }

    /**
     * Delete a term: refuses protected default terms, reparents children to the term's parent,
     * detaches associated entries, and removes the row.
     */
    public function delete(int $id): void
    {
        $term = $this->find($id);
        if ($term === null) {
            return;
        }

        $taxDef = $this->taxonomies->get($term->taxonomy);
        if ($taxDef !== null && $taxDef->defaultTermSlug !== null && $term->slug === $taxDef->defaultTermSlug) {
            throw new ContentException(sprintf(
                'Le terme par défaut « %s » ne peut pas être supprimé.',
                $term->name,
            ));
        }

        $this->db->transaction(function () use ($term): void {
            // Re-parent direct children to the deleted term's parent
            $this->db->table('terms')
                ->where('taxonomy', '=', $term->taxonomy)
                ->where('parent_id', '=', $term->id)
                ->update(['parent_id' => $term->parentId]);

            // Detach from entries (also covered by FK cascade on MySQL, explicit for safety)
            $this->db->table('entry_terms')
                ->where('term_id', '=', $term->id)
                ->delete();

            $this->db->table('terms')
                ->where('id', '=', $term->id)
                ->delete();
        });
    }

    /**
     * Remove all terms belonging to a taxonomy (used by plugin `uninstall()`).
     */
    public function deleteByTaxonomy(string $taxonomy): int
    {
        $ids = array_map(
            static fn (array $r): int => (int) $r['id'],
            $this->db->table('terms')->select(['id'])->where('taxonomy', '=', $taxonomy)->get(),
        );

        if ($ids === []) {
            return 0;
        }

        $this->db->transaction(function () use ($ids, $taxonomy): void {
            $this->db->table('entry_terms')->whereIn('term_id', $ids)->delete();
            $this->db->table('terms')->where('taxonomy', '=', $taxonomy)->delete();
        });

        return count($ids);
    }

    /**
     * Recalculate `count` for specific term IDs (published entries only).
     *
     * @param list<int> $termIds
     */
    public function recountTerms(array $termIds): void
    {
        $uniqueIds = array_values(array_unique(array_filter($termIds, static fn (int $id): bool => $id > 0)));
        if ($uniqueIds === []) {
            return;
        }

        $now = $this->nowUtc();
        $entryTerms = $this->db->prefix() . 'entry_terms';
        $entries = $this->db->prefix() . 'entries';
        $placeholders = implode(', ', array_fill(0, count($uniqueIds), '?'));

        $sql = sprintf(
            'SELECT et.term_id, COUNT(*) AS cnt '
            . 'FROM `%s` et '
            . 'INNER JOIN `%s` e ON e.id = et.entry_id '
            . 'WHERE et.term_id IN (%s) '
            . 'AND e.status = ? '
            . 'AND (e.published_at IS NULL OR e.published_at <= ?) '
            . 'GROUP BY et.term_id',
            $entryTerms,
            $entries,
            $placeholders,
        );

        $rows = $this->db->select($sql, [...$uniqueIds, 'published', $now]);
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['term_id']] = (int) $row['cnt'];
        }

        foreach ($uniqueIds as $termId) {
            $this->db->table('terms')
                ->where('id', '=', $termId)
                ->update(['count' => $counts[$termId] ?? 0]);
        }
    }

    /**
     * Recalculate `count` for every term in a taxonomy.
     */
    public function recountTaxonomy(string $taxonomy): void
    {
        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->db->table('terms')->select(['id'])->where('taxonomy', '=', $taxonomy)->get(),
        );

        $this->recountTerms($ids);
    }

    /**
     * Return or create the protected default category "Non classé".
     */
    public function ensureDefaultCategory(): Term
    {
        $existing = $this->findBySlug('category', 'non-classe');
        if ($existing !== null) {
            return $existing;
        }

        return $this->create(
            taxonomy: 'category',
            name: 'Non classé',
            slug: 'non-classe',
            description: 'Catégorie par défaut des articles.',
        );
    }

    /**
     * Find or create flat terms by display name (used by quick tag input in editor sidebar).
     *
     * @param list<string> $names
     * @return list<int>
     */
    public function findOrCreateByNames(string $taxonomy, array $names): array
    {
        $ids = [];
        foreach ($names as $rawName) {
            $name = trim($rawName);
            if ($name === '') {
                continue;
            }
            $slug = Str::slug($name);
            if ($slug === '') {
                continue;
            }

            $existing = $this->findBySlug($taxonomy, $slug);
            if ($existing !== null) {
                $ids[] = $existing->id;
            } else {
                $created = $this->create($taxonomy, $name, $slug);
                $ids[] = $created->id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function uniqueSlug(string $taxonomy, string $baseSlug, ?int $ignoreId = null): string
    {
        $candidate = $baseSlug;
        $suffix = 2;

        while (true) {
            $qb = $this->db->table('terms')
                ->where('taxonomy', '=', $taxonomy)
                ->where('slug', '=', $candidate);

            if ($ignoreId !== null) {
                $qb->where('id', '!=', $ignoreId);
            }

            if (!$qb->exists()) {
                return $candidate;
            }

            $suffixStr = '-' . $suffix;
            $candidate = substr($baseSlug, 0, 191 - strlen($suffixStr)) . $suffixStr;
            $suffix++;
        }
    }

    private function isDescendant(string $taxonomy, int $ancestorId, int $candidateId): bool
    {
        $all = $this->allForTaxonomy($taxonomy);
        $parentMap = [];
        foreach ($all as $term) {
            $parentMap[$term->id] = $term->parentId;
        }

        $current = $candidateId;
        $guard = 0;
        while ($current > 0 && $guard < 256) {
            if ($current === $ancestorId) {
                return true;
            }
            $current = $parentMap[$current] ?? 0;
            $guard++;
        }

        return false;
    }

    private function nowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
