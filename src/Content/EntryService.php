<?php

declare(strict_types=1);

namespace Prime\Content;

use DateTimeImmutable;
use DateTimeZone;
use Prime\Auth\User;
use Prime\Content\Exception\ContentConflictException;
use Prime\Content\Exception\ContentException;
use Prime\Content\Exception\ContentForbiddenException;
use Prime\Content\Exception\ContentLockedException;
use Prime\Content\Exception\ContentValidationException;
use Prime\Content\Fields\FieldRegistry;
use Prime\Content\Fields\FieldValidator;
use Prime\Editor\Renderer;
use Prime\Hooks\Hooks;
use Prime\Http\Router;
use Prime\Media\MediaRepository;
use Prime\Media\MediaUsageRepository;
use Prime\Support\Str;
use Throwable;

/**
 * Single authoritative domain service for creating, updating, scheduling, trashing,
 * restoring, deleting, duplicating, revising, locking, and re-rendering content entries.
 */
final class EntryService
{
    /**
     * Reserved top-level slugs forbidden for root pages (`parent_id = 0`).
     *
     * @var list<string>
     */
    public const BASE_RESERVED_SLUGS = [
        'admin',
        'install',
        'api',
        'assets',
        'uploads',
        'health',
        'login',
        'content',
    ];

    public function __construct(
        private readonly EntryRepository $entries,
        private readonly TermRepository $terms,
        private readonly RevisionRepository $revisions,
        private readonly EntryLockRepository $locks,
        private readonly ContentTypeRegistry $types,
        private readonly TaxonomyRegistry $taxonomies,
        private readonly Renderer $renderer,
        private readonly FieldValidator $fieldValidator,
        private readonly Hooks $hooks,
        private readonly ?Router $router = null,
        private readonly int $revisionsMax = 25,
        private readonly string $schedulerStampPath = '',
        private readonly int $schedulerIntervalSeconds = 60,
        private readonly ?MediaUsageRepository $mediaUsage = null,
        private readonly ?FieldRegistry $fields = null,
        private readonly ?MediaRepository $media = null,
    ) {
    }

    public function contentTypes(): ContentTypeRegistry
    {
        return $this->types;
    }

    /**
     * Check whether `$user` can create entries of `$type`.
     */
    public function canCreate(User $user, ContentType $type): bool
    {
        return $user->can($type->capability('create'));
    }

    /**
     * Check whether `$user` can edit `$entry` (enforcing ownership and published-content rules).
     */
    public function canEdit(User $user, Entry $entry, ContentType $type): bool
    {
        if ($user->can($type->capability('edit_any'))) {
            return true;
        }

        if ($entry->authorId !== $user->id || !$user->can($type->capability('edit_own'))) {
            return false;
        }

        // Contributors (who lack `publish` and `edit_any`) can only edit their own drafts;
        // once submitted (`pending`) or published, the entry becomes non-editable for them.
        if (
            in_array($entry->status, [Entry::STATUS_PENDING, Entry::STATUS_PUBLISHED, Entry::STATUS_SCHEDULED, Entry::STATUS_PRIVATE], true)
            && !$user->can($type->capability('publish'))
        ) {
            return false;
        }

        return true;
    }

    /**
     * Check whether `$user` can transition an entry to or from published/scheduled/private statuses.
     */
    public function canPublish(User $user, ContentType $type): bool
    {
        return $user->can($type->capability('publish'));
    }

    /**
     * Check whether `$user` can trash, restore, or permanently delete `$entry`.
     */
    public function canDelete(User $user, Entry $entry, ContentType $type): bool
    {
        if ($user->can($type->capability('delete_any'))) {
            return true;
        }

        return $entry->authorId === $user->id && $user->can($type->capability('delete_own'));
    }

    /**
     * Check whether `$user` can preview/view `$entry` (including drafts, pending, private, scheduled).
     */
    public function canPreview(User $user, Entry $entry, ContentType $type): bool
    {
        if ($entry->status === Entry::STATUS_PUBLISHED) {
            return $user->can($type->capability('view'));
        }

        if ($user->can($type->capability('edit_any'))) {
            return true;
        }

        return $entry->authorId === $user->id && $user->can($type->capability('edit_own'));
    }

    /**
     * Check whether `$user` can forcefully take over another user's active edit lock.
     */
    public function canTakeOverLock(User $user, ContentType $type): bool
    {
        return $user->can($type->capability('edit_any'));
    }

    /**
     * Return all statuses `$user` is permitted to assign to an entry of `$type`.
     *
     * @return list<string>
     */
    public function allowedStatusesFor(User $user, ContentType $type): array
    {
        $statuses = [Entry::STATUS_DRAFT, Entry::STATUS_PENDING];
        if ($this->canPublish($user, $type)) {
            $statuses[] = Entry::STATUS_PUBLISHED;
            $statuses[] = Entry::STATUS_SCHEDULED;
            $statuses[] = Entry::STATUS_PRIVATE;
        }

        return $statuses;
    }

