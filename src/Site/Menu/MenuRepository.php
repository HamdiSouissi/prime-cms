<?php

declare(strict_types=1);

namespace Prime\Site\Menu;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Prime\Content\ContentTypeRegistry;
use Prime\Content\EntryRepository;
use Prime\Content\TermRepository;
use Prime\Database\Connection;
use Prime\Site\UrlGenerator;
use Prime\Site\UrlResolver;
use Prime\Support\Str;

/**
 * Persistence and hierarchical tree resolution for navigation menus (`pc_menus`, `pc_menu_items`).
 *
 * Enforces max 3 nesting levels, strict URL scheme allowlist (`http`, `https`, `mailto`,
 * `tel`, or relative `/`), and graceful handling of deleted or unpublished entry/term targets.
 */
final class MenuRepository
{
    public const MAX_DEPTH = 3;

    public const TYPE_URL = 'url';
    public const TYPE_ENTRY = 'entry';
    public const TYPE_TERM = 'term';
    public const TYPE_ARCHIVE = 'archive';

    /**
     * @var list<string>
     */
    public const ALLOWED_ITEM_TYPES = [
        self::TYPE_URL,
        self::TYPE_ENTRY,
        self::TYPE_TERM,
        self::TYPE_ARCHIVE,
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly UrlGenerator $urls,
        private readonly ?EntryRepository $entries = null,
        private readonly ?TermRepository $terms = null,
        private readonly ?ContentTypeRegistry $types = null,
    ) {
    }

