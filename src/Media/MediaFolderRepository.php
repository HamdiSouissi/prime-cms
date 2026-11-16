<?php

declare(strict_types=1);

namespace Prime\Media;

use DateTimeImmutable;
use DateTimeZone;
use Prime\Database\Connection;
use Prime\Media\Exception\MediaValidationException;
use Prime\Support\Str;

/**
 * Data access layer for hierarchical media folders (`pc_media_folders`).
 */
final class MediaFolderRepository
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function find(int $id): ?MediaFolder
    {
        $row = $this->db->table('media_folders')
            ->where('id', '=', $id)
            ->first();

        return $row !== null ? MediaFolder::fromRow($row) : null;
    }

    /**
     * Return all folders ordered alphabetically with media counts per folder.
     *
     * @return list<MediaFolder>
     */
    public function allWithCounts(): array
    {
        $rows = $this->db->table('media_folders')
            ->orderBy('name', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();

        $counts = $this->mediaCountsByFolder();
        $folders = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $folders[] = MediaFolder::fromRow($row, $counts[$id] ?? 0);
        }

        return $folders;
    }

    /**
     * Build a nested folder tree ready for sidebar & picker rendering.
     *
     * @return list<array{folder: MediaFolder, depth: int, children: list<mixed>}>
     */
    public function tree(): array
    {
        $all = $this->allWithCounts();
        $byParent = [];
        foreach ($all as $folder) {
            $key = $folder->parentId ?? 0;
            $byParent[$key][] = $folder;
        }

        return $this->buildBranch($byParent, 0, 0, []);
    }

    /**
     * Flattened depth-annotated list of folders (for `<select>` dropdowns on mobile and move menus).
     *
     * @return list<array{folder: MediaFolder, depth: int}>
     */
    public function flatTree(): array
    {
        $result = [];
        $walk = static function (array $nodes) use (&$walk, &$result): void {
            foreach ($nodes as $node) {
                $result[] = [
                    'folder' => $node['folder'],
                    'depth' => $node['depth'],
                ];
                if ($node['children'] !== []) {
                    $walk($node['children']);
                }
            }
        };

        $walk($this->tree());

        return $result;
    }

    public function create(string $name, ?int $parentId = null): MediaFolder
    {
        $cleanName = trim($name);
        if ($cleanName === '' || mb_strlen($cleanName, 'UTF-8') > 120) {
            throw new MediaValidationException(
                'Le nom du dossier doit contenir entre 1 et 120 caractères.',
                ['name' => ['Le nom du dossier doit contenir entre 1 et 120 caractères.']],
            );
        }

        if ($parentId !== null) {
            if ($parentId <= 0 || $this->find($parentId) === null) {
                throw new MediaValidationException(
                    'Le dossier parent sélectionné est introuvable.',
                    ['parent_id' => ['Le dossier parent sélectionné est introuvable.']],
                );
            }
        }

        $baseSlug = Str::slug($cleanName, '-', 100);
        if ($baseSlug === '') {
            $baseSlug = 'dossier';
        }
        $slug = $this->uniqueSlug($baseSlug, $parentId);
        $now = $this->nowUtc();

        $id = $this->db->table('media_folders')->insertGetId([
            'parent_id' => $parentId,
            'name' => $cleanName,
            'slug' => $slug,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $created = $this->find($id);
        if ($created === null) {
            throw new MediaValidationException('Impossible de charger le dossier créé.');
        }

        return $created;
    }

    public function update(int $id, ?string $name = null, bool $changeParent = false, ?int $parentId = null): MediaFolder
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new MediaValidationException('Dossier introuvable.');
        }

        $newName = $name !== null ? trim($name) : $existing->name;
        if ($newName === '' || mb_strlen($newName, 'UTF-8') > 120) {
            throw new MediaValidationException(
                'Le nom du dossier doit contenir entre 1 et 120 caractères.',
                ['name' => ['Le nom du dossier doit contenir entre 1 et 120 caractères.']],
            );
        }

        $targetParent = $changeParent ? $parentId : $existing->parentId;
        if ($targetParent !== null) {
            if ($targetParent === $id) {
                throw new MediaValidationException(
                    'Un dossier ne peut pas être son propre parent.',
                    ['parent_id' => ['Un dossier ne peut pas être son propre parent.']],
                );
            }
            if ($this->find($targetParent) === null) {
                throw new MediaValidationException(
                    'Le dossier parent sélectionné est introuvable.',
                    ['parent_id' => ['Le dossier parent sélectionné est introuvable.']],
                );
            }
            if ($this->isDescendantOf($targetParent, $id)) {
                throw new MediaValidationException(
                    'Impossible de déplacer un dossier à l\'intérieur d\'un de ses sous-dossiers.',
                    ['parent_id' => ['Déplacement circulaire interdit.']],
                );
            }
        }

        $baseSlug = Str::slug($newName, '-', 100);
        if ($baseSlug === '') {
            $baseSlug = 'dossier';
        }
        $slug = $this->uniqueSlug($baseSlug, $targetParent, excludeId: $id);

        $this->db->table('media_folders')
            ->where('id', '=', $id)
            ->update([
                'name' => $newName,
                'slug' => $slug,
                'parent_id' => $targetParent,
                'updated_at' => $this->nowUtc(),
            ]);

        $updated = $this->find($id);
        if ($updated === null) {
            throw new MediaValidationException('Dossier introuvable après mise à jour.');
        }

        return $updated;
    }

    /**
     * Delete a folder and reparent all its direct sub-folders and media items to its parent.
     */
    public function delete(int $id): bool
    {
        $folder = $this->find($id);
        if ($folder === null) {
            return false;
        }

        $targetParent = $folder->parentId;
        $now = $this->nowUtc();

        $this->db->transaction(function () use ($id, $targetParent, $now): void {
            // Reassign media in this folder to the deleted folder's parent (or root NULL)
            $this->db->table('media')
                ->where('folder_id', '=', $id)
                ->update([
                    'folder_id' => $targetParent,
                    'updated_at' => $now,
                ]);

            // Reparent direct child folders while ensuring slug uniqueness at destination
            $children = $this->db->table('media_folders')
                ->where('parent_id', '=', $id)
                ->get();

            foreach ($children as $child) {
                $childId = (int) $child['id'];
                $childSlug = (string) $child['slug'];
                $safeSlug = $this->uniqueSlug($childSlug, $targetParent, excludeId: $childId);

                $this->db->table('media_folders')
                    ->where('id', '=', $childId)
                    ->update([
                        'parent_id' => $targetParent,
                        'slug' => $safeSlug,
                        'updated_at' => $now,
                    ]);
            }

            $this->db->table('media_folders')
                ->where('id', '=', $id)
                ->delete();
        });

        return true;
    }

    public function countAll(): int
    {
        return $this->db->table('media_folders')->count();
    }

    /**
     * Return all descendant folder IDs (inclusive of `$folderId`) for recursive queries if needed.
     *
     * @return list<int>
     */
    public function descendantIds(int $folderId): array
    {
        $all = $this->db->table('media_folders')->select(['id', 'parent_id'])->get();
        $childrenMap = [];
        foreach ($all as $row) {
            $pid = isset($row['parent_id']) && $row['parent_id'] !== '' ? (int) $row['parent_id'] : 0;
            $childrenMap[$pid][] = (int) $row['id'];
        }

        $result = [$folderId];
        $queue = [$folderId];
        $visited = [$folderId => true];

        while ($queue !== []) {
            $curr = array_shift($queue);
            foreach ($childrenMap[$curr] ?? [] as $childId) {
                if (!isset($visited[$childId])) {
                    $visited[$childId] = true;
                    $result[] = $childId;
                    $queue[] = $childId;
                }
            }
        }

        return $result;
    }

    /**
     * @return array<int, int>
     */
    private function mediaCountsByFolder(): array
    {
        $mediaTable = $this->db->tableName('media');
        $rows = $this->db->select(
            sprintf('SELECT `folder_id`, COUNT(*) AS `cnt` FROM `%s` WHERE `folder_id` IS NOT NULL GROUP BY `folder_id`', $mediaTable),
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['folder_id']] = (int) $row['cnt'];
        }

        return $map;
    }

    private function isDescendantOf(int $candidateId, int $ancestorId): bool
    {
        $currentId = $candidateId;
        $guard = 0;

        while ($currentId > 0 && $guard < 64) {
            $guard++;
            if ($currentId === $ancestorId) {
                return true;
            }
            $row = $this->db->table('media_folders')
                ->select(['parent_id'])
                ->where('id', '=', $currentId)
                ->first();
            if ($row === null || !isset($row['parent_id']) || $row['parent_id'] === '') {
                return false;
            }
            $currentId = (int) $row['parent_id'];
        }

        return false;
    }

    private function uniqueSlug(string $baseSlug, ?int $parentId, ?int $excludeId = null): string
    {
        $candidate = $baseSlug;
        $suffix = 2;

        while ($this->slugExists($candidate, $parentId, $excludeId)) {
            $candidate = mb_substr($baseSlug, 0, 108, 'UTF-8') . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function slugExists(string $slug, ?int $parentId, ?int $excludeId = null): bool
    {
        $query = $this->db->table('media_folders')->where('slug', '=', $slug);
        if ($parentId === null) {
            $query->whereNull('parent_id');
        } else {
            $query->where('parent_id', '=', $parentId);
        }

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * @param array<int, list<MediaFolder>> $byParent
     * @param array<int, true> $visited
     * @return list<array{folder: MediaFolder, depth: int, children: list<mixed>}>
     */
    private function buildBranch(array $byParent, int $parentId, int $depth, array $visited): array
    {
        $nodes = [];
        foreach ($byParent[$parentId] ?? [] as $folder) {
            if (isset($visited[$folder->id])) {
                continue;
            }
            $nextVisited = $visited;
            $nextVisited[$folder->id] = true;
            $nodes[] = [
                'folder' => $folder,
                'depth' => $depth,
                'children' => $this->buildBranch($byParent, $folder->id, $depth + 1, $nextVisited),
            ];
        }

        return $nodes;
    }

    private function nowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