    /**
     * Create a new entry with full validation, slug generation, custom fields, taxonomy terms,
     * initial revision, HTML rendering, and lifecycle hooks.
     *
     * @param array<string, mixed> $input
     */
    public function create(string $typeName, array $input, User $actor): Entry
    {
        $type = $this->requireType($typeName);
        if (!$this->canCreate($actor, $type)) {
            throw new ContentForbiddenException('Vous n\'avez pas la permission de créer ce type de contenu.');
        }

        $payload = $this->preparePayload($type, $input, $actor, existing: null);
        $now = $this->nowUtc();

        // Allow plugins to inspect or enrich payload before persistence
        $filtered = $this->hooks->apply('content.saving', $payload, null, $type, $actor);
        if (is_array($filtered)) {
            $payload = array_merge($payload, $filtered);
        }

        $entryId = $this->entries->insert([
            'type' => $type->name,
            'status' => $payload['status'],
            'title' => $payload['title'],
            'slug' => $payload['slug'],
            'excerpt' => $payload['excerpt'],
            'content' => $payload['content'],
            'content_html' => $payload['content_html'],
            'content_text' => $payload['content_text'],
            'author_id' => $payload['author_id'],
            'parent_id' => $payload['parent_id'],
            'menu_order' => $payload['menu_order'],
            'template' => $payload['template'],
            'published_at' => $payload['published_at'],
            'previous_status' => null,
            'trashed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var array<string, mixed> $meta */
        $meta = $payload['meta'];
        if ($meta !== []) {
            $this->entries->setMeta($entryId, $meta);
        }

        /** @var list<int> $termIds */
        $termIds = $payload['term_ids'];
        $affectedTerms = $this->entries->syncTerms($entryId, $termIds);
        if ($affectedTerms !== []) {
            $this->terms->recountTerms($affectedTerms);
        }

        if ($type->supports('revisions') && $this->revisionsMax > 0) {
            $this->revisions->create(
                entryId: $entryId,
                authorId: $actor->id,
                title: (string) $payload['title'],
                excerpt: is_string($payload['excerpt']) ? $payload['excerpt'] : null,
                contentJson: (string) $payload['content'],
                meta: $meta,
                maxKeep: $this->revisionsMax,
            );
        }

        $entry = $this->entries->find($entryId);
        if ($entry === null) {
            throw new ContentException('Impossible de relire le contenu créé.');
        }

        // Re-render once with the real hydrated Entry for `content.render` filters that inspect `$entry`
        $finalHtml = $this->renderer->renderHtml(
            $entry->decodedContent(),
            $entry,
            allowUnfilteredHtml: $actor->can('content.unfiltered_html'),
        );
        if ($finalHtml !== $entry->contentHtml) {
            $this->entries->updateRow($entry->id, ['content_html' => $finalHtml]);
            $entry = $this->entries->find($entry->id) ?? $entry;
        }

        $this->syncEntryMediaUsage($entry);

        $this->hooks->emit('content.saved', $entry, true, $actor);
        if ($entry->status === Entry::STATUS_PUBLISHED) {
            $this->hooks->emit('content.published', $entry, $actor);
        }

        return $entry;
    }

    /**
     * Update an existing entry with lock enforcement, RBAC, revision creation, and hooks.
     *
     * @param array<string, mixed> $input
     */
    public function update(int $entryId, array $input, User $actor, bool $bypassLock = false): Entry
    {
        $existing = $this->entries->find($entryId);
        if ($existing === null) {
            throw new ContentException('Contenu introuvable.');
        }

        $type = $this->requireType($existing->type);
        if (!$this->canEdit($actor, $existing, $type)) {
            throw new ContentForbiddenException('Vous n\'avez pas la permission de modifier ce contenu.');
        }

        if (!$bypassLock) {
            $otherLock = $this->locks->lockedByOther($existing->id, $actor->id);
            if ($otherLock !== null) {
                throw new ContentLockedException(
                    entryId: $existing->id,
                    lockedByUserId: $otherLock->userId,
                    lockedByName: $otherLock->userName !== '' ? $otherLock->userName : 'un autre utilisateur',
                    acquiredAt: $otherLock->acquiredAt,
                    message: sprintf(
                        'Ce contenu est actuellement verrouillé par %s. Rechargez la page avant d\'enregistrer.',
                        $otherLock->userName !== '' ? $otherLock->userName : 'un autre utilisateur',
                    ),
                );
            }
        }

        $payload = $this->preparePayload($type, $input, $actor, existing: $existing);
        $now = $this->nowUtc();

        $filtered = $this->hooks->apply('content.saving', $payload, $existing, $type, $actor);
        if (is_array($filtered)) {
            $payload = array_merge($payload, $filtered);
        }

        // Determine whether tracked editorial fields changed (for revision creation)
        /** @var array<string, mixed> $newMeta */
        $newMeta = $payload['meta'];
        $mergedMeta = array_merge($existing->meta, $newMeta);
        $hasEditorialChanges = $existing->title !== $payload['title']
            || $existing->excerpt !== $payload['excerpt']
            || $existing->content !== $payload['content']
            || json_encode($existing->meta) !== json_encode($mergedMeta);

        $this->entries->updateRow($existing->id, [
            'status' => $payload['status'],
            'title' => $payload['title'],
            'slug' => $payload['slug'],
            'excerpt' => $payload['excerpt'],
            'content' => $payload['content'],
            'content_html' => $payload['content_html'],
            'content_text' => $payload['content_text'],
            'author_id' => $payload['author_id'],
            'parent_id' => $payload['parent_id'],
            'menu_order' => $payload['menu_order'],
            'template' => $payload['template'],
            'published_at' => $payload['published_at'],
            'updated_at' => $now,
        ]);

        if ($newMeta !== []) {
            $this->entries->setMeta($existing->id, $newMeta);
        }

        if ($payload['sync_terms'] === true) {
            /** @var list<int> $termIds */
            $termIds = $payload['term_ids'];
            $affectedTerms = $this->entries->syncTerms($existing->id, $termIds);
            if ($affectedTerms !== []) {
                $this->terms->recountTerms($affectedTerms);
            }
        } elseif ($existing->status !== $payload['status']) {
            $existingTermIds = $existing->termIds();
            if ($existingTermIds !== []) {
                $this->terms->recountTerms($existingTermIds);
            }
        }

        if ($hasEditorialChanges && $type->supports('revisions') && $this->revisionsMax > 0) {
            $this->revisions->create(
                entryId: $existing->id,
                authorId: $actor->id,
                title: (string) $payload['title'],
                excerpt: is_string($payload['excerpt']) ? $payload['excerpt'] : null,
                contentJson: (string) $payload['content'],
                meta: $mergedMeta,
                maxKeep: $this->revisionsMax,
            );
        }

        // Clear user's autosave after a clean explicit save
        $this->revisions->clearAutosave($existing->id, $actor->id);

        $updated = $this->entries->find($existing->id);
        if ($updated === null) {
            throw new ContentException('Impossible de relire le contenu mis à jour.');
        }

        $this->syncEntryMediaUsage($updated);

        $this->hooks->emit('content.saved', $updated, false, $actor);
        $this->hooks->emit('content.updated', $updated, $existing, $actor);

        if ($existing->status !== $updated->status) {
            $this->hooks->emit('content.status_changed', $updated, $existing->status, $updated->status, $actor);
            if ($updated->status === Entry::STATUS_PUBLISHED) {
                $this->hooks->emit('content.published', $updated, $actor);
            }
        }

        return $updated;
    }

    /**
     * Save an unobtrusive autosave snapshot for `(entry, actor)` without overwriting
     * the live entry or creating multiple autosave rows.
     *
     * @param array<string, mixed> $input
     */
    public function autosave(int $entryId, array $input, User $actor): Revision
    {
        $existing = $this->entries->find($entryId);
        if ($existing === null) {
            throw new ContentException('Contenu introuvable.');
        }

        $type = $this->requireType($existing->type);
        if (!$this->canEdit($actor, $existing, $type)) {
            throw new ContentForbiddenException('Permission insuffisante pour l\'autosauvegarde.');
        }

        $otherLock = $this->locks->lockedByOther($existing->id, $actor->id);
        if ($otherLock !== null) {
            throw new ContentLockedException(
                entryId: $existing->id,
                lockedByUserId: $otherLock->userId,
                lockedByName: $otherLock->userName,
                acquiredAt: $otherLock->acquiredAt,
            );
        }

        $title = is_string($input['title'] ?? null) ? mb_substr(trim($input['title']), 0, 255) : $existing->title;
        $excerpt = isset($input['excerpt']) && is_string($input['excerpt']) ? trim($input['excerpt']) : $existing->excerpt;

        $preservedHtml = $this->renderer->extractUnfilteredHtmlBlocks($existing->content);
        $rawContent = $input['content'] ?? $existing->content;
        $contentResult = $this->renderer->validateAndNormalize(
            is_string($rawContent) || is_array($rawContent) ? $rawContent : $existing->content,
            allowUnfilteredHtml: $actor->can('content.unfiltered_html'),
            preservedUnfilteredBlocks: $preservedHtml,
        );

        $contentJson = $contentResult['valid']
            ? $this->renderer->encode($contentResult['document'])
            : $existing->content;

        /** @var array<string, mixed> $meta */
        $meta = is_array($input['fields'] ?? null) ? $input['fields'] : $existing->meta;

        return $this->revisions->upsertAutosave(
            entryId: $existing->id,
            authorId: $actor->id,
            title: $title,
            excerpt: $excerpt !== '' ? $excerpt : null,
            contentJson: $contentJson,
            meta: $meta,
        );
    }

    /**
     * Restore a previous revision (or autosave) onto its entry, creating a new revision.
     */
    public function restoreRevision(int $entryId, int $revisionId, User $actor): Entry
    {
        $existing = $this->entries->find($entryId);
        if ($existing === null) {
            throw new ContentException('Contenu introuvable.');
        }

        $revision = $this->revisions->find($revisionId);
        if ($revision === null || $revision->entryId !== $existing->id) {
            throw new ContentException('Révision introuvable pour ce contenu.');
        }

        return $this->update($existing->id, [
            'title' => $revision->title,
            'excerpt' => $revision->excerpt ?? '',
            'content' => $revision->content,
            'fields' => $revision->meta,
            'status' => $existing->status,
            'slug' => $existing->slug,
            'parent_id' => $existing->parentId,
            'menu_order' => $existing->menuOrder,
            'template' => $existing->template,
            'published_at' => $existing->publishedAt,
            'author_id' => $existing->authorId,
        ], $actor);
    }

    /**
     * Move an entry to trash, remembering `previous_status` and `trashed_at`.
     */
    public function trash(int $entryId, User $actor): Entry
    {
        $existing = $this->entries->find($entryId);
        if ($existing === null) {
            throw new ContentException('Contenu introuvable.');
        }

        $type = $this->requireType($existing->type);
        if (!$this->canDelete($actor, $existing, $type)) {
            throw new ContentForbiddenException('Vous n\'avez pas la permission de mettre ce contenu à la corbeille.');
        }

        if ($existing->status === Entry::STATUS_TRASH) {
            return $existing;
        }

        $now = $this->nowUtc();
        $this->entries->updateRow($existing->id, [
            'previous_status' => $existing->status,
            'status' => Entry::STATUS_TRASH,
            'trashed_at' => $now,
            'updated_at' => $now,
        ]);

        $this->locks->release($existing->id);

        $termIds = $existing->termIds();
        if ($termIds !== []) {
            $this->terms->recountTerms($termIds);
        }

        $trashed = $this->entries->find($existing->id) ?? $existing;
        $this->hooks->emit('content.status_changed', $trashed, $existing->status, Entry::STATUS_TRASH, $actor);
        $this->hooks->emit('content.trashed', $trashed, $actor);

        return $trashed;
    }

    /**
     * Restore a trashed entry to its `previous_status` (or `draft` if a scheduled date already elapsed).
     */
    public function restore(int $entryId, User $actor): Entry
    {
        $existing = $this->entries->find($entryId);
        if ($existing === null) {
            throw new ContentException('Contenu introuvable.');
        }

        $type = $this->requireType($existing->type);
        if (!$this->canDelete($actor, $existing, $type)) {
            throw new ContentForbiddenException('Vous n\'avez pas la permission de restaurer ce contenu.');
        }

        if ($existing->status !== Entry::STATUS_TRASH) {
            return $existing;
        }

        $now = $this->nowUtc();
        $targetStatus = $existing->previousStatus ?? Entry::STATUS_DRAFT;
        if (!in_array($targetStatus, Entry::ALL_STATUSES, true) || $targetStatus === Entry::STATUS_TRASH) {
            $targetStatus = Entry::STATUS_DRAFT;
        }

        // Scheduled entry whose date has already passed restores cleanly as draft
        if ($targetStatus === Entry::STATUS_SCHEDULED && ($existing->publishedAt === null || $existing->publishedAt <= $now)) {
            $targetStatus = Entry::STATUS_DRAFT;
        }

        // Respect publishing capability when restoring
        if (
            in_array($targetStatus, [Entry::STATUS_PUBLISHED, Entry::STATUS_SCHEDULED, Entry::STATUS_PRIVATE], true)
            && !$this->canPublish($actor, $type)
        ) {
            $targetStatus = Entry::STATUS_DRAFT;
        }

        $this->entries->updateRow($existing->id, [
            'status' => $targetStatus,
            'previous_status' => null,
            'trashed_at' => null,
            'updated_at' => $now,
        ]);

        $termIds = $existing->termIds();
        if ($termIds !== []) {
            $this->terms->recountTerms($termIds);
        }

        $restored = $this->entries->find($existing->id) ?? $existing;
        $this->hooks->emit('content.status_changed', $restored, Entry::STATUS_TRASH, $targetStatus, $actor);
        $this->hooks->emit('content.restored', $restored, $actor);

        return $restored;
    }

    /**
     * Permanently delete a trashed entry (and all its meta, revisions, terms links, and locks).
     * Refuses permanent deletion of entries that are not already in the trash (HTTP 409).
     */
    public function delete(int $entryId, User $actor): void
    {
        $existing = $this->entries->find($entryId);
        if ($existing === null) {
            return;
        }

        $type = $this->requireType($existing->type);
        if (!$this->canDelete($actor, $existing, $type)) {
            throw new ContentForbiddenException('Vous n\'avez pas la permission de supprimer définitivement ce contenu.');
        }

        if ($existing->status !== Entry::STATUS_TRASH) {
            throw new ContentConflictException(
                'Ce contenu doit d’abord être placé dans la corbeille avant de pouvoir être supprimé définitivement.',
            );
        }

        $termIds = $existing->termIds();
        $this->entries->deletePermanent($existing->id);

        if ($this->mediaUsage !== null) {
            try {
                $this->mediaUsage->deleteForEntry($existing->id);
            } catch (Throwable) {
            }
        }

        if ($termIds !== []) {
            $this->terms->recountTerms($termIds);
        }

        $this->hooks->emit('content.deleted', $existing, $actor);
    }

    /**
     * Duplicate an entry into a new draft titled "{title} (copie)" with copied content, meta, and terms.
     */
    public function duplicate(int $entryId, User $actor): Entry
    {
        $source = $this->entries->find($entryId);
        if ($source === null) {
            throw new ContentException('Contenu introuvable.');
        }

        $type = $this->requireType($source->type);
        if (!$this->canCreate($actor, $type)) {
            throw new ContentForbiddenException('Vous n\'avez pas la permission de dupliquer ce contenu.');
        }

        $copyTitle = mb_substr(trim($source->title), 0, 245) . ' (copie)';
        $termIdsByTax = [];
        foreach ($source->terms as $taxonomy => $termList) {
            $termIdsByTax[$taxonomy] = array_map(static fn (Term $t): int => $t->id, $termList);
        }

        return $this->create($source->type, [
            'title' => $copyTitle,
            'slug' => '',
            'status' => Entry::STATUS_DRAFT,
            'excerpt' => $source->excerpt ?? '',
            'content' => $source->content,
            'parent_id' => $source->parentId,
            'menu_order' => $source->menuOrder,
            'template' => $source->template,
            'fields' => $source->meta,
            'terms' => $termIdsByTax,
        ], $actor);
    }

    /**
     * Publish all scheduled entries whose `published_at <= nowUtc`.
     */
    public function publishScheduled(?string $nowUtc = null): int
    {
        $due = $this->entries->dueScheduled($nowUtc);
        if ($due === []) {
            return 0;
        }

        $now = $nowUtc ?? $this->nowUtc();
        $affectedTerms = [];

        foreach ($due as $entry) {
            $this->entries->updateRow($entry->id, [
                'status' => Entry::STATUS_PUBLISHED,
                'updated_at' => $now,
            ]);

            $affectedTerms = [...$affectedTerms, ...$entry->termIds()];
            $published = $this->entries->find($entry->id) ?? $entry;
            $this->hooks->emit('content.status_changed', $published, Entry::STATUS_SCHEDULED, Entry::STATUS_PUBLISHED, null);
            $this->hooks->emit('content.published', $published, null);
        }

        if ($affectedTerms !== []) {
            $this->terms->recountTerms($affectedTerms);
        }

        return count($due);
    }

    /**
     * Lightweight request-time scheduler trigger: executes `publishScheduled()` at most
     * once per `$schedulerIntervalSeconds` (default 60s) via a lock timestamp file.
     */
    public function maybePublishScheduled(): int
    {
        if ($this->schedulerStampPath === '') {
            return 0;
        }

        $nowTs = time();
        if (is_file($this->schedulerStampPath)) {
            $lastRun = (int) trim((string) @file_get_contents($this->schedulerStampPath));
            if (($nowTs - $lastRun) < $this->schedulerIntervalSeconds) {
                return 0;
            }
        }

        $dir = dirname($this->schedulerStampPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fp = @fopen($this->schedulerStampPath, 'c+');
        if ($fp === false) {
            return 0;
        }

        try {
            if (!flock($fp, LOCK_EX | LOCK_NB)) {
                return 0;
            }

            $content = stream_get_contents($fp);
            $lastRun = (int) trim(is_string($content) ? $content : '');
            if (($nowTs - $lastRun) < $this->schedulerIntervalSeconds) {
                return 0;
            }

            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, (string) $nowTs);
            fflush($fp);

            return $this->publishScheduled();
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Permanently purge trashed entries older than `$days` days.
     */
    public function purgeTrash(int $days = 30): int
    {
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify(sprintf('-%d days', max(0, $days)))
            ->format('Y-m-d H:i:s');

        $expired = $this->entries->expiredTrashed($cutoff);
        $count = 0;

        foreach ($expired as $entry) {
            $this->entries->deletePermanent($entry->id);
            $this->hooks->emit('content.deleted', $entry, null);
            $count++;
        }

        return $count;
    }

    /**
     * Re-render `content_html` and `content_text` for all entries (optionally filtered by type).
     */
    public function rerender(?string $type = null): int
    {
        $ids = $this->entries->allIds($type !== '' ? $type : null);
        $count = 0;

        foreach ($ids as $id) {
            $entry = $this->entries->find($id);
            if ($entry === null) {
                continue;
            }
            $doc = $entry->decodedContent();
            $html = $this->renderer->renderHtml($doc, $entry, allowUnfilteredHtml: true);
            $text = $this->renderer->extractText($doc);

            $this->entries->updateRow($entry->id, [
                'content_html' => $html,
                'content_text' => $text,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Compute a unique slug for `(type, parent_id)`, avoiding collisions and reserved root page slugs.
     */
    public function generateUniqueSlug(
        string $type,
        string $desired,
        int $parentId = 0,
        ?int $ignoreId = null,
    ): string {
        $base = Str::slug($desired, '-', 180);
        if ($base === '') {
            $base = 'contenu';
        }

        $reserved = ($type === 'page' && $parentId === 0) ? $this->reservedRootSlugs() : [];
        $candidate = $base;
        $suffix = 2;

        while (
            in_array($candidate, $reserved, true)
            || $this->entries->slugExists($type, $parentId, $candidate, $ignoreId)
        ) {
            $suffixStr = '-' . $suffix;
            $candidate = substr($base, 0, 191 - strlen($suffixStr)) . $suffixStr;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Return all reserved root-level slugs (static base list + registered Router first segments).
     *
     * @return list<string>
     */
    public function reservedRootSlugs(): array
    {
        $reserved = self::BASE_RESERVED_SLUGS;

        if ($this->router !== null) {
            foreach ($this->router->routes() as $route) {
                $pattern = ltrim($route->path(), '/');
                if ($pattern === '') {
                    continue;
                }
                $first = explode('/', $pattern, 2)[0];
                if ($first !== '' && !str_contains($first, '{')) {
                    $slug = strtolower(trim($first));
                    if ($slug !== '' && !in_array($slug, $reserved, true)) {
                        $reserved[] = $slug;
                    }
                }
            }
        }

        $filtered = $this->hooks->apply('content.reserved_slugs', $reserved);
        if (is_array($filtered)) {
            foreach ($filtered as $item) {
                if (is_string($item)) {
                    $slug = strtolower(trim($item));
                    if ($slug !== '' && !in_array($slug, $reserved, true)) {
                        $reserved[] = $slug;
                    }
                }
            }
        }

        return $reserved;
    }

    /**
     * @return array{
     *   title: string,
     *   slug: string,
     *   status: string,
     *   excerpt: ?string,
     *   content: string,
     *   content_html: string,
     *   content_text: string,
     *   author_id: ?int,
     *   parent_id: int,
     *   menu_order: int,
     *   template: ?string,
     *   published_at: ?string,
     *   meta: array<string, mixed>,
     *   term_ids: list<int>,
     *   sync_terms: bool
     * }
     * @param array<string, mixed> $input
     */
    private function preparePayload(
        ContentType $type,
        array $input,
        User $actor,
        ?Entry $existing,
    ): array {
        $errors = [];

        // 1. Title
        $title = is_string($input['title'] ?? null)
            ? trim($input['title'])
            : ($existing !== null ? $existing->title : '');
        if ($title === '') {
            $errors['title'][] = 'Le titre est obligatoire.';
        } elseif (mb_strlen($title) > 255) {
            $errors['title'][] = 'Le titre ne peut pas dépasser 255 caractères.';
        }

        // 2. Status & RBAC enforcement
        $requestedStatus = is_string($input['status'] ?? null) && $input['status'] !== ''
            ? trim($input['status'])
            : ($existing !== null ? $existing->status : Entry::STATUS_DRAFT);

        if (!in_array($requestedStatus, Entry::ALL_STATUSES, true) || $requestedStatus === Entry::STATUS_TRASH) {
            $errors['status'][] = 'Le statut demandé est invalide.';
            $requestedStatus = Entry::STATUS_DRAFT;
        }

        $privilegedStatuses = [Entry::STATUS_PUBLISHED, Entry::STATUS_SCHEDULED, Entry::STATUS_PRIVATE];
        $needsPublishCap = in_array($requestedStatus, $privilegedStatuses, true)
            || ($existing !== null && in_array($existing->status, $privilegedStatuses, true));

        if ($needsPublishCap && !$this->canPublish($actor, $type)) {
            throw new ContentForbiddenException('Vous n\'avez pas la permission de publier, planifier ou modifier ce statut.');
        }

        // 3. Published at & scheduling resolution
        $now = $this->nowUtc();
        $rawPubAt = $input['published_at'] ?? ($existing?->publishedAt);
        $publishedAt = $this->parseDateTimeUtc($rawPubAt);

        if (is_string($rawPubAt) && trim($rawPubAt) !== '' && $publishedAt === null) {
            $errors['published_at'][] = 'La date de publication est invalide.';
        }

        $status = $requestedStatus;
        if ($status === Entry::STATUS_PUBLISHED) {
            if ($publishedAt === null) {
                $publishedAt = $now;
            } elseif ($publishedAt > $now) {
                $status = Entry::STATUS_SCHEDULED;
            }
        } elseif ($status === Entry::STATUS_SCHEDULED) {
            if ($publishedAt === null || $publishedAt <= $now) {
                $publishedAt = $publishedAt ?? $now;
                $status = Entry::STATUS_PUBLISHED;
            }
        } elseif ($status === Entry::STATUS_PRIVATE) {
            if ($publishedAt === null) {
                $publishedAt = $now;
            }
        }

        // 4. Hierarchy (`parent_id`, `menu_order`, `template`)
        $parentId = 0;
        $menuOrder = 0;
        $template = null;

        if ($type->hierarchical || $type->supports('page_attributes')) {
            $rawParent = is_numeric($input['parent_id'] ?? null)
                ? (int) $input['parent_id']
                : ($existing !== null ? $existing->parentId : 0);
            if ($rawParent > 0) {
                if ($existing !== null && $rawParent === $existing->id) {
                    $errors['parent_id'][] = 'Un contenu ne peut pas être son propre parent.';
                } else {
                    $parentEntry = $this->entries->find($rawParent, withRelations: false);
                    if ($parentEntry === null || $parentEntry->type !== $type->name) {
                        $errors['parent_id'][] = 'Le parent sélectionné est introuvable.';
                    } elseif ($existing !== null && $this->isEntryDescendant($type->name, $existing->id, $rawParent)) {
                        $errors['parent_id'][] = 'Impossible de choisir un sous-élément comme parent.';
                    } else {
                        $parentId = $rawParent;
                    }
                }
            }

            $menuOrder = is_numeric($input['menu_order'] ?? null)
                ? (int) $input['menu_order']
                : ($existing !== null ? $existing->menuOrder : 0);
        }

        // Template selection is available on any content type when declared by the active theme
        $rawTemplate = array_key_exists('template', $input)
            ? (is_string($input['template']) ? trim($input['template']) : '')
            : ($existing !== null && $existing->template !== null ? $existing->template : '');
        if ($rawTemplate !== '') {
            if (preg_match('/^[a-z0-9_-]{1,100}$/', $rawTemplate) !== 1) {
                $errors['template'][] = 'Le nom du modèle est invalide.';
            } else {
                $template = $rawTemplate;
            }
        }

        // 5. Author
        $authorId = $existing !== null ? $existing->authorId : $actor->id;
        if (isset($input['author_id']) && is_numeric($input['author_id']) && (int) $input['author_id'] > 0) {
            $requestedAuthor = (int) $input['author_id'];
            if ($requestedAuthor !== $authorId && $actor->can($type->capability('edit_any'))) {
                $authorId = $requestedAuthor;
            }
        }

        // 6. Slug (unique per (type, parent_id), reserved root slugs protected)
        $rawSlug = isset($input['slug']) && is_string($input['slug']) ? trim($input['slug']) : '';
        if ($rawSlug !== '') {
            $normalizedExplicit = strtolower(trim($rawSlug, " /\t\n\r"));
            $slugifiedExplicit = Str::slug($rawSlug, '-', 180);
            $appliesReserved = ($type->name === 'page' && $parentId === 0);
            if (!$appliesReserved && $type->name === 'post') {
                $appliesReserved = (bool) $this->hooks->apply('content.post_uses_root_slug', false);
            }
            if ($appliesReserved) {
                $reserved = $this->reservedRootSlugs();
                if (
                    in_array($normalizedExplicit, $reserved, true)
                    || in_array($slugifiedExplicit, $reserved, true)
                    || (str_starts_with($normalizedExplicit, 'sitemap-') && str_ends_with($normalizedExplicit, '.xml'))
                ) {
                    $errors['slug'][] = sprintf('Le permalien « %s » est réservé par Prime CMS.', $rawSlug);
                }
            }
        }
        $desiredSlug = $rawSlug !== '' ? $rawSlug : ($existing !== null && $existing->slug !== '' ? $existing->slug : $title);
        $slug = $this->generateUniqueSlug($type->name, $desiredSlug, $parentId, $existing?->id);

        // 7. Excerpt
        $excerpt = null;
        if (isset($input['excerpt']) && is_string($input['excerpt'])) {
            $cleanExcerpt = trim($input['excerpt']);
            $excerpt = $cleanExcerpt !== '' ? mb_substr($cleanExcerpt, 0, 2000) : null;
        } elseif ($existing !== null) {
            $excerpt = $existing->excerpt;
        }

        // 8. Prime Editor JSON blocks validation, HTML rendering & plain text extraction
        $rawContent = $input['content'] ?? ($existing !== null ? $existing->content : Renderer::emptyDocument());
        $preservedHtml = $existing !== null ? $this->renderer->extractUnfilteredHtmlBlocks($existing->content) : [];
        $allowUnfiltered = $actor->can('content.unfiltered_html');

        $blockResult = $this->renderer->validateAndNormalize(
            is_string($rawContent) || is_array($rawContent) ? $rawContent : Renderer::emptyDocument(),
            allowUnfilteredHtml: $allowUnfiltered,
            preservedUnfilteredBlocks: $preservedHtml,
        );

        if (!$blockResult['valid']) {
            $errors['content'] = $blockResult['errors'];
        }

        $contentJson = $this->renderer->encode($blockResult['document']);
        $contentHtml = $this->renderer->renderHtml($blockResult['document'], $existing, allowUnfilteredHtml: $allowUnfiltered);
        $contentText = $this->renderer->extractText($blockResult['document']);

        // 9. Custom fields validation
        $meta = [];
        if ($type->supports('custom_fields') && array_key_exists('fields', $input) && is_array($input['fields'])) {
            /** @var array<string, mixed> $fieldsInput */
            $fieldsInput = $input['fields'];
            $fieldValidation = $this->fieldValidator->validate($type->name, $fieldsInput);
            if (!$fieldValidation['valid']) {
                foreach ($fieldValidation['errors'] as $k => $msgs) {
                    $errors[$k] = $msgs;
                }
            }
            $meta = $fieldValidation['values'];
        }

        // 9b. Per-entry SEO metadata (`seo` payload)
        if (array_key_exists('seo', $input) && is_array($input['seo'])) {
            $seoIn = $input['seo'];
            if (array_key_exists('title', $seoIn)) {
                $seoTitle = is_scalar($seoIn['title']) ? trim(strip_tags((string) $seoIn['title'])) : '';
                $meta['seo_title'] = mb_substr($seoTitle, 0, 180);
            }
            if (array_key_exists('description', $seoIn)) {
                $seoDesc = is_scalar($seoIn['description']) ? trim(strip_tags((string) $seoIn['description'])) : '';
                $meta['seo_description'] = mb_substr($seoDesc, 0, 320);
            }
            if (array_key_exists('canonical', $seoIn)) {
                $seoCan = is_scalar($seoIn['canonical']) ? trim((string) $seoIn['canonical']) : '';
                if ($seoCan !== '') {
                    $validCan = str_starts_with($seoCan, '/')
                        || preg_match('#^https?://[^\s<>"\']+$#i', $seoCan) === 1;
                    if (!$validCan || str_starts_with($seoCan, '//')) {
                        $errors['seo_canonical'][] = 'L\'URL canonique doit être une URL http(s):// ou un chemin commençant par "/".';
                    } else {
                        $meta['seo_canonical'] = mb_substr($seoCan, 0, 500);
                    }
                } else {
                    $meta['seo_canonical'] = '';
                }
            }
            $meta['seo_noindex'] = !empty($seoIn['noindex']) && !in_array(strtolower((string) $seoIn['noindex']), ['0', 'false', 'off', ''], true) ? '1' : '0';
            if (array_key_exists('image_id', $seoIn)) {
                $imgId = is_numeric($seoIn['image_id']) && (int) $seoIn['image_id'] > 0 ? (int) $seoIn['image_id'] : '';
                $meta['seo_image_id'] = (string) $imgId;
            }
        }

        // Optional featured image (`featured_media_id`) and legacy `_thumbnail_url` if supported
        if ($type->supports('thumbnail')) {
            if (array_key_exists('featured_media_id', $input)) {
                $rawFeatured = $input['featured_media_id'];
                if ($rawFeatured === null || $rawFeatured === '' || $rawFeatured === 0 || $rawFeatured === '0') {
                    $meta['_featured_media_id'] = null;
                } elseif (is_numeric($rawFeatured) && (int) $rawFeatured > 0) {
                    $featuredId = (int) $rawFeatured;
                    if ($this->media !== null) {
                        $mediaItem = $this->media->find($featuredId);
                        if ($mediaItem === null || !$mediaItem->isImage()) {
                            $errors['featured_media_id'][] = 'L’image à la une sélectionnée est introuvable ou n’est pas une image.';
                        } else {
                            $meta['_featured_media_id'] = $featuredId;
                        }
                    } else {
                        $meta['_featured_media_id'] = $featuredId;
                    }
                } else {
                    $errors['featured_media_id'][] = 'Identifiant d’image à la une invalide.';
                }
            }

            if (isset($input['thumbnail_url']) && is_string($input['thumbnail_url'])) {
                $thumb = trim($input['thumbnail_url']);
                $meta['_thumbnail_url'] = $thumb !== '' ? $thumb : null;
            }
        }

        // 10. Taxonomy terms resolution
        $syncTerms = array_key_exists('terms', $input) || array_key_exists('new_terms', $input) || $existing === null;
        $termIds = [];
        if ($syncTerms) {
            $attachedTaxonomies = $this->taxonomies->forContentType($type->name, $type);
            $termsInput = is_array($input['terms'] ?? null) ? $input['terms'] : [];
            $newTermsInput = is_array($input['new_terms'] ?? null) ? $input['new_terms'] : [];

            foreach ($attachedTaxonomies as $taxName => $taxDef) {
                $selectedForTax = [];
                $rawSelected = $termsInput[$taxName] ?? [];
                if (is_array($rawSelected)) {
                    foreach ($rawSelected as $tid) {
                        if (is_numeric($tid) && (int) $tid > 0) {
                            $termObj = $this->terms->find((int) $tid);
                            if ($termObj !== null && $termObj->taxonomy === $taxName) {
                                $selectedForTax[] = $termObj->id;
                            }
                        }
                    }
                }

                // Comma-separated flat tags input (`new_terms[tag] = "PHP, CMS"`)
                $rawNew = $newTermsInput[$taxName] ?? '';
                if (is_string($rawNew) && trim($rawNew) !== '') {
                    $names = array_values(array_filter(array_map('trim', explode(',', $rawNew)), static fn (string $s): bool => $s !== ''));
                    if ($names !== []) {
                        $createdIds = $this->terms->findOrCreateByNames($taxName, $names);
                        $selectedForTax = [...$selectedForTax, ...$createdIds];
                    }
                }

                // Ensure posts always fall back to the default category if none was selected
                if ($selectedForTax === [] && $taxDef->defaultTermSlug !== null) {
                    $defaultTerm = $this->terms->findBySlug($taxName, $taxDef->defaultTermSlug);
                    if ($defaultTerm !== null) {
                        $selectedForTax[] = $defaultTerm->id;
                    }
                }

                $termIds = [...$termIds, ...$selectedForTax];
            }

            $termIds = array_values(array_unique($termIds));
        }

        if ($errors !== []) {
            throw new ContentValidationException($errors);
        }

        return [
            'title' => $title,
            'slug' => $slug,
            'status' => $status,
            'excerpt' => $excerpt,
            'content' => $contentJson,
            'content_html' => $contentHtml,
            'content_text' => $contentText,
            'author_id' => $authorId,
            'parent_id' => $parentId,
            'menu_order' => $menuOrder,
            'template' => $template,
            'published_at' => $publishedAt,
            'meta' => $meta,
            'term_ids' => $termIds,
            'sync_terms' => $syncTerms,
        ];
    }

    private function requireType(string $typeName): ContentType
    {
        $type = $this->types->get($typeName);
        if ($type === null) {
            throw new ContentException(sprintf('Type de contenu inconnu : "%s".', $typeName));
        }

        return $type;
    }

    private function parseDateTimeUtc(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        if (!is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        $normalized = str_replace('T', ' ', $trimmed);
        $tz = new DateTimeZone('UTC');

        foreach (['!Y-m-d H:i:s', '!Y-m-d H:i', '!Y-m-d'] as $fmt) {
            $dt = DateTimeImmutable::createFromFormat($fmt, $normalized, $tz);
            if ($dt !== false) {
                return $dt->format('Y-m-d H:i:s');
            }
        }

        return null;
    }

    private function isEntryDescendant(string $type, int $ancestorId, int $candidateId): bool
    {
        $tree = $this->entries->hierarchicalTree($type);
        $parentMap = [];
        foreach ($tree as $item) {
            $parentMap[$item['entry']->id] = $item['entry']->parentId;
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

    private function syncEntryMediaUsage(Entry $entry): void
    {
        if ($this->mediaUsage === null) {
            return;
        }

        try {
            $this->mediaUsage->syncFromEntry($entry, $this->fields);
        } catch (Throwable) {
            // Gracefully ignore when media tables are absent in minimal content unit harnesses.
        }
    }

    private function nowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
