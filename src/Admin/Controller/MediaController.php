<?php

declare(strict_types=1);

namespace Prime\Admin\Controller;

use Prime\Admin\AdminContext;
use Prime\Admin\TranslatesAdminCopy;
use Prime\Auth\Auth;
use Prime\Auth\User;
use Prime\Http\Exception\ForbiddenException;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Media\Exception\MediaException;
use Prime\Media\Exception\MediaValidationException;
use Prime\Media\Media;
use Prime\Media\MediaConfig;
use Prime\Media\MediaFolderRepository;
use Prime\Media\MediaRepository;
use Prime\Media\MediaUsageRepository;
use Prime\Media\UploadService;
use Prime\View\View;

/**
 * Admin Media Library controller (`/admin/media`) and CSRF/RBAC-protected JSON API (`/admin/media/api/*`).
 */
final class MediaController
{
    use TranslatesAdminCopy;

    public function __construct(
        private readonly View $view,
        private readonly AdminContext $context,
        private readonly Auth $auth,
        private readonly MediaConfig $config,
        private readonly MediaRepository $media,
        private readonly MediaFolderRepository $folders,
        private readonly MediaUsageRepository $usages,
        private readonly UploadService $uploader,
    ) {
    }

    /**
     * Render the Media Library screen (`GET /admin/media`).
     */
    public function index(Request $request): Response
    {
        $user = $this->requireUser($request);
        if (!$user->can('media.view')) {
            throw new ForbiddenException($this->t('admin.ctrl_text_media.vous_ne_disposez_pas_de_la_permission_d_acceder_a_la_mediath'));
        }

        $folderParam = trim((string) $request->query('folder', ''));
        $unclassified = $folderParam === 'unclassified';
        $folderId = (!$unclassified && ctype_digit($folderParam) && (int) $folderParam > 0) ? (int) $folderParam : null;
        $kind = trim((string) $request->query('kind', ''));
        $month = trim((string) $request->query('month', ''));
        $mine = (string) $request->query('mine', '') === '1';
        $unused = (string) $request->query('unused', '') === '1';
        $q = trim((string) $request->query('q', ''));
        $sort = trim((string) $request->query('sort', 'created_at'));
        $dir = trim((string) $request->query('dir', 'desc'));

        $initialPage = $this->media->paginate([
            'folder_id' => $folderId,
            'unclassified' => $unclassified,
            'kind' => $kind,
            'month' => $month,
            'author_id' => $mine ? $user->id : null,
            'unused' => $unused,
            'q' => $q,
            'sort' => $sort,
            'dir' => $dir,
            'page' => 1,
            'per_page' => 24,
        ]);

        $tree = $this->serializeFolderTree($this->folders->tree());
        $flatFolders = array_map(
            fn (array $row): array => [
                'id' => $row['folder']->id,
                'parent_id' => $row['folder']->parentId,
                'name' => $row['folder']->name,
                'slug' => $row['folder']->slug,
                'media_count' => $row['folder']->mediaCount,
                'depth' => $row['depth'],
            ],
            $this->folders->flatTree(),
        );

        $limit = $this->config->effectiveUploadLimit();
        $allowedExts = array_keys($this->config->allowedExtensionMap());
        sort($allowedExts);

        $bootstrap = [
            'apiBase' => '/admin/media/api',
            'urlPrefix' => $this->config->urlPrefix(),
            'canUpload' => $user->can('media.upload'),
            'canDeleteAny' => $user->can('media.delete_any'),
            'currentUserId' => $user->id,
            'maxUploadBytes' => $limit['bytes'],
            'maxUploadMb' => $limit['megabytes'],
            'phpLimited' => $limit['phpLimited'],
            'allowedExtensions' => $allowedExts,
            'months' => $this->media->availableMonths(),
            'counts' => [
                'all' => $this->media->countAll(),
                'unclassified' => $this->media->countUnclassified(),
            ],
            'folders' => $tree,
            'flatFolders' => $flatFolders,
            'initialFilters' => [
                'folder' => $folderParam,
                'kind' => $kind,
                'month' => $month,
                'mine' => $mine,
                'unused' => $unused,
                'q' => $q,
                'sort' => $sort,
                'dir' => strtolower($dir) === 'asc' ? 'asc' : 'desc',
            ],
            'initialItems' => array_map(fn (Media $m): array => $this->serializeMedia($m, $user), $initialPage['items']),
            'initialTotal' => $initialPage['total'],
            'initialHasMore' => $initialPage['has_more'],
            'openMediaId' => ctype_digit((string) $request->query('open', '')) ? (int) $request->query('open') : null,
        ];

        $bootstrapJson = json_encode($bootstrap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

        $html = $this->view->render('admin::media/index', $this->context->build($request, t('admin.nav.media_library'), [
            'canUpload' => $user->can('media.upload'),
            'canDeleteAny' => $user->can('media.delete_any'),
            'limit' => $limit,
            'allowedExtensions' => $allowedExts,
            'months' => $bootstrap['months'],
            'counts' => $bootstrap['counts'],
            'flatFolders' => $flatFolders,
            'initialItems' => $initialPage['items'],
            'initialTotal' => $initialPage['total'],
            'initialHasMore' => $initialPage['has_more'],
            'initialFilters' => $bootstrap['initialFilters'],
            'bootstrapJson' => is_string($bootstrapJson) ? $bootstrapJson : '{}',
            'primaryCtaInContent' => $user->can('media.upload'),
            'breadcrumbs' => [
                ['label' => $this->t('admin.ctrl_text_media.tableau_de_bord'), 'url' => '/admin'],
                ['label' => t('admin.nav.media_library'), 'url' => null],
            ],
        ]));

        return Response::html($html);
    }

    /**
     * JSON list of media items (`GET /admin/media/api/items`).
     */
    public function apiList(Request $request): Response
    {
        $user = $this->requireUser($request);
        if (!$user->can('media.view')) {
            return Response::json(['error' => $this->t('admin.ctrl_text_media.acces_refuse_a_la_mediatheque')], 403);
        }

        $folderParam = trim((string) $request->query('folder', ''));
        $unclassified = $folderParam === 'unclassified' || (string) $request->query('unclassified', '') === '1';
        $folderId = null;
        if (!$unclassified) {
            $rawFolderId = $folderParam !== '' ? $folderParam : trim((string) $request->query('folder_id', ''));
            if (ctype_digit($rawFolderId) && (int) $rawFolderId > 0) {
                $folderId = (int) $rawFolderId;
            }
        }

        $kindsParam = trim((string) $request->query('kinds', ''));
        $kinds = $kindsParam !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $kindsParam)), fn (string $k): bool => $k !== ''))
            : [];

        $mine = (string) $request->query('mine', '') === '1';
        $unused = (string) $request->query('unused', '') === '1';
        $page = max(1, (int) $request->query('page', '1'));
        $perPage = max(1, min(100, (int) $request->query('per_page', '24')));

        $result = $this->media->paginate([
            'folder_id' => $folderId,
            'unclassified' => $unclassified,
            'kind' => trim((string) $request->query('kind', '')),
            'kinds' => $kinds,
            'month' => trim((string) $request->query('month', '')),
            'author_id' => $mine ? $user->id : null,
            'unused' => $unused,
            'q' => trim((string) $request->query('q', '')),
            'sort' => trim((string) $request->query('sort', 'created_at')),
            'dir' => trim((string) $request->query('dir', 'desc')),
            'page' => $page,
            'per_page' => $perPage,
        ]);

        return Response::json([
            'items' => array_map(fn (Media $m): array => $this->serializeMedia($m, $user), $result['items']),
            'total' => $result['total'],
            'page' => $result['page'],
            'per_page' => $result['per_page'],
            'total_pages' => $result['total_pages'],
            'has_more' => $result['has_more'],
            'counts' => [
                'all' => $this->media->countAll(),
                'unclassified' => $this->media->countUnclassified(),
            ],
            'months' => $this->media->availableMonths(),
        ]);
    }

    /**
     * JSON single media detail including usages (`GET /admin/media/api/items/{id}`).
     */
    public function apiShow(Request $request, int $id): Response
    {
        $user = $this->requireUser($request);
        if (!$user->can('media.view')) {
            return Response::json(['error' => $this->t('admin.ctrl_text_media.acces_refuse')], 403);
        }

        $item = $this->media->find($id);
        if ($item === null) {
            return Response::json(['error' => $this->t('admin.ctrl_text_media.media_introuvable')], 404);
        }

        return Response::json([
            'item' => $this->serializeMedia($item, $user),
            'usages' => $this->usages->findByMedia($item->id),
        ]);
    }

    /**
     * JSON upload endpoint (`POST /admin/media/api/upload`).
     */
    public function apiUpload(Request $request): Response
    {
        $user = $this->requireUser($request);
        if (!$user->can('media.upload')) {
            return Response::json(['error' => $this->t('admin.ctrl_text_media.vous_n_avez_pas_la_permission_de_televerser_des_medias')], 403);
        }

        $file = $request->file('file');
        if (!is_array($file)) {
            return Response::json(['error' => $this->t('admin.ctrl_text_media.aucun_fichier_n_a_ete_envoye')], 422);
        }

        $rawFolder = $request->body('folder_id');
        $folderId = (is_scalar($rawFolder) && ctype_digit(trim((string) $rawFolder)) && (int) $rawFolder > 0)
            ? (int) $rawFolder
            : null;

        try {
            $result = $this->uploader->upload($file, $user, $folderId, [
                'title' => (string) $request->body('title', ''),
                'alt' => (string) $request->body('alt', ''),
            ]);

            $media = $result['media'];
            $duplicate = $result['duplicate'];

            return Response::json([
                'item' => $this->serializeMedia($media, $user),
                'duplicate' => $duplicate !== null ? [
                    'id' => $duplicate->id,
                    'title' => $duplicate->displayTitle(),
                    'original_name' => $duplicate->originalName,
                    'url' => '/admin/media?open=' . $duplicate->id,
                ] : null,
                'warning' => $duplicate !== null
                    ? sprintf($this->t('admin.ctrl_text_media.un_fichier_identique_existe_deja_dans_la_mediatheque_s'), $duplicate->displayTitle())
                    : null,
            ], 201);
        } catch (MediaValidationException $e) {
            return Response::json([
                'error' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (MediaException $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * JSON update metadata, folder, or focal point (`POST /admin/media/api/items/{id}`).
     */
    public function apiUpdate(Request $request, int $id): Response
    {
        $user = $this->requireUser($request);
        $item = $this->media->find($id);
        if ($item === null) {
            return Response::json(['error' => $this->t('admin.ctrl_text_media.media_introuvable')], 404);
        }

        if (!$this->canEditMedia($user, $item)) {
            return Response::json(['error' => $this->t('admin.ctrl_text_media.vous_n_avez_pas_la_permission_de_modifier_ce_media')], 403);
        }

        $body = $request->body();
        $payload = is_array($body) ? $body : [];

        try {
            $updated = $this->uploader->updateMetadata($item, $payload, $user);

            return Response::json([
                'item' => $this->serializeMedia($updated, $user),
                'usages' => $this->usages->findByMedia($updated->id),
            ]);
        } catch (MediaValidationException $e) {
            return Response::json(['error' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (MediaException $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * JSON regenerate GD image variants (`POST /admin/media/api/items/{id}/regenerate`).
     */
    public function apiRegenerate(Request $request, int $id): Response
    {
        $user = $this->requireUser($request);
        $item = $this->media->find($id);
        if ($item === null) {
            return Response::json(['error' => $this->t('admin.ctrl_text_media.media_introuvable')], 404);
        }

        if (!$this->canEditMedia($user, $item)) {
            return Response::json(['error' => $this->t('admin.ctrl_text_media.vous_n_avez_pas_la_permission_de_regenerer_ce_media')], 403);
        }

        try {
            $updated = $this->uploader->regenerate($item, null, $user);

            return Response::json([
                'item' => $this->serializeMedia($updated, $user),
            ]);
        } catch (MediaException $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * JSON replace media file keeping ID and base URL (`POST /admin/media/api/items/{id}/replace`).
     */
    public function apiReplace(Request $request, int $id): Response
    {
        $user = $this->requireUser($request);
        $item = $this->media->find($id);
        if ($item === null) {
            return Response::json(['error' => $this->t('admin.ctrl_text_media.media_introuvable')], 404);
        }

        if (!$this->canEditMedia($user, $item)) {
            return Response::json(['error' => $this->t('admin.ctrl_text_media.vous_n_avez_pas_la_permission_de_remplacer_ce_media')], 403);
        }

        $file = $request->file('file');
        if (!is_array($file)) {
            return Response::json(['error' => $this->t('admin.ctrl_text_media.aucun_fichier_de_remplacement_n_a_ete_envoye')], 422);
        }

        try {
            $updated = $this->uploader->replace($item, $file, $user);

            return Response::json([
                'item' => $this->serializeMedia($updated, $user),
                'usages' => $this->usages->findByMedia($updated->id),
            ]);
        } catch (MediaValidationException $e) {
            return Response::json(['error' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (MediaException $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * JSON delete media item (`POST /admin/media/api/items/{id}/delete`).
     */
    public function apiDelete(Request $request, int $id): Response
    {
        $user = $this->requireUser($request);
        $item = $this->media->find($id);
        if ($item === null) {
            return Response::json(['error' => $this->t('admin.ctrl_text_media.media_introuvable')], 404);
        }

        if (!$this->canDeleteMedia($user, $item)) {
            return Response::json(['error' => $this->t('admin.ctrl_text_media.vous_n_avez_pas_la_permission_de_supprimer_ce_media')], 403);
        }

        $this->uploader->delete($item, $user);

        return Response::json([
            'deleted' => true,
            'id' => $id,
        ]);
    }

    /**
     * JSON bulk move or delete (`POST /admin/media/api/bulk`).
     */
    public function apiBulk(Request $request): Response
    {
        $user = $this->requireUser($request);
        if (!$user->can('media.view')) {
            return Response::json(['error' => $this->t('admin.ctrl_text_media.acces_refuse')], 403);
        }

        $action = trim((string) $request->body('action', ''));
        $rawIds = $request->body('ids', []);
        $ids = [];
        if (is_array($rawIds)) {
            foreach ($rawIds as $v) {
                if (is_numeric($v) && (int) $v > 0) {
                    $ids[] = (int) $v;
                }
            }
        }
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return Response::json(['error' => $this->t('admin.ctrl_text_media.aucun_media_selectionne')], 422);
        }

        $itemsMap = $this->media->findByIds($ids);
        if ($itemsMap === []) {
            return Response::json(['error' => $this->t('admin.ctrl_text_media.aucun_media_correspondant_trouve')], 404);
        }

        if ($action === 'move') {
            foreach ($itemsMap as $item) {
                if (!$this->canEditMedia($user, $item)) {
                    return Response::json(['error' => $this->t('admin.ctrl_text_media.vous_n_avez_pas_la_permission_de_deplacer_certains_medias_se')], 403);
                }
            }

            $rawFolder = $request->body('folder_id');
            $folderId = null;
            if ($rawFolder !== null && $rawFolder !== '' && $rawFolder !== 0 && $rawFolder !== '0') {
                $fid = (int) $rawFolder;
                if ($fid <= 0 || $this->folders->find($fid) === null) {
                    return Response::json(['error' => $this->t('admin.ctrl_text_media.le_dossier_cible_est_introuvable')], 422);
                }
                $folderId = $fid;
            }

            $validIds = array_keys($itemsMap);
            $this->media->moveMany($validIds, $folderId);

            return Response::json([
                'action' => 'move',
                'count' => count($validIds),
                'ids' => $validIds,
                'folder_id' => $folderId,
            ]);
        }

        if ($action === 'delete') {
            foreach ($itemsMap as $item) {
                if (!$this->canDeleteMedia($user, $item)) {
                    return Response::json(['error' => $this->t('admin.ctrl_text_media.vous_n_avez_pas_la_permission_de_supprimer_certains_medias_s')], 403);
                }
            }

            $deletedIds = [];
            foreach ($itemsMap as $item) {
                $this->uploader->delete($item, $user);
                $deletedIds[] = $item->id;
            }

            return Response::json([
                'action' => 'delete',
                'count' => count($deletedIds),
                'ids' => $deletedIds,
            ]);
        }

        return Response::json(['error' => $this->t('admin.ctrl_text_media.action_groupee_non_reconnue')], 422);
    }

    /**
     * JSON folder tree (`GET /admin/media/api/folders`).
     */
    public function apiFolders(Request $request): Response
    {
        $user = $this->requireUser($request);
        if (!$user->can('media.view')) {
            return Response::json(['error' => $this->t('admin.ctrl_text_media.acces_refuse')], 403);
        }

        return Response::json($this->foldersPayload());
    }

    /**
     * JSON create folder (`POST /admin/media/api/folders`).
     */
    public function apiCreateFolder(Request $request): Response
    {
        $user = $this->requireUser($request);
        if (!$user->can('media.upload') && !$user->can('media.delete_any')) {
            return Response::json(['error' => $this->t('admin.ctrl_text_media.vous_n_avez_pas_la_permission_de_creer_un_dossier')], 403);
        }

        $name = trim((string) $request->body('name', ''));
        $rawParent = $request->body('parent_id');
        $parentId = (is_scalar($rawParent) && ctype_digit(trim((string) $rawParent)) && (int) $rawParent > 0)
            ? (int) $rawParent
            : null;

        try {
            $folder = $this->folders->create($name, $parentId);

            return Response::json(array_merge(
                ['folder' => $folder->toApiArray()],
                $this->foldersPayload(),
            ), 201);
        } catch (MediaValidationException $e) {
            return Response::json(['error' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }
    }

    /**
     * JSON rename or move folder (`POST /admin/media/api/folders/{id}`).
     */
    public function apiUpdateFolder(Request $request, int $id): Response
    {
        $user = $this->requireUser($request);
        if (!$user->can('media.upload') && !$user->can('media.delete_any')) {
            return Response::json(['error' => $this->t('admin.ctrl_text_media.vous_n_avez_pas_la_permission_de_modifier_ce_dossier')], 403);
        }

        $body = $request->body();
        $payload = is_array($body) ? $body : [];
        $name = array_key_exists('name', $payload) ? (string) $payload['name'] : null;
        $changeParent = array_key_exists('parent_id', $payload);
        $parentId = null;
        if ($changeParent) {
            $rawParent = $payload['parent_id'];
            if ($rawParent !== null && $rawParent !== '' && $rawParent !== 0 && $rawParent !== '0') {
                $parentId = (int) $rawParent;
            }
        }

        try {
            $folder = $this->folders->update($id, $name, $changeParent, $parentId);

            return Response::json(array_merge(
                ['folder' => $folder->toApiArray()],
                $this->foldersPayload(),
            ));
        } catch (MediaValidationException $e) {
            return Response::json(['error' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }
    }

    /**
     * JSON delete folder reparenting contents and sub-folders to parent (`POST /admin/media/api/folders/{id}/delete`).
     */
    public function apiDeleteFolder(Request $request, int $id): Response
    {
        $user = $this->requireUser($request);
        if (!$user->can('media.upload') && !$user->can('media.delete_any')) {
            return Response::json(['error' => $this->t('admin.ctrl_text_media.vous_n_avez_pas_la_permission_de_supprimer_ce_dossier')], 403);
        }

        $folder = $this->folders->find($id);
        if ($folder === null) {
            return Response::json(['error' => $this->t('admin.ctrl_text_media.dossier_introuvable')], 404);
        }

        // Non-delete_any users may only delete folders that do not contain other users' files
        if (!$user->can('media.delete_any')) {
            $inFolder = $this->media->paginate(['folder_id' => $id, 'page' => 1, 'per_page' => 100])['items'];
            foreach ($inFolder as $m) {
                if ($m->authorId !== $user->id) {
                    return Response::json(['error' => $this->t('admin.ctrl_text_media.ce_dossier_contient_des_medias_appartenant_a_d_autres_utilis')], 403);
                }
            }
        }

        $this->folders->delete($id);

        return Response::json(array_merge(
            ['deleted' => true, 'id' => $id],
            $this->foldersPayload(),
        ));
    }

    private function canEditMedia(User $user, Media $media): bool
    {
        if ($user->can('media.delete_any')) {
            return true;
        }

        return $user->can('media.upload') && $media->authorId !== null && $media->authorId === $user->id;
    }

    private function canDeleteMedia(User $user, Media $media): bool
    {
        return $this->canEditMedia($user, $media);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMedia(Media $media, User $user): array
    {
        $data = $media->toApiArray($this->config->urlPrefix());
        $data['can_edit'] = $this->canEditMedia($user, $media);
        $data['can_delete'] = $this->canDeleteMedia($user, $media);

        return $data;
    }

    /**
     * @return array{folders: list<array<string, mixed>>, flat_folders: list<array<string, mixed>>, counts: array{all: int, unclassified: int}}
     */
    private function foldersPayload(): array
    {
        $tree = $this->serializeFolderTree($this->folders->tree());
        $flat = array_map(
            fn (array $row): array => [
                'id' => $row['folder']->id,
                'parent_id' => $row['folder']->parentId,
                'name' => $row['folder']->name,
                'slug' => $row['folder']->slug,
                'media_count' => $row['folder']->mediaCount,
                'depth' => $row['depth'],
            ],
            $this->folders->flatTree(),
        );

        return [
            'folders' => $tree,
            'flat_folders' => $flat,
            'counts' => [
                'all' => $this->media->countAll(),
                'unclassified' => $this->media->countUnclassified(),
            ],
        ];
    }

    /**
     * @param list<array{folder: \Prime\Media\MediaFolder, depth: int, children: list<mixed>}> $nodes
     * @return list<array<string, mixed>>
     */
    private function serializeFolderTree(array $nodes): array
    {
        $result = [];
        foreach ($nodes as $node) {
            /** @var list<array{folder: \Prime\Media\MediaFolder, depth: int, children: list<mixed>}> $children */
            $children = $node['children'];
            $result[] = [
                'id' => $node['folder']->id,
                'parent_id' => $node['folder']->parentId,
                'name' => $node['folder']->name,
                'slug' => $node['folder']->slug,
                'media_count' => $node['folder']->mediaCount,
                'depth' => $node['depth'],
                'children' => $this->serializeFolderTree($children),
            ];
        }

        return $result;
    }

    private function requireUser(Request $request): User
    {
        $user = $this->auth->user($request);
        if ($user === null) {
            throw new ForbiddenException('Authentification requise.');
        }

        return $user;
    }
}
