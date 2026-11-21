<?php

declare(strict_types=1);

namespace Prime\Admin\Controller;

use Prime\Admin\AdminContext;
use Prime\Admin\TranslatesAdminCopy;
use Prime\Auth\Auth;
use Prime\Auth\User;
use Prime\Auth\UserRepository;
use Prime\Content\ContentType;
use Prime\Content\ContentTypeRegistry;
use Prime\Content\Entry;
use Prime\Content\EntryLock;
use Prime\Content\EntryLockRepository;
use Prime\Content\EntryQuery;
use Prime\Content\EntryRepository;
use Prime\Content\EntryService;
use Prime\Content\Exception\ContentConflictException;
use Prime\Content\Exception\ContentException;
use Prime\Content\Exception\ContentForbiddenException;
use Prime\Content\Exception\ContentLockedException;
use Prime\Content\Exception\ContentValidationException;
use Prime\Content\Fields\FieldGroup;
use Prime\Content\Fields\FieldRegistry;
use Prime\Content\Revision;
use Prime\Content\RevisionDiff;
use Prime\Content\RevisionRepository;
use Prime\Content\TaxonomyRegistry;
use Prime\Content\TermRepository;
use Prime\Editor\BlockRegistry;
use Prime\Editor\EditorRegistry;
use Prime\Editor\Renderer;
use Prime\Hooks\Hooks;
use Prime\Http\Exception\ForbiddenException;
use Prime\Http\Exception\NotFoundException;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Kernel\Application;
use Prime\Log\Logger;
use Prime\Media\Media;
use Prime\Media\MediaRepository;
use Prime\Media\MediaUsageRepository;
use Prime\Site\Http\SiteController;
use Prime\Site\Seo\SeoSettings;
use Prime\Site\UrlGenerator;
use Prime\Site\Zone\DynamicBlockRegistry;
use Prime\Themes\ThemeManager;
use Prime\View\View;
use Throwable;

/**
 * Dynamic administration controller for all registered content types (`/admin/content/{type}`).
 */
final class ContentController
{
    use TranslatesAdminCopy;

    /**
     * Preview-only CSP extending the baseline policy with whitelisted YouTube/Vimeo iframes and HTTPS images.
     */
    private const PREVIEW_CSP = "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; script-src 'self'; frame-src https://www.youtube-nocookie.com https://player.vimeo.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";

    public function __construct(
        private readonly Application $app,
        private readonly View $view,
        private readonly AdminContext $context,
        private readonly Auth $auth,
        private readonly ContentTypeRegistry $types,
        private readonly TaxonomyRegistry $taxonomies,
        private readonly FieldRegistry $fields,
        private readonly BlockRegistry $blocks,
        private readonly Renderer $renderer,
        private readonly EntryRepository $entries,
        private readonly EntryQuery $query,
        private readonly EntryService $service,
        private readonly TermRepository $terms,
        private readonly RevisionRepository $revisions,
        private readonly EntryLockRepository $locks,
        private readonly UserRepository $users,
        private readonly ?MediaRepository $media = null,
        private readonly ?ThemeManager $themes = null,
        private readonly ?SeoSettings $seoSettings = null,
        private readonly ?SiteController $siteController = null,
        private readonly ?UrlGenerator $urls = null,
        private readonly ?DynamicBlockRegistry $dynamicBlocks = null,
    ) {
    }

    /**
     * List entries for a content type (`GET /admin/content/{type}`).
     */
    public function index(Request $request, string $type): Response
    {
        $contentType = $this->requireContentType($type);
        $user = $this->requireUser($request);

        // Opportunistically publish due scheduled entries on admin access.
        $this->service->maybePublishScheduled();

        $statusParam = trim((string) $request->query('status', 'all'));
        $allowedStatuses = array_merge(['all'], Entry::ALL_STATUSES);
        if (!in_array($statusParam, $allowedStatuses, true)) {
            $statusParam = 'all';
        }

        $qParam = trim((string) $request->query('q', ''));
        $search = $qParam !== '' ? $qParam : trim((string) $request->query('search', ''));
        $authorFilter = $this->optionalInt($request->query('author'));
        $termFilter = trim((string) $request->query('term', ''));
        $monthFilter = trim((string) $request->query('month', ''));
        if ($monthFilter !== '' && preg_match('/^\d{4}-\d{2}$/', $monthFilter) !== 1) {
            $monthFilter = '';
        }
        $sort = trim((string) $request->query('sort', 'updated_at'));
        $direction = strtolower(trim((string) $request->query('dir', 'desc'))) === 'asc' ? 'ASC' : 'DESC';
        $page = max(1, (int) $request->query('page', '1'));
        $perPage = 20;

        $q = $this->query->newQuery()
            ->type($contentType->name)
            ->includeFuture(true)
            ->visibleInAdminFor($user, $contentType);

        if ($statusParam === 'all') {
            $q = $q->anyStatus(includeTrash: false);
        } else {
            $q = $q->status($statusParam);
        }

        if ($search !== '') {
            $q = $q->search($search);
        }
        if ($authorFilter !== null) {
            $q = $q->author($authorFilter);
        }
        if ($monthFilter !== '') {
            $q = $q->month($monthFilter);
        }
        if ($termFilter !== '' && str_contains($termFilter, ':')) {
            [$taxSlug, $termIdStr] = explode(':', $termFilter, 2);
            if ($this->taxonomies->has($taxSlug) && ctype_digit($termIdStr) && (int) $termIdStr > 0) {
                $q = $q->whereTerm($taxSlug, (int) $termIdStr);
            }
        }

        $q = $q->orderBy($sort, $direction);
        $result = $q->paginate($page, $perPage);

        $visibleAuthorId = $user->can($contentType->capability('edit_any')) ? null : $user->id;
        $counts = $this->entries->countByStatus($contentType->name, $visibleAuthorId);
        $availableMonths = $this->entries->availableMonths($contentType->name, $visibleAuthorId);
        $allTotal = ($counts[Entry::STATUS_PUBLISHED] ?? 0)
            + ($counts[Entry::STATUS_DRAFT] ?? 0)
            + ($counts[Entry::STATUS_PENDING] ?? 0)
            + ($counts[Entry::STATUS_SCHEDULED] ?? 0)
            + ($counts[Entry::STATUS_PRIVATE] ?? 0);

        $items = $result['items'];
        $entryIds = array_map(static fn (Entry $e): int => $e->id, $items);

        $authorsMap = $this->loadAuthorsMap();
        $locksMap = $this->locks->activeForEntries($entryIds);

        // Per-row permission flags so UI accurately reflects editability (e.g. contributor pending entries)
        $editableMap = [];
        $deletableMap = [];
        foreach ($items as $item) {
            $editableMap[$item->id] = $this->service->canEdit($user, $item, $contentType);
            $deletableMap[$item->id] = $this->service->canDelete($user, $item, $contentType);
        }

        // Depth map for hierarchical types
        $depths = [];
        if ($contentType->hierarchical && $search === '' && $statusParam !== Entry::STATUS_TRASH) {
            foreach ($this->entries->hierarchicalTree($contentType->name) as $node) {
                $depths[$node['entry']->id] = $node['depth'];
            }
        }

        $associatedTaxonomies = array_values($this->taxonomies->forContentType($contentType->name, $contentType));
        $filterTerms = [];
        foreach ($associatedTaxonomies as $tax) {
            foreach ($this->terms->allForTaxonomy($tax->name) as $term) {
                $filterTerms[] = [
                    'value' => $tax->name . ':' . $term->id,
                    'label' => $tax->label('singular') . ' : ' . $term->name,
                ];
            }
        }

        $canCreate = $this->service->canCreate($user, $contentType);
        $canPublish = $this->service->canPublish($user, $contentType);
        $canDelete = $user->can($contentType->capability('delete_any')) || $user->can($contentType->capability('delete_own'));

        $featuredThumbs = $this->batchLoadFeaturedThumbs($items);

        $html = $this->view->render('admin::content/index', $this->context->build($request, $contentType->label('plural'), [
            'contentType' => $contentType,
            'entries' => $items,
            'featuredThumbs' => $featuredThumbs,
            'total' => $result['total'],
            'page' => $result['page'],
            'perPage' => $result['per_page'],
            'totalPages' => max(1, $result['last_page']),
            'counts' => $counts,
            'allTotal' => $allTotal,
            'filters' => [
                'status' => $statusParam,
                'q' => $search,
                'author' => $authorFilter,
                'term' => $termFilter,
                'month' => $monthFilter,
                'sort' => $sort,
                'dir' => strtolower($direction),
            ],
            'availableMonths' => $availableMonths,
            'authorsMap' => $authorsMap,
            'locksMap' => $locksMap,
            'depths' => $depths,
            'filterTerms' => $filterTerms,
            'associatedTaxonomies' => $associatedTaxonomies,
            'editableMap' => $editableMap,
            'deletableMap' => $deletableMap,
            'currentUserId' => $user->id,
            'canCreate' => $canCreate,
            'canPublish' => $canPublish,
            'canDelete' => $canDelete,
            'canEditOthers' => $user->can($contentType->capability('edit_any')),
            'primaryCtaInContent' => $canCreate,
            'breadcrumbs' => [
                ['label' => $this->t('admin.ctrl_text_content.tableau_de_bord'), 'url' => '/admin'],
                ['label' => $contentType->label('plural'), 'url' => null],
            ],
        ]));

        return Response::html($html);
    }