    /**
     * Validate a custom menu item URL strictly (only relative `/...`, `http://`, `https://`, `mailto:`, `tel:`, `#anchor`).
     */
    public static function sanitizeUrl(string $rawUrl): ?string
    {
        $url = trim($rawUrl);
        if ($url === '' || str_contains($url, "\0") || str_contains($url, "\r") || str_contains($url, "\n")) {
            return null;
        }

        // Allow internal paths (`/foo`, `#section`) but reject protocol-relative `//evil.com`
        if (str_starts_with($url, '/')) {
            return str_starts_with($url, '//') ? null : $url;
        }
        if (str_starts_with($url, '#')) {
            return preg_match('/^#[a-zA-Z0-9_.:-]+$/', $url) === 1 ? $url : null;
        }

        // Scheme allowlist
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.-]*):(.*)$/', $url, $m) !== 1) {
            return null;
        }

        $scheme = strtolower($m[1]);
        if ($scheme === 'http' || $scheme === 'https') {
            return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
        }
        if ($scheme === 'mailto') {
            $addr = trim(explode('?', $m[2], 2)[0]);

            return filter_var($addr, FILTER_VALIDATE_EMAIL) !== false ? 'mailto:' . $m[2] : null;
        }
        if ($scheme === 'tel') {
            $digits = trim($m[2]);

            return preg_match('/^[+0-9(). -]{3,30}$/', $digits) === 1 ? 'tel:' . $digits : null;
        }

        return null;
    }

    /**
     * List all menus ordered alphabetically.
     *
     * @return list<array{id: int, name: string, slug: string, location: ?string, item_count: int}>
     */
    public function all(): array
    {
        $rows = $this->db->table('menus')
            ->orderBy('name', 'ASC')
            ->get();

        $counts = [];
        $itemRows = $this->db->table('menu_items')->select(['menu_id'])->get();
        foreach ($itemRows as $ir) {
            $mid = (int) $ir['menu_id'];
            $counts[$mid] = ($counts[$mid] ?? 0) + 1;
        }

        $result = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $result[] = [
                'id' => $id,
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'location' => isset($row['location']) && $row['location'] !== '' ? (string) $row['location'] : null,
                'item_count' => $counts[$id] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * Find a menu by ID.
     *
     * @return ?array{id: int, name: string, slug: string, location: ?string}
     */
    public function find(int $id): ?array
    {
        $row = $this->db->table('menus')->where('id', '=', $id)->first();

        return $row !== null ? $this->mapMenuRow($row) : null;
    }

    /**
     * Find the menu assigned to a theme location (e.g. `primary`, `footer`).
     *
     * @return ?array{id: int, name: string, slug: string, location: ?string}
     */
    public function findByLocation(string $location): ?array
    {
        $clean = trim($location);
        if ($clean === '') {
            return null;
        }

        $row = $this->db->table('menus')->where('location', '=', $clean)->first();

        return $row !== null ? $this->mapMenuRow($row) : null;
    }

    /**
     * Create a new menu and return its ID.
     */
    public function create(string $name, ?string $location = null): int
    {
        $cleanName = trim($name);
        if ($cleanName === '') {
            throw new InvalidArgumentException('Le nom du menu est obligatoire.');
        }

        $baseSlug = Str::slug($cleanName, '-', 80);
        if ($baseSlug === '') {
            $baseSlug = 'menu';
        }
        $slug = $baseSlug;
        $i = 2;
        while ($this->db->table('menus')->where('slug', '=', $slug)->first() !== null) {
            $slug = substr($baseSlug, 0, 90) . '-' . $i;
            $i++;
        }

        $cleanLoc = $location !== null && trim($location) !== '' ? trim($location) : null;
        $now = $this->nowUtc();

        $id = 0;
        $this->db->transaction(function () use ($cleanName, $slug, $cleanLoc, $now, &$id): void {
            if ($cleanLoc !== null) {
                $this->db->table('menus')->where('location', '=', $cleanLoc)->update(['location' => null, 'updated_at' => $now]);
            }

            $id = $this->db->table('menus')->insert([
                'name' => mb_substr($cleanName, 0, 120),
                'slug' => $slug,
                'location' => $cleanLoc,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        return $id;
    }

    /**
     * Update menu name and/or assigned location.
     */
    public function update(int $id, string $name, ?string $location = null): void
    {
        $cleanName = trim($name);
        if ($cleanName === '') {
            throw new InvalidArgumentException('Le nom du menu est obligatoire.');
        }

        $cleanLoc = $location !== null && trim($location) !== '' ? trim($location) : null;
        $now = $this->nowUtc();

        $this->db->transaction(function () use ($id, $cleanName, $cleanLoc, $now): void {
            if ($cleanLoc !== null) {
                $this->db->table('menus')
                    ->where('location', '=', $cleanLoc)
                    ->where('id', '!=', $id)
                    ->update(['location' => null, 'updated_at' => $now]);
            }

            $this->db->table('menus')
                ->where('id', '=', $id)
                ->update([
                    'name' => mb_substr($cleanName, 0, 120),
                    'location' => $cleanLoc,
                    'updated_at' => $now,
                ]);
        });
    }

    /**
     * Delete a menu and all its items.
     */
    public function delete(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $this->db->table('menu_items')->where('menu_id', '=', $id)->delete();
            $this->db->table('menus')->where('id', '=', $id)->delete();
        });
    }

    /**
     * Replace the full hierarchical item tree of `$menuId` atomically.
     *
     * Accepts nested arrays (`children: [...]`) or flat parent-indexed items up to `MAX_DEPTH` (3).
     *
     * @param list<array<string, mixed>> $items
     */
    public function replaceItems(int $menuId, array $items): void
    {
        $now = $this->nowUtc();

        $this->db->transaction(function () use ($menuId, $items, $now): void {
            $this->db->table('menu_items')->where('menu_id', '=', $menuId)->delete();
            $this->insertTreeLevel($menuId, $items, parentId: 0, depth: 1, now: $now);
            $this->db->table('menus')->where('id', '=', $menuId)->update(['updated_at' => $now]);
        });
    }

    /**
     * Resolve menu items into a nested tree with computed URLs and missing-target flags.
     *
     * When `$forPublic` is true, items pointing to deleted or unpublished entries/terms
     * are omitted cleanly without breaking rendering.
     *
     * @return list<array<string, mixed>>
     */
    public function itemsTree(int $menuId, bool $forPublic = true): array
    {
        $rows = $this->db->table('menu_items')
            ->where('menu_id', '=', $menuId)
            ->orderBy('position', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();

        if ($rows === []) {
            return [];
        }

        // Batch-load referenced entries & terms to avoid N+1
        $entryMap = [];
        $termMap = [];
        foreach ($rows as $row) {
            $type = (string) ($row['type'] ?? self::TYPE_URL);
            $tid = isset($row['target_id']) && is_numeric($row['target_id']) ? (int) $row['target_id'] : null;
            if ($type === self::TYPE_ENTRY && $tid !== null && $tid > 0 && $this->entries !== null && !isset($entryMap[$tid])) {
                $entryMap[$tid] = $this->entries->find($tid, withRelations: false);
            } elseif ($type === self::TYPE_TERM && $tid !== null && $tid > 0 && $this->terms !== null && !isset($termMap[$tid])) {
                $termMap[$tid] = $this->terms->find($tid);
            }
        }

        $byParent = [];
        foreach ($rows as $row) {
            $resolved = $this->resolveItemRow($row, $entryMap, $termMap);
            if ($forPublic && !$resolved['available']) {
                continue;
            }
            $parentId = (int) ($row['parent_id'] ?? 0);
            $byParent[$parentId][] = $resolved;
        }

        return $this->buildNestedTree($byParent, parentId: 0, depth: 1);
    }

    /**
     * @param list<array<string, mixed>> $nodes
     */
    private function insertTreeLevel(int $menuId, array $nodes, int $parentId, int $depth, string $now): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        $pos = 0;
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = is_string($node['type'] ?? null) ? trim($node['type']) : self::TYPE_URL;
            if (!in_array($type, self::ALLOWED_ITEM_TYPES, true)) {
                throw new InvalidArgumentException(sprintf('Type d\'élément de menu invalide : "%s".', $type));
            }

            $label = trim(strip_tags((string) ($node['label'] ?? '')));
            if ($label === '') {
                throw new InvalidArgumentException('Chaque élément de menu doit posséder un libellé.');
            }
            $label = mb_substr($label, 0, 160);

            $targetId = null;
            $url = null;
            if ($type === self::TYPE_ENTRY || $type === self::TYPE_TERM) {
                $tid = is_numeric($node['target_id'] ?? null) ? (int) $node['target_id'] : 0;
                if ($tid <= 0) {
                    throw new InvalidArgumentException(sprintf('L\'élément « %s » doit cibler un contenu ou un terme valide.', $label));
                }
                $targetId = $tid;
            } elseif ($type === self::TYPE_ARCHIVE) {
                $archiveType = is_string($node['url'] ?? null) ? trim($node['url']) : '';
                if ($archiveType === '' || preg_match('/^[a-z0-9_-]{1,64}$/', $archiveType) !== 1) {
                    throw new InvalidArgumentException(sprintf('Type d\'archive invalide pour « %s ».', $label));
                }
                $url = $archiveType;
            } else {
                $sanitized = self::sanitizeUrl((string) ($node['url'] ?? ''));
                if ($sanitized === null) {
                    throw new InvalidArgumentException(sprintf(
                        'L\'URL de « %s » est invalide ou utilise un protocole non autorisé.',
                        $label,
                    ));
                }
                $url = mb_substr($sanitized, 0, 500);
            }

            $rawTarget = is_string($node['target'] ?? null) ? trim($node['target']) : '_self';
            $target = $rawTarget === '_blank' ? '_blank' : '_self';

            $rawCss = is_string($node['css_class'] ?? null) ? trim($node['css_class']) : '';
            $cssClass = null;
            if ($rawCss !== '') {
                $cleanTokens = [];
                foreach (preg_split('/\s+/', $rawCss) ?: [] as $tok) {
                    if (preg_match('/^[a-zA-Z_-][a-zA-Z0-9_-]{0,48}$/', $tok) === 1) {
                        $cleanTokens[] = $tok;
                    }
                }
                if ($cleanTokens !== []) {
                    $cssClass = mb_substr(implode(' ', $cleanTokens), 0, 120);
                }
            }

            $insertedId = $this->db->table('menu_items')->insert([
                'menu_id' => $menuId,
                'parent_id' => $parentId,
                'type' => $type,
                'target_id' => $targetId,
                'label' => $label,
                'url' => $url,
                'target' => $target,
                'css_class' => $cssClass,
                'position' => $pos,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $children = is_array($node['children'] ?? null) ? array_values($node['children']) : [];
            if ($children !== [] && $depth < self::MAX_DEPTH) {
                $this->insertTreeLevel($menuId, $children, parentId: $insertedId, depth: $depth + 1, now: $now);
            }

            $pos++;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, ?\Prime\Content\Entry> $entryMap
     * @param array<int, ?\Prime\Content\Term> $termMap
     * @return array<string, mixed>
     */
    private function resolveItemRow(array $row, array $entryMap, array $termMap): array
    {
        $type = (string) ($row['type'] ?? self::TYPE_URL);
        $targetId = isset($row['target_id']) && is_numeric($row['target_id']) ? (int) $row['target_id'] : null;
        $storedUrl = isset($row['url']) && is_string($row['url']) ? $row['url'] : '';
        $label = (string) ($row['label'] ?? '');
        $available = true;
        $statusNote = null;
        $resolvedUrl = '#';

        if ($type === self::TYPE_ENTRY) {
            $entry = $targetId !== null ? ($entryMap[$targetId] ?? null) : null;
            if ($entry === null) {
                $available = false;
                $statusNote = 'Contenu supprimé';
            } elseif (!UrlResolver::isPubliclyVisible($entry)) {
                $available = false;
                $statusNote = 'Non publié (' . $entry->statusLabel() . ')';
                $resolvedUrl = $this->urls->entryUrl($entry);
            } else {
                $resolvedUrl = $this->urls->entryUrl($entry);
            }
        } elseif ($type === self::TYPE_TERM) {
            $term = $targetId !== null ? ($termMap[$targetId] ?? null) : null;
            if ($term === null) {
                $available = false;
                $statusNote = 'Terme supprimé';
            } else {
                $resolvedUrl = $this->urls->termUrl($term);
            }
        } elseif ($type === self::TYPE_ARCHIVE) {
            if ($this->types !== null && $storedUrl !== 'post' && $this->types->get($storedUrl) === null) {
                $available = false;
                $statusNote = 'Type introuvable';
            } else {
                $resolvedUrl = $this->urls->archiveUrl($storedUrl !== '' ? $storedUrl : 'post');
            }
        } else {
            $safe = self::sanitizeUrl($storedUrl);
            if ($safe === null) {
                $available = false;
                $statusNote = 'URL invalide';
            } else {
                $resolvedUrl = $safe;
            }
        }

        return [
            'id' => (int) $row['id'],
            'parent_id' => (int) ($row['parent_id'] ?? 0),
            'type' => $type,
            'target_id' => $targetId,
            'label' => $label,
            'raw_url' => $storedUrl,
            'url' => $resolvedUrl,
            'target' => ((string) ($row['target'] ?? '_self')) === '_blank' ? '_blank' : '_self',
            'css_class' => isset($row['css_class']) && is_string($row['css_class']) ? $row['css_class'] : '',
            'position' => (int) ($row['position'] ?? 0),
            'available' => $available,
            'status_note' => $statusNote,
            'children' => [],
        ];
    }

    /**
     * @param array<int, list<array<string, mixed>>> $byParent
     * @return list<array<string, mixed>>
     */
    private function buildNestedTree(array $byParent, int $parentId, int $depth): array
    {
        if ($depth > self::MAX_DEPTH || !isset($byParent[$parentId])) {
            return [];
        }

        $branch = [];
        foreach ($byParent[$parentId] as $item) {
            $item['depth'] = $depth;
            $item['children'] = $this->buildNestedTree($byParent, (int) $item['id'], $depth + 1);
            $branch[] = $item;
        }

        return $branch;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, name: string, slug: string, location: ?string}
     */
    private function mapMenuRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'location' => isset($row['location']) && $row['location'] !== '' ? (string) $row['location'] : null,
        ];
    }

    private function nowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