    /**
     * Show the create entry editor (`GET /admin/content/{type}/create`).
     */
    public function create(Request $request, string $type): Response
    {
        $contentType = $this->requireContentType($type);
        $user = $this->requireUser($request);

        if (!$this->service->canCreate($user, $contentType)) {
            throw new ForbiddenException($this->t('admin.ctrl_text_content.vous_n_avez_pas_la_permission_de_creer_ce_type_de_contenu'));
        }

        return $this->renderEditor($request, $contentType, $user, null, [], []);
    }

    /**
     * Persist a newly created entry (`POST /admin/content/{type}`).
     */
    public function store(Request $request, string $type): Response
    {
        $contentType = $this->requireContentType($type);
        $user = $this->requireUser($request);

        $input = $this->extractFormInput($request, $contentType, $user);

        try {
            $entry = $this->service->create($contentType->name, $input, $user);
            $isPendingSubmission = $entry->status === Entry::STATUS_PENDING && !$this->service->canPublish($user, $contentType);
            $this->context->flashToast(
                $isPendingSubmission
                    ? sprintf($this->t('admin.ctrl_text_content.s_a_ete_soumis_pour_relecture'), $entry->title)
                    : sprintf($this->t('admin.ctrl_text_content.s_a_ete_enregistre'), $entry->title),
                'success',
                $isPendingSubmission ? 'Soumis pour relecture' : $contentType->label('singular') . ' créé',
            );

            return Response::redirect($entry->editUrl());
        } catch (ContentValidationException $exception) {
            $this->context->flashToast($this->t('admin.ctrl_text_content.veuillez_corriger_les_erreurs_signalees_dans_le_formulaire'), 'danger', 'Validation');

            return $this->renderEditor($request, $contentType, $user, null, $input, $this->flattenErrors($exception->errors()), 422);
        } catch (ContentForbiddenException $exception) {
            throw new ForbiddenException($exception->getMessage());
        } catch (ContentException $exception) {
            $this->context->flashToast($exception->getMessage(), 'danger', 'Erreur');

            return $this->renderEditor($request, $contentType, $user, null, $input, [], 422);
        }
    }

    /**
     * Show the edit entry editor (`GET /admin/content/{type}/{id}/edit`).
     */
    public function edit(Request $request, string $type, int $id): Response
    {
        $contentType = $this->requireContentType($type);
        $user = $this->requireUser($request);
        $entry = $this->requireEntry($contentType->name, $id);

        // Contributor viewing their own submitted (`pending`) entry sees a clear read-only notice.
        $isPendingLocked = $entry->authorId === $user->id
            && $entry->status === Entry::STATUS_PENDING
            && !$this->service->canPublish($user, $contentType)
            && !$user->can($contentType->capability('edit_any'));

        if ($isPendingLocked) {
            return $this->renderEditor($request, $contentType, $user, $entry, [], [], 200, true);
        }

        if (!$this->service->canEdit($user, $entry, $contentType)) {
            throw new ForbiddenException($this->t('admin.ctrl_text_content.vous_ne_disposez_pas_des_droits_necessaires_pour_modifier_ce'));
        }

        // Check/acquire cooperative edit lock (120s TTL)
        $result = $this->locks->acquire($entry->id, $user->id, force: false);
        if (!$result['acquired'] && $result['lock']->userId !== $user->id) {
            $holder = $this->users->find($result['lock']->userId);

            return $this->renderLockedScreen($request, $contentType, $entry, $result['lock'], $holder, $user);
        }

        return $this->renderEditor($request, $contentType, $user, $entry, [], []);
    }

    /**
     * Update an existing entry (`POST /admin/content/{type}/{id}`).
     */
    public function update(Request $request, string $type, int $id): Response
    {
        $contentType = $this->requireContentType($type);
        $user = $this->requireUser($request);
        $entry = $this->requireEntry($contentType->name, $id);

        $input = $this->extractFormInput($request, $contentType, $user);

        try {
            $updated = $this->service->update($entry->id, $input, $user);
            $isPendingSubmission = $updated->status === Entry::STATUS_PENDING && !$this->service->canPublish($user, $contentType);
            if ($isPendingSubmission) {
                $this->locks->release($updated->id, $user->id);
            } else {
                $this->locks->acquire($updated->id, $user->id, force: false);
            }
            $this->context->flashToast(
                $isPendingSubmission
                    ? sprintf($this->t('admin.ctrl_text_content.s_a_ete_soumis_pour_relecture_et_ne_peut_plus_etre_modifie_t'), $updated->title)
                    : sprintf($this->t('admin.ctrl_text_content.s_a_ete_mis_a_jour'), $updated->title),
                'success',
                $isPendingSubmission ? 'Soumis pour relecture' : $this->t('admin.ctrl_text_content.modifications_enregistrees'),
            );

            return Response::redirect($updated->editUrl());
        } catch (ContentLockedException $exception) {
            $activeLock = $this->locks->current($entry->id);
            if ($activeLock !== null) {
                $holder = $this->users->find($activeLock->userId);

                return $this->renderLockedScreen($request, $contentType, $entry, $activeLock, $holder, $user);
            }
            $this->context->flashToast($exception->getMessage(), 'warning', $this->t('admin.ctrl_text_content.contenu_verrouille'));

            return Response::redirect($entry->editUrl());
        } catch (ContentValidationException $exception) {
            $this->context->flashToast($this->t('admin.ctrl_text_content.veuillez_corriger_les_erreurs_signalees'), 'danger', 'Validation');

            return $this->renderEditor($request, $contentType, $user, $entry, $input, $this->flattenErrors($exception->errors()), 422);
        } catch (ContentForbiddenException $exception) {
            throw new ForbiddenException($exception->getMessage());
        } catch (ContentException $exception) {
            $this->context->flashToast($exception->getMessage(), 'danger', 'Erreur');

            return $this->renderEditor($request, $contentType, $user, $entry, $input, [], 422);
        }
    }

    /**
     * AJAX autosave revision endpoint (`POST /admin/content/{type}/{id}/autosave`).
     */
    public function autosave(Request $request, string $type, int $id): Response
    {
        $contentType = $this->requireContentType($type);
        $user = $this->requireUser($request);
        $entry = $this->requireEntry($contentType->name, $id);

        $payload = $this->decodeJsonBody($request);
        $title = isset($payload['title']) && is_string($payload['title'])
            ? $payload['title']
            : (string) $request->body('title', $entry->title);
        $contentJson = isset($payload['content_json']) && is_string($payload['content_json'])
            ? $payload['content_json']
            : (string) $request->body('content_json', $entry->content);

        try {
            $revision = $this->service->autosave($entry->id, [
                'title' => $title,
                'content' => $contentJson,
            ], $user);

            return Response::json([
                'ok' => true,
                'revision_id' => $revision->id,
                'saved_at' => $revision->createdAt,
            ]);
        } catch (ContentLockedException $exception) {
            return Response::json(['ok' => false, 'locked' => true, 'message' => $exception->getMessage()], 423);
        } catch (ContentForbiddenException $exception) {
            return Response::json(['ok' => false, 'message' => $exception->getMessage()], 403);
        } catch (ContentException $exception) {
            return Response::json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * AJAX lock heartbeat (`POST /admin/content/{type}/{id}/lock/heartbeat`).
     */
    public function lockHeartbeat(Request $request, string $type, int $id): Response
    {
        $contentType = $this->requireContentType($type);
        $user = $this->requireUser($request);
        $entry = $this->requireEntry($contentType->name, $id);

        if (!$this->service->canEdit($user, $entry, $contentType)) {
            return Response::json(['ok' => false, 'message' => $this->t('admin.ctrl_text_content.acces_refuse')], 403);
        }

        $result = $this->locks->heartbeat($entry->id, $user->id);
        if ($result['status'] !== 'ok') {
            $holderLock = $result['lock'];
            $holderName = $holderLock !== null && $holderLock->userName !== ''
                ? $holderLock->userName
                : 'un autre utilisateur';

            return Response::json([
                'ok' => false,
                'locked_by_other' => true,
                'holder' => $holderName,
            ], 409);
        }

        return Response::json([
            'ok' => true,
            'heartbeat_at' => $result['lock']?->heartbeatAt,
        ]);
    }

    /**
     * Force-takeover an active edit lock (`POST /admin/content/{type}/{id}/lock/takeover`).
     */
    public function lockTakeover(Request $request, string $type, int $id): Response
    {
        $contentType = $this->requireContentType($type);
        $user = $this->requireUser($request);
        $entry = $this->requireEntry($contentType->name, $id);

        if (!$this->service->canEdit($user, $entry, $contentType) || !$this->service->canTakeOverLock($user, $contentType)) {
            throw new ForbiddenException($this->t('admin.ctrl_text_content.vous_ne_pouvez_pas_prendre_le_controle_de_ce_contenu'));
        }

        $this->locks->acquire($entry->id, $user->id, force: true);
        $this->context->flashToast($this->t('admin.ctrl_text_content.vous_avez_repris_le_controle_de_l_edition'), 'info', $this->t('admin.ctrl_text_content.verrou_transfere'));

        return Response::redirect($entry->editUrl());
    }

    /**
     * Release the current user's edit lock (`POST /admin/content/{type}/{id}/lock/release`).
     */
    public function lockRelease(Request $request, string $type, int $id): Response
    {
        $contentType = $this->requireContentType($type);
        $user = $this->requireUser($request);
        $entry = $this->requireEntry($contentType->name, $id);

        $this->locks->release($entry->id, $user->id);

        return Response::json(['ok' => true]);
    }

    /**
     * Move an entry to trash (`POST /admin/content/{type}/{id}/trash`).
     */
    public function trash(Request $request, string $type, int $id): Response
    {
        $contentType = $this->requireContentType($type);
        $user = $this->requireUser($request);
        $entry = $this->requireEntry($contentType->name, $id);

        try {
            $this->service->trash($entry->id, $user);
            $this->context->flashToast(sprintf($this->t('admin.ctrl_text_content.s_a_ete_deplace_dans_la_corbeille'), $entry->title), 'info');
        } catch (ContentForbiddenException $exception) {
            throw new ForbiddenException($exception->getMessage());
        } catch (ContentException $exception) {
            $this->context->flashToast($exception->getMessage(), 'danger');
        }

        return Response::redirect($contentType->adminListUrl());
    }

    /**
     * Restore a trashed entry (`POST /admin/content/{type}/{id}/restore`).
     */
    public function restore(Request $request, string $type, int $id): Response
    {
        $contentType = $this->requireContentType($type);
        $user = $this->requireUser($request);
        $entry = $this->requireEntry($contentType->name, $id);

        try {
            $this->service->restore($entry->id, $user);
            $this->context->flashToast(sprintf($this->t('admin.ctrl_text_content.s_a_ete_restaure'), $entry->title), 'success');
        } catch (ContentForbiddenException $exception) {
            throw new ForbiddenException($exception->getMessage());
        } catch (ContentException $exception) {
            $this->context->flashToast($exception->getMessage(), 'danger');
        }

        return Response::redirect($contentType->adminListUrl() . '?status=trash');
    }

    /**
     * Permanently delete an entry (`POST /admin/content/{type}/{id}/delete`).
     */
    public function forceDelete(Request $request, string $type, int $id): Response
    {
        $contentType = $this->requireContentType($type);
        $user = $this->requireUser($request);
        $entry = $this->requireEntry($contentType->name, $id);

        try {
            $this->service->delete($entry->id, $user);
            $this->context->flashToast(sprintf($this->t('admin.ctrl_text_content.s_a_ete_supprime_definitivement'), $entry->title), 'success');
        } catch (ContentForbiddenException $exception) {
            throw new ForbiddenException($exception->getMessage());
        } catch (ContentConflictException $exception) {
            return $this->conflictResponse($request, $exception->getMessage());
        } catch (ContentException $exception) {
            $this->context->flashToast($exception->getMessage(), 'danger');
        }

        return Response::redirect($contentType->adminListUrl() . '?status=trash');
    }

    /**
     * Duplicate an entry into a new draft (`POST /admin/content/{type}/{id}/duplicate`).
     */
    public function duplicate(Request $request, string $type, int $id): Response
    {
        $contentType = $this->requireContentType($type);
        $user = $this->requireUser($request);
        $entry = $this->requireEntry($contentType->name, $id);

        try {
            $copy = $this->service->duplicate($entry->id, $user);
            $this->context->flashToast(sprintf($this->t('admin.ctrl_text_content.copie_s_creee_en_brouillon'), $copy->title), 'success');

            return Response::redirect($copy->editUrl());
        } catch (ContentForbiddenException $exception) {
            throw new ForbiddenException($exception->getMessage());
        } catch (ContentException $exception) {
            $this->context->flashToast($exception->getMessage(), 'danger');

            return Response::redirect($contentType->adminListUrl());
        }
    }

    /**
     * Bulk actions (`POST /admin/content/{type}/bulk`).
     */
    public function bulk(Request $request, string $type): Response
    {
        $contentType = $this->requireContentType($type);
        $user = $this->requireUser($request);

        $action = trim((string) $request->body('bulk_action', ''));
        $rawIds = $request->body('ids', []);
        $ids = [];
        if (is_array($rawIds)) {
            foreach ($rawIds as $rawId) {
                if (is_numeric($rawId) && (int) $rawId > 0) {
                    $ids[] = (int) $rawId;
                }
            }
        }

        if ($ids === [] || $action === '') {
            $this->context->flashToast($this->t('admin.ctrl_text_content.selectionnez_au_moins_un_contenu_et_une_action_groupee'), 'warning');

            return Response::redirect($contentType->adminListUrl());
        }

        // Permanent bulk deletion is strictly reserved for entries already in the trash (HTTP 409).
        if ($action === 'delete') {
            foreach ($ids as $entryId) {
                $entry = $this->entries->find($entryId, withRelations: false);
                if ($entry !== null && $entry->type === $contentType->name && $entry->status !== Entry::STATUS_TRASH) {
                    return $this->conflictResponse(
                        $request,
                        $this->t('admin.ctrl_text_content.la_suppression_definitive_est_reservee_aux_contenus_deja_pla'),
                    );
                }
            }
        }

        $processed = 0;
        $errors = 0;
        foreach ($ids as $entryId) {
            $entry = $this->entries->find($entryId, withRelations: false);
            if ($entry === null || $entry->type !== $contentType->name) {
                continue;
            }

            try {
                match ($action) {
                    'publish' => $this->service->update($entry->id, ['status' => Entry::STATUS_PUBLISHED], $user),
                    'draft' => $this->service->update($entry->id, ['status' => Entry::STATUS_DRAFT], $user),
                    'trash' => $this->service->trash($entry->id, $user),
                    'restore' => $this->service->restore($entry->id, $user),
                    'delete' => $this->service->delete($entry->id, $user),
                    default => throw new ContentException($this->t('admin.ctrl_text_content.action_groupee_inconnue')),
                };
                $processed++;
            } catch (ContentConflictException $exception) {
                return $this->conflictResponse($request, $exception->getMessage());
            } catch (ContentException) {
                $errors++;
            }
        }

        if ($processed > 0) {
            $this->context->flashToast(sprintf($this->t('admin.ctrl_text_content.d_contenu_s_traite_s'), $processed), 'success');
        }
        if ($errors > 0) {
            $this->context->flashToast(sprintf($this->t('admin.ctrl_text_content.d_contenu_s_n_ont_pas_pu_etre_modifies_droits_ou_verrou'), $errors), 'warning');
        }

        return Response::redirect($contentType->adminListUrl());
    }

    /**
     * Empty all trashed items for a content type (`POST /admin/content/{type}/trash/empty`).
     */
    public function emptyTrash(Request $request, string $type): Response
    {
        $contentType = $this->requireContentType($type);
        $user = $this->requireUser($request);

        if (!$user->can($contentType->capability('delete_any'))) {
            throw new ForbiddenException('Permission requise pour vider la corbeille.');
        }

        $trashed = $this->query->newQuery()
            ->type($contentType->name)
            ->status(Entry::STATUS_TRASH)
            ->includeFuture(true)
            ->withRelations(false, false, false)
            ->limit(500)
            ->get();

        $deleted = 0;
        foreach ($trashed as $item) {
            $this->service->delete($item->id, $user);
            $deleted++;
        }

        $this->context->flashToast(sprintf($this->t('admin.ctrl_text_content.d_element_s_supprime_s_definitivement'), $deleted), 'success', $this->t('admin.ctrl_text_content.corbeille_videe'));

        return Response::redirect($contentType->adminListUrl() . '?status=trash');
    }

    /**
     * Standalone clean preview with preview-only CSP (`GET /admin/content/{type}/{id}/preview`).
     */
    public function preview(Request $request, string $type, int $id): Response
    {
        $contentType = $this->requireContentType($type);
        $user = $this->requireUser($request);
        $entry = $this->requireEntry($contentType->name, $id);

        if (!$this->service->canPreview($user, $entry, $contentType)) {
            throw new ForbiddenException($this->t('admin.ctrl_text_content.vous_ne_pouvez_pas_previsualiser_ce_contenu'));
        }

        // Optional revision preview (`?revision=123`)
        $revisionId = $this->optionalInt($request->query('revision'));
        $previewTitle = $entry->title;
        $previewJson = $entry->content;
        $previewRevision = null;

        if ($revisionId !== null) {
            $rev = $this->revisions->find($revisionId);
            if ($rev !== null && $rev->entryId === $entry->id) {
                $previewRevision = $rev;
                $previewTitle = $rev->title;
                $previewJson = $rev->content;
            }
        }

        if ($previewRevision === null && $this->siteController !== null) {
            try {
                return $this->siteController->renderEntryPreview($request, $entry);
            } catch (Throwable) {
                // Fallback to standalone admin preview if site rendering is unavailable
            }
        }

        $author = $entry->authorId !== null ? $this->users->find($entry->authorId) : null;
        $allowUnfiltered = $author !== null && $author->can('content.unfiltered_html');
        $decoded = $this->renderer->decode($previewJson) ?? Renderer::emptyDocument();
        $renderedHtml = $this->renderer->renderHtml($decoded, $entry, allowUnfilteredHtml: $allowUnfiltered);

        $html = $this->view->render('admin::content/preview', [
            'appName' => (string) $this->app->config()->get('app.name', 'Prime CMS'),
            'contentType' => $contentType,
            'entry' => $entry,
            'author' => $author,
            'previewTitle' => $previewTitle,
            'previewRevision' => $previewRevision,
            'renderedHtml' => $renderedHtml,
            'canEdit' => $this->service->canEdit($user, $entry, $contentType),
        ]);

        return Response::html($html)->withHeader('Content-Security-Policy', self::PREVIEW_CSP);
    }

    /**
     * Revisions list & line-by-line diff (`GET /admin/content/{type}/{id}/revisions`).
     */
    public function revisions(Request $request, string $type, int $id): Response
    {
        $contentType = $this->requireContentType($type);
        $user = $this->requireUser($request);
        $entry = $this->requireEntry($contentType->name, $id);

        if (!$this->service->canEdit($user, $entry, $contentType)) {
            throw new ForbiddenException($this->t('admin.ctrl_text_content.vous_ne_pouvez_pas_consulter_les_revisions_de_ce_contenu'));
        }

        $revisions = $this->revisions->forEntry($entry->id, includeAutosaves: true);
        $selectedId = $this->optionalInt($request->query('compare'));
        $selected = null;

        foreach ($revisions as $rev) {
            if ($selectedId !== null && $rev->id === $selectedId) {
                $selected = $rev;
                break;
            }
        }
        if ($selected === null && $revisions !== []) {
            $selected = $revisions[0];
        }

        $titleDiff = null;
        $bodyDiff = null;
        if ($selected instanceof Revision) {
            $titleDiff = RevisionDiff::diffLines($selected->title, $entry->title);
            $oldDoc = $this->renderer->decode($selected->content) ?? Renderer::emptyDocument();
            $newDoc = $this->renderer->decode($entry->content) ?? Renderer::emptyDocument();
            $bodyDiff = RevisionDiff::diffLines(
                $this->renderer->extractText($oldDoc),
                $this->renderer->extractText($newDoc),
            );
        }

        $html = $this->view->render('admin::content/revisions', $this->context->build($request, t('admin.nav.revisions') . ' — ' . $entry->title, [
            'contentType' => $contentType,
            'entry' => $entry,
            'revisions' => $revisions,
            'selected' => $selected,
            'titleDiff' => $titleDiff,
            'bodyDiff' => $bodyDiff,
            'authorsMap' => $this->loadAuthorsMap(),
            'breadcrumbs' => [
                ['label' => $this->t('admin.ctrl_text_content.tableau_de_bord'), 'url' => '/admin'],
                ['label' => $contentType->label('plural'), 'url' => $contentType->adminListUrl()],
                ['label' => $entry->title, 'url' => $entry->editUrl()],
                ['label' => t('admin.nav.revisions'), 'url' => null],
            ],
        ]));

        return Response::html($html);
    }

    /**
     * Restore a historical revision (`POST /admin/content/{type}/{id}/revisions/{revId}/restore`).
     */
    public function restoreRevision(Request $request, string $type, int $id, int $revId): Response
    {
        $contentType = $this->requireContentType($type);
        $user = $this->requireUser($request);
        $entry = $this->requireEntry($contentType->name, $id);

        try {
            $this->service->restoreRevision($entry->id, $revId, $user);
            $this->context->flashToast($this->t('admin.ctrl_text_content.la_revision_selectionnee_a_ete_restauree'), 'success', $this->t('admin.ctrl_text_content.revision_restauree'));

            return Response::redirect($entry->editUrl());
        } catch (ContentForbiddenException $exception) {
            throw new ForbiddenException($exception->getMessage());
        } catch (ContentException $exception) {
            $this->context->flashToast($exception->getMessage(), 'danger');

            return Response::redirect(sprintf('/admin/content/%s/%d/revisions', $contentType->name, $entry->id));
        }
    }

    /**
     * AJAX autocomplete endpoint for `entry` relation custom fields (`GET /admin/content/search-entries`).
     */
    public function searchEntries(Request $request): Response
    {
        $this->requireUser($request);

        $query = trim((string) $request->query('q', ''));
        $rawTypes = trim((string) $request->query('types', ''));

        $allowedTypes = [];
        if ($rawTypes !== '') {
            foreach (explode(',', $rawTypes) as $candidate) {
                $slug = trim($candidate);
                if ($slug !== '' && $this->types->has($slug)) {
                    $allowedTypes[] = $slug;
                }
            }
        }
        if ($allowedTypes === []) {
            $allowedTypes = array_keys($this->types->all());
        }

        $q = $this->query->newQuery()
            ->type($allowedTypes)
            ->anyStatus(includeTrash: false)
            ->includeFuture(true)
            ->withRelations(false, false, false)
            ->orderBy('updated_at', 'DESC')
            ->limit(20);

        if ($query !== '') {
            $q = $q->search($query);
        }

        $items = [];
        foreach ($q->get() as $entry) {
            $ct = $this->types->get($entry->type);
            $items[] = [
                'id' => $entry->id,
                'title' => $entry->title,
                'slug' => $entry->slug,
                'type' => $entry->type,
                'type_label' => $ct !== null ? $ct->label('singular') : $entry->type,
            ];
        }

        return Response::json(['items' => $items]);
    }

    /**
     * Render the shared Create / Edit editor page.
     *
     * @param array<string, mixed> $oldInput
     * @param array<string, string> $errors
     */
    private function renderEditor(
        Request $request,
        ContentType $contentType,
        User $user,
        ?Entry $entry,
        array $oldInput,
        array $errors,
        int $statusCode = 200,
        bool $isPendingLocked = false,
    ): Response {
        $isEdit = $entry !== null;
        $formTitle = is_string($oldInput['title'] ?? null) ? $oldInput['title'] : ($entry !== null ? $entry->title : '');
        $formSlug = is_string($oldInput['slug'] ?? null) ? $oldInput['slug'] : ($entry !== null ? $entry->slug : '');
        $formExcerpt = is_string($oldInput['excerpt'] ?? null) ? $oldInput['excerpt'] : ($entry !== null && $entry->excerpt !== null ? $entry->excerpt : '');
        $formContentJson = is_string($oldInput['content'] ?? null)
            ? $oldInput['content']
            : ($entry !== null ? $entry->content : '{"version":1,"blocks":[{"id":"b0","type":"paragraph","data":{"text":""}}]}');
        $formStatus = is_string($oldInput['status'] ?? null) ? $oldInput['status'] : ($entry !== null ? $entry->status : Entry::STATUS_DRAFT);
        $formPublishedAt = is_string($oldInput['published_at'] ?? null)
            ? $oldInput['published_at']
            : ($entry !== null && $entry->publishedAt !== null ? $entry->publishedAt : '');
        $formAuthorId = isset($oldInput['author_id']) && is_int($oldInput['author_id'])
            ? $oldInput['author_id']
            : ($entry !== null && $entry->authorId !== null ? $entry->authorId : $user->id);
        $formParentId = isset($oldInput['parent_id']) && is_int($oldInput['parent_id'])
            ? $oldInput['parent_id']
            : ($entry !== null ? $entry->parentId : 0);
        $formMenuOrder = isset($oldInput['menu_order']) && is_int($oldInput['menu_order'])
            ? $oldInput['menu_order']
            : ($entry !== null ? $entry->menuOrder : 0);
        $formTemplate = is_string($oldInput['template'] ?? null)
            ? $oldInput['template']
            : ($entry !== null && $entry->template !== null ? $entry->template : '');

        $oldSeo = is_array($oldInput['seo'] ?? null) ? $oldInput['seo'] : [];
        $seoForm = [
            'title' => is_string($oldSeo['title'] ?? null) ? $oldSeo['title'] : (string) ($entry?->meta('seo_title', '') ?? ''),
            'description' => is_string($oldSeo['description'] ?? null) ? $oldSeo['description'] : (string) ($entry?->meta('seo_description', '') ?? ''),
            'canonical' => is_string($oldSeo['canonical'] ?? null) ? $oldSeo['canonical'] : (string) ($entry?->meta('seo_canonical', '') ?? ''),
            'image_id' => array_key_exists('image_id', $oldSeo)
                ? (string) ($oldSeo['image_id'] ?? '')
                : (string) ($entry?->meta('seo_image_id', '') ?? ''),
            'noindex' => array_key_exists('noindex', $oldSeo)
                ? !empty($oldSeo['noindex'])
                : !empty($entry?->meta('seo_noindex', false)),
        ];

        $themeTemplates = $this->themes !== null ? $this->themes->templatesFor($contentType->name) : [];
        $seoSeparator = $this->seoSettings !== null ? $this->seoSettings->separator() : '—';
        $siteName = (string) $this->app->config()->get('app.name', 'Prime CMS');

        // Custom fields metadata values
        $metaValues = $entry !== null ? $entry->meta : [];
        if (isset($oldInput['fields']) && is_array($oldInput['fields'])) {
            $metaValues = array_merge($metaValues, $oldInput['fields']);
        }

        // Resolve existing labels for `entry` relation custom fields & batch-load `media` custom fields + featured image
        $relatedEntryLabels = [];
        $allGroups = $this->fields->forContentType($contentType->name);
        $neededMediaIds = [];

        $featuredMediaId = array_key_exists('featured_media_id', $oldInput)
            ? $this->optionalInt($oldInput['featured_media_id'])
            : ($entry?->featuredMediaId());
        if ($featuredMediaId !== null) {
            $neededMediaIds[$featuredMediaId] = true;
        }

        foreach ($allGroups as $group) {
            foreach ($group->fields as $field) {
                if ($field->type === 'entry') {
                    $rawRel = $metaValues[$field->name] ?? null;
                    if (is_numeric($rawRel) && (int) $rawRel > 0) {
                        $relEntry = $this->entries->find((int) $rawRel, withRelations: false);
                        if ($relEntry !== null) {
                            $relatedEntryLabels[$field->name] = sprintf('#%d — %s', $relEntry->id, $relEntry->title);
                        }
                    }
                } elseif ($field->type === 'media') {
                    foreach (MediaUsageRepository::extractMediaIdsFromFieldValue($metaValues[$field->name] ?? null) as $mId) {
                        $neededMediaIds[$mId] = true;
                    }
                }
            }
        }

        $loadedMediaById = $this->batchLoadMediaMap(array_keys($neededMediaIds));
        $featuredMedia = $featuredMediaId !== null ? ($loadedMediaById[$featuredMediaId] ?? null) : null;
        $fieldMediaItems = [];
        foreach ($allGroups as $group) {
            foreach ($group->fields as $field) {
                if ($field->type !== 'media') {
                    continue;
                }
                $resolvedList = [];
                foreach (MediaUsageRepository::extractMediaIdsFromFieldValue($metaValues[$field->name] ?? null) as $mId) {
                    if (isset($loadedMediaById[$mId])) {
                        $resolvedList[] = $loadedMediaById[$mId]->toApiArray();
                    }
                }
                $fieldMediaItems[$field->name] = $resolvedList;
            }
        }

        $mainFieldGroups = array_values(array_filter(
            $allGroups,
            static fn (FieldGroup $g): bool => $g->location === FieldGroup::LOCATION_MAIN,
        ));
        $sidebarFieldGroups = array_values(array_filter(
            $allGroups,
            static fn (FieldGroup $g): bool => $g->location === FieldGroup::LOCATION_SIDEBAR,
        ));

        // Associated taxonomies & selected terms
        $associatedTaxonomies = array_values($this->taxonomies->forContentType($contentType->name, $contentType));
        $selectedTermIds = $entry !== null ? $entry->termIds() : [];
        if (isset($oldInput['terms']) && is_array($oldInput['terms'])) {
            $selectedTermIds = [];
            foreach ($oldInput['terms'] as $taxList) {
                if (is_array($taxList)) {
                    foreach ($taxList as $tid) {
                        if (is_numeric($tid) && (int) $tid > 0) {
                            $selectedTermIds[] = (int) $tid;
                        }
                    }
                }
            }
        }

        $taxonomyPanels = [];
        foreach ($associatedTaxonomies as $tax) {
            $allTerms = $this->terms->allForTaxonomy($tax->name);
            $flatValue = '';
            if (!$tax->hierarchical) {
                if (isset($oldInput['new_terms'][$tax->name]) && is_string($oldInput['new_terms'][$tax->name])) {
                    $flatValue = $oldInput['new_terms'][$tax->name];
                } else {
                    $flatNames = [];
                    foreach ($allTerms as $term) {
                        if (in_array($term->id, $selectedTermIds, true)) {
                            $flatNames[] = $term->name;
                        }
                    }
                    $flatValue = implode(', ', $flatNames);
                }
            }
            $taxonomyPanels[] = [
                'taxonomy' => $tax,
                'terms' => $allTerms,
                'flatValue' => $flatValue,
            ];
        }

        // Parent options if hierarchical
        $parentOptions = [];
        if ($contentType->hierarchical) {
            $parentOptions = $this->entries->hierarchicalTree($contentType->name, $entry?->id);
        }

        $recentRevisions = $entry !== null
            ? array_slice($this->revisions->forEntry($entry->id, includeAutosaves: true), 0, 5)
            : [];

        $userCapabilities = $user->isAdministrator()
            ? ['*', 'content.unfiltered_html', 'content.publish', 'content.create']
            : $user->capabilities;

        $pluginBlocks = [];
        foreach ($this->blocks->clientDefinitions($user) as $def) {
            if ($def['source'] !== 'core') {
                $pluginBlocks[] = $def;
            }
        }

        $editorConfig = [
            'entryId' => $entry?->id,
            'type' => $contentType->name,
            'capabilities' => $userCapabilities,
            'autosaveUrl' => $entry !== null ? sprintf('/admin/content/%s/%d/autosave', $contentType->name, $entry->id) : '',
            'autosaveIntervalMs' => max(10, (int) $this->app->config()->get('content.autosave_interval_seconds', 30)) * 1000,
            'heartbeatUrl' => $entry !== null ? sprintf('/admin/content/%s/%d/lock/heartbeat', $contentType->name, $entry->id) : '',
            'heartbeatIntervalMs' => max(15, (int) $this->app->config()->get('content.lock_heartbeat_seconds', 30)) * 1000,
            'releaseUrl' => $entry !== null ? sprintf('/admin/content/%s/%d/lock/release', $contentType->name, $entry->id) : '',
            'pluginBlocks' => $pluginBlocks,
            'dynamicBlocks' => $this->dynamicBlocks !== null ? $this->dynamicBlocks->catalog() : [],
        ];

        $urlPreviewPattern = $this->buildUrlPreviewPattern($contentType, $entry, $formParentId, $formPublishedAt);
        $previewSlugSample = $formSlug !== '' ? $formSlug : 'nouveau-contenu';
        $urlPreviewInitial = str_replace('__PRIME_SLUG__', $previewSlugSample, $urlPreviewPattern);

        $editorConfigJson = json_encode(
            $editorConfig,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP,
        );

        [$customEditor, $editorNotice, $formContentJson] = $this->resolveEditor($contentType, $entry, $formContentJson);

        $pageTitle = $isEdit ? 'Modifier : ' . $entry->title : $contentType->label('add_new');
        $contextData = $this->context->build($request, $pageTitle, [
            'contentType' => $contentType,
            'entry' => $entry,
            'isEdit' => $isEdit,
            'customEditor' => $customEditor,
            'editorNotice' => $editorNotice,
            'urlPreviewPattern' => $urlPreviewPattern,
            'urlPreviewInitial' => $urlPreviewInitial,
            'form' => [
                'title' => $formTitle,
                'slug' => $formSlug,
                'excerpt' => $formExcerpt,
                'content_json' => $formContentJson,
                'status' => $formStatus,
                'published_at' => $formPublishedAt,
                'author_id' => $formAuthorId,
                'parent_id' => $formParentId,
                'menu_order' => $formMenuOrder,
                'template' => $formTemplate,
            ],
            'seoForm' => $seoForm,
            'themeTemplates' => $themeTemplates,
            'seoSeparator' => $seoSeparator,
            'siteName' => $siteName,
            'metaValues' => $metaValues,
            'relatedEntryLabels' => $relatedEntryLabels,
            'featuredMediaId' => $featuredMediaId,
            'featuredMediaJson' => $this->encodeJsonArray($featuredMedia !== null ? [$featuredMedia->toApiArray()] : []),
            'fieldMediaItems' => $fieldMediaItems,
            'mainFieldGroups' => $mainFieldGroups,
            'sidebarFieldGroups' => $sidebarFieldGroups,
            'taxonomyPanels' => $taxonomyPanels,
            'selectedTermIds' => $selectedTermIds,
            'parentOptions' => $parentOptions,
            'authorsMap' => $this->loadAuthorsMap(),
            'recentRevisions' => $recentRevisions,
            'errors' => $errors,
            'canPublish' => $this->service->canPublish($user, $contentType),
            'canEditOthers' => $user->can($contentType->capability('edit_any')),
            'canDelete' => $entry !== null && !$isPendingLocked && $this->service->canDelete($user, $entry, $contentType),
            'isPendingLocked' => $isPendingLocked,
            'primaryCtaInContent' => true,
            'editorConfigJson' => is_string($editorConfigJson) ? $editorConfigJson : '{}',
            'breadcrumbs' => [
                ['label' => $this->t('admin.ctrl_text_content.tableau_de_bord'), 'url' => '/admin'],
                ['label' => $contentType->label('plural'), 'url' => $contentType->adminListUrl()],
                ['label' => $isEdit ? $entry->title : 'Créer', 'url' => null],
            ],
        ]);

        // Load plugin editor extension scripts after `admin.js` (so `window.Prime.editor` is ready).
        $extensionScripts = $this->blocks->extensionScripts();
        if ($extensionScripts !== [] && isset($contextData['adminScripts']) && is_array($contextData['adminScripts'])) {
            $contextData['adminScripts'] = array_values(array_unique(array_merge($contextData['adminScripts'], $extensionScripts)));
        }

        if ($customEditor !== null) {
            foreach (['adminScripts' => $customEditor['scripts'], 'adminStyles' => $customEditor['styles']] as $key => $urls) {
                $current = isset($contextData[$key]) && is_array($contextData[$key]) ? $contextData[$key] : [];
                $contextData[$key] = array_values(array_unique(array_merge($current, $urls)));
            }
        }

        $html = $this->view->render('admin::content/edit', $contextData);

        return Response::html($html, $statusCode);
    }

    /**
     * Picks the editor of the edit screen.
     *
     * A content type (or the `content.editor` filter) may name an editor registered by a
     * plugin: its HTML replaces the block canvas. When that editor is not available — the
     * plugin was deactivated or its view failed — the built-in block editor is used. If the
     * stored document holds blocks nobody knows any more, it is shown as one HTML block made
     * of the last rendered HTML, so nothing disappears from the screen.
     *
     * @return array{0: array{name: string, label: string, html: string, scripts: list<string>, styles: list<string>}|null, 1: string|null, 2: string}
     */
    private function resolveEditor(ContentType $contentType, ?Entry $entry, string $contentJson): array
    {
        $hooks = $this->app->container()->has(Hooks::class) ? $this->app->get(Hooks::class) : null;
        $name = $contentType->editor;
        if ($hooks !== null) {
            try {
                $filtered = $hooks->apply('content.editor', $name, $contentType);
                $name = is_string($filtered) && $filtered !== '' ? $filtered : $name;
            } catch (Throwable) {
                // Keep the declared editor.
            }
        }

        $notice = null;
        if ($name !== EditorRegistry::DEFAULT) {
            $definition = $this->app->get(EditorRegistry::class)->get($name);
            if ($definition !== null) {
                $document = json_decode($contentJson, true);
                try {
                    $html = ($definition->render)([
                        'entry' => $entry,
                        'type' => $contentType,
                        'document' => is_array($document) ? $document : ['version' => 1, 'blocks' => []],
                        'contentJson' => $contentJson,
                    ]);

                    return [[
                        'name' => $definition->name,
                        'label' => $definition->label,
                        'html' => $html,
                        'scripts' => $definition->scripts,
                        'styles' => $definition->styles,
                    ], null, $contentJson];
                } catch (Throwable $exception) {
                    $this->app->get(Logger::class)->error('Plugin editor failed to render', [
                        'editor' => $name,
                        'owner' => $definition->owner,
                        'message' => $exception->getMessage(),
                    ]);
                }
            }

            $notice = t('admin.content.editor_unavailable', ['name' => $name]);
        }

        $document = json_decode($contentJson, true);
        $blocks = is_array($document) && is_array($document['blocks'] ?? null) ? $document['blocks'] : [];
        foreach ($blocks as $block) {
            $type = is_array($block) && is_string($block['type'] ?? null) ? $block['type'] : '';
            if ($type !== '' && !$this->blocks->has($type) && $entry !== null) {
                $legacy = (string) json_encode([
                    'version' => 1,
                    'blocks' => [['id' => 'b_legacy', 'type' => 'html', 'data' => ['html' => $entry->contentHtml]]],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                return [null, $notice ?? t('admin.content.editor_unknown_blocks'), $legacy];
            }
        }

        return [null, $notice, $contentJson];
    }

    /**
     * Build an explicit HTTP 409 Conflict response (JSON when requested, crisp 409 HTML otherwise).
     */
    private function conflictResponse(Request $request, string $message): Response
    {
        if (str_contains((string) $request->header('Accept', ''), 'application/json')) {
            return Response::json(['ok' => false, 'message' => $message], 409);
        }

        return Response::html($this->view->render('errors.409', ['message' => $message]), 409);
    }

    /**
     * Render the cooperative edit lock takeover page.
     */
    private function renderLockedScreen(
        Request $request,
        ContentType $contentType,
        Entry $entry,
        EntryLock $lock,
        ?User $holder,
        User $currentUser,
    ): Response {
        $html = $this->view->render('admin::content/locked', $this->context->build($request, $this->t('admin.ctrl_text_content.contenu_verrouille'), [
            'contentType' => $contentType,
            'entry' => $entry,
            'lock' => $lock,
            'holder' => $holder,
            'canTakeOver' => $this->service->canTakeOverLock($currentUser, $contentType),
            'breadcrumbs' => [
                ['label' => $this->t('admin.ctrl_text_content.tableau_de_bord'), 'url' => '/admin'],
                ['label' => $contentType->label('plural'), 'url' => $contentType->adminListUrl()],
                ['label' => $entry->title, 'url' => null],
            ],
        ]));

        return Response::html($html);
    }

    /**
     * Extract normalized input from a POST editor submission aligned with `EntryService::preparePayload()`.
     *
     * @return array<string, mixed>
     */
    private function extractFormInput(Request $request, ContentType $contentType, User $user): array
    {
        $status = trim((string) $request->body('status', Entry::STATUS_DRAFT));
        $publishedAtRaw = trim((string) $request->body('published_at', ''));
        $publishedAt = $publishedAtRaw !== '' ? str_replace('T', ' ', $publishedAtRaw) : null;
        if ($publishedAt !== null && strlen($publishedAt) === 16) {
            $publishedAt .= ':00';
        }

        $parentIdRaw = $request->body('parent_id', '0');
        $parentId = is_numeric($parentIdRaw) && (int) $parentIdRaw > 0 ? (int) $parentIdRaw : 0;

        $authorIdRaw = $request->body('author_id', '');
        $authorId = $user->can($contentType->capability('edit_any')) && is_numeric($authorIdRaw) && (int) $authorIdRaw > 0
            ? (int) $authorIdRaw
            : $user->id;

        $rawTerms = $request->body('terms', []);
        $terms = is_array($rawTerms) ? $rawTerms : [];

        $rawNewTerms = $request->body('new_terms', []);
        $newTerms = is_array($rawNewTerms) ? $rawNewTerms : [];

        // Custom field values
        $rawFields = $request->body('meta', []);
        $fields = is_array($rawFields) ? $rawFields : [];
        foreach ($this->fields->forContentType($contentType->name) as $group) {
            foreach ($group->fields as $field) {
                if ($field->type === 'checkbox' || $field->type === 'toggle') {
                    $fields[$field->name] = isset($fields[$field->name]) && (string) $fields[$field->name] !== '' && (string) $fields[$field->name] !== '0';
                }
            }
        }

        $featuredRaw = $request->body('featured_media_id');

        $templateRaw = trim((string) $request->body('template', ''));

        $payload = [
            'title' => trim((string) $request->body('title', '')),
            'slug' => trim((string) $request->body('slug', '')),
            'excerpt' => trim((string) $request->body('excerpt', '')),
            'content' => (string) $request->body('content_json', ''),
            'status' => $status,
            'published_at' => $publishedAt,
            'author_id' => $authorId,
            'parent_id' => $contentType->hierarchical ? $parentId : 0,
            'menu_order' => max(0, (int) $request->body('menu_order', '0')),
            'template' => $templateRaw !== '' ? $templateRaw : null,
            'terms' => $terms,
            'new_terms' => $newTerms,
            'fields' => $fields,
        ];

        $rawSeo = $request->body('seo');
        if (is_array($rawSeo)) {
            $payload['seo'] = [
                'title' => trim((string) ($rawSeo['title'] ?? '')),
                'description' => trim((string) ($rawSeo['description'] ?? '')),
                'canonical' => trim((string) ($rawSeo['canonical'] ?? '')),
                'image_id' => $this->optionalInt($rawSeo['image_id'] ?? null),
                'noindex' => in_array($rawSeo['noindex'] ?? null, [true, 1, '1', 'true', 'on'], true),
            ];
        } elseif ($request->body('seo_title') !== null || $request->body('seo_description') !== null) {
            $payload['seo'] = [
                'title' => trim((string) $request->body('seo_title', '')),
                'description' => trim((string) $request->body('seo_description', '')),
                'canonical' => trim((string) $request->body('seo_canonical', '')),
                'image_id' => $this->optionalInt($request->body('seo_image_id')),
                'noindex' => $request->body('seo_noindex') === '1',
            ];
        }

        if ($contentType->supports('thumbnail') && $featuredRaw !== null) {
            $payload['featured_media_id'] = $this->optionalInt($featuredRaw);
        }

        return $payload;
    }

    /**
     * Flatten `array<string, list<string>>` validation errors into first-message-per-field map.
     *
     * @param array<string, list<string>> $errors
     * @return array<string, string>
     */
    private function flattenErrors(array $errors): array
    {
        $flat = [];
        foreach ($errors as $key => $messages) {
            if ($messages !== []) {
                $flat[$key] = $messages[0];
            }
        }

        return $flat;
    }

    private function requireContentType(string $slug): ContentType
    {
        $contentType = $this->types->get($slug);
        if ($contentType === null) {
            throw new NotFoundException(sprintf($this->t('admin.ctrl_text_content.type_de_contenu_s_introuvable'), $slug));
        }

        return $contentType;
    }

    private function requireEntry(string $type, int $id): Entry
    {
        $entry = $this->entries->find($id);
        if ($entry === null || $entry->type !== $type) {
            throw new NotFoundException($this->t('admin.ctrl_text_content.contenu_introuvable'));
        }

        return $entry;
    }

    private function requireUser(Request $request): User
    {
        $user = $this->auth->user($request);
        if ($user === null) {
            throw new ForbiddenException('Authentification requise.');
        }

        return $user;
    }

    private function optionalInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonBody(Request $request): array
    {
        $raw = $request->rawBody();
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Load users indexed by ID for display in lists/dropdowns.
     *
     * @return array<int, User>
     */
    private function loadAuthorsMap(): array
    {
        $list = $this->users->paginate(page: 1, perPage: 200)['items'];
        $map = [];
        foreach ($list as $u) {
            $map[$u->id] = $u;
        }

        return $map;
    }

    /**
     * Batch-resolve featured thumbnails (`entry_id => {url, alt}`) with zero N+1 queries.
     *
     * @param list<Entry> $entries
     * @return array<int, array{url: string, alt: string}>
     */
    private function batchLoadFeaturedThumbs(array $entries): array
    {
        if ($this->media === null || $entries === []) {
            return [];
        }

        $mediaIds = [];
        foreach ($entries as $entry) {
            $fid = $entry->featuredMediaId();
            if ($fid !== null) {
                $mediaIds[$fid] = true;
            }
        }
        if ($mediaIds === []) {
            return [];
        }

        $loaded = $this->batchLoadMediaMap(array_keys($mediaIds));
        $thumbs = [];
        foreach ($entries as $entry) {
            $fid = $entry->featuredMediaId();
            if ($fid !== null && isset($loaded[$fid]) && $loaded[$fid]->isImage()) {
                $thumbs[$entry->id] = [
                    'url' => $loaded[$fid]->variantUrl('thumbnail'),
                    'alt' => $loaded[$fid]->alt !== '' ? $loaded[$fid]->alt : $entry->title,
                ];
            }
        }

        return $thumbs;
    }

    /**
     * @param list<int> $ids
     * @return array<int, Media>
     */
    private function batchLoadMediaMap(array $ids): array
    {
        if ($this->media === null || $ids === []) {
            return [];
        }

        try {
            return $this->media->findByIds($ids);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Build a live URL preview template (with `__PRIME_SLUG__` placeholder) using `UrlGenerator`.
     */
    private function buildUrlPreviewPattern(
        ContentType $contentType,
        ?Entry $entry,
        int $parentId,
        string $publishedAt,
    ): string {
        $nowUtc = gmdate('Y-m-d H:i:s');
        $fallbackDate = $entry !== null
            ? ($entry->publishedAt ?? $entry->createdAt)
            : $nowUtc;
        $effectiveDate = trim($publishedAt) !== ''
            ? str_replace('T', ' ', trim($publishedAt))
            : $fallbackDate;

        $probe = new Entry(
            id: $entry !== null ? $entry->id : 0,
            type: $contentType->name,
            status: $entry !== null ? $entry->status : Entry::STATUS_PUBLISHED,
            title: $entry !== null ? $entry->title : '',
            slug: '__PRIME_SLUG__',
            excerpt: null,
            content: '{}',
            contentHtml: '',
            contentText: '',
            authorId: $entry?->authorId,
            parentId: max(0, $parentId),
            menuOrder: 0,
            template: $entry?->template,
            publishedAt: $effectiveDate,
            previousStatus: null,
            trashedAt: null,
            createdAt: $entry !== null ? $entry->createdAt : $nowUtc,
            updatedAt: $entry !== null ? $entry->updatedAt : $nowUtc,
        );

        if ($this->urls !== null) {
            return $this->urls->entryUrl($probe);
        }

        if ($contentType->name === 'page') {
            return '/__PRIME_SLUG__';
        }
        if ($contentType->name === 'post') {
            return '/blog/__PRIME_SLUG__';
        }

        $base = ($contentType->rewriteBase !== null && $contentType->rewriteBase !== '')
            ? trim($contentType->rewriteBase, '/')
            : $contentType->name;

        return '/' . $base . '/__PRIME_SLUG__';
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function encodeJsonArray(array $items): string
    {
        $encoded = json_encode(
            $items,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP,
        );

        return is_string($encoded) ? $encoded : '[]';
    }
}
