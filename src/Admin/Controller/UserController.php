<?php

declare(strict_types=1);

namespace Prime\Admin\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Prime\Admin\AdminContext;
use Prime\Admin\TranslatesAdminCopy;
use Prime\Api\Token\ApiTokenService;
use Prime\Auth\Auth;
use Prime\Auth\Exception\PolicyException;
use Prime\Auth\PasswordResetService;
use Prime\Auth\Role;
use Prime\Auth\RoleRepository;
use Prime\Auth\TwoFactorService;
use Prime\Auth\User;
use Prime\Auth\UserGuard;
use Prime\Auth\UserRepository;
use Prime\Database\Connection;
use Prime\Http\Exception\ForbiddenException;
use Prime\Http\Exception\NotFoundException;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Kernel\Application;
use Prime\Media\MediaRepository;
use Prime\Security\PasswordPolicy;
use Prime\Settings\Settings;
use Prime\Validation\Validator;
use Prime\View\View;
use Throwable;

/**
 * Full CRUD and security administration controller for `/admin/users`.
 */
final class UserController
{
    use TranslatesAdminCopy;

    /**
     * @var list<string>
     */
    private const LOCALE_OPTIONS = ['fr', 'en', 'it'];

    public function __construct(
        private readonly Application $app,
        private readonly ApiTokenService $apiTokens,
        private readonly View $view,
        private readonly AdminContext $context,
        private readonly Auth $auth,
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly UserGuard $guard,
        private readonly PasswordResetService $passwordResets,
        private readonly TwoFactorService $twoFactor,
        private readonly Connection $connection,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly ?MediaRepository $media = null,
    ) {
    }

    /**
     * Locale choices offered in the account forms, labelled in the interface language.
     *
     * @return array<string, string>
     */
    private function localeOptions(): array
    {
        $options = [];
        foreach (self::LOCALE_OPTIONS as $code) {
            $options[$code] = \Prime\I18n\LocaleFormatter::fallback()->localeName($code);
        }

        return $options;
    }

    public function index(Request $request): Response
    {
        $page = max(1, (int) $request->query('page', 1));
        $search = trim((string) $request->query('search', ''));
        $roleSlug = trim((string) $request->query('role', ''));
        $status = trim((string) $request->query('status', ''));
        $sort = trim((string) $request->query('sort', 'created_at'));
        $dir = strtolower(trim((string) $request->query('dir', 'desc'))) === 'asc' ? 'asc' : 'desc';

        $pagination = $this->users->paginate(
            page: $page,
            perPage: 20,
            search: $search,
            roleSlug: $roleSlug,
            status: $status,
            sort: $sort,
            direction: $dir,
        );

        $allRoles = $this->roles->all();
        $actor = $this->auth->user($request);

        $relativeDates = [];
        $avatarMediaIds = [];
        foreach ($pagination['items'] as $item) {
            $relativeDates[$item->id] = $this->formatRelativeUtc($item->lastLoginAt);
            if ($item->avatarMediaId !== null) {
                $avatarMediaIds[$item->avatarMediaId] = true;
            }
        }

        $avatarUrls = [];
        if ($avatarMediaIds !== [] && $this->media !== null) {
            try {
                $loadedAvatars = $this->media->findByIds(array_keys($avatarMediaIds));
                foreach ($pagination['items'] as $item) {
                    if ($item->avatarMediaId !== null && isset($loadedAvatars[$item->avatarMediaId]) && $loadedAvatars[$item->avatarMediaId]->isImage()) {
                        $avatarUrls[$item->id] = $loadedAvatars[$item->avatarMediaId]->variantUrl('thumbnail');
                    }
                }
            } catch (Throwable) {
            }
        }

        $data = $this->context->build($request, $this->t('admin.shared.utilisateurs'), [
            'pagination' => $pagination,
            'avatarUrls' => $avatarUrls,
            'allRoles' => $allRoles,
            'filters' => [
                'search' => $search,
                'role' => $roleSlug,
                'status' => $status,
                'sort' => $sort,
                'dir' => $dir,
            ],
            'hasFilters' => $search !== '' || $roleSlug !== '' || $status !== '',
            'relativeDates' => $relativeDates,
            'canCreate' => $actor !== null && $actor->can('users.create'),
            'canEdit' => $actor !== null && $actor->can('users.edit'),
            'canDelete' => $actor !== null && $actor->can('users.delete'),
            'canManageRoles' => $actor !== null && $actor->can('roles.manage'),
            'breadcrumbs' => [
                ['label' => $this->t('admin.ctrl_text_user.tableau_de_bord'), 'url' => '/admin'],
                ['label' => $this->t('admin.shared.utilisateurs'), 'url' => null],
            ],
        ]);

        return Response::html($this->view->render('admin::users/index', $data));
    }

    public function create(Request $request): Response
    {
        $actor = $this->requireActor($request);
        $assignableRoles = $this->assignableRolesFor($actor);

        $data = $this->context->build($request, $this->t('admin.ctrl_text_user.ajouter_un_utilisateur'), [
            'assignableRoles' => $assignableRoles,
            'localeOptions' => $this->localeOptions(),
            'old' => [
                'display_name' => '',
                'email' => '',
                'locale' => 'fr',
                'status' => User::STATUS_ACTIVE,
                'creation_mode' => 'password',
                'roles' => [],
            ],
            'errors' => [],
            'breadcrumbs' => [
                ['label' => $this->t('admin.ctrl_text_user.tableau_de_bord'), 'url' => '/admin'],
                ['label' => $this->t('admin.shared.utilisateurs'), 'url' => '/admin/users'],
                ['label' => t('admin.nav.add'), 'url' => null],
            ],
        ]);

        return Response::html($this->view->render('admin::users/create', $data));
    }

    public function store(Request $request): Response
    {
        $actor = $this->requireActor($request);
        $displayName = trim((string) $request->body('display_name', ''));
        $email = UserRepository::normalizeEmail((string) $request->body('email', ''));
        $locale = trim((string) $request->body('locale', 'fr'));
        $status = trim((string) $request->body('status', User::STATUS_ACTIVE));
        $mode = trim((string) $request->body('creation_mode', 'password'));
        $password = (string) $request->body('password', '');
        $passwordConfirmation = (string) $request->body('password_confirmation', '');
        $roleIds = $this->parseRoleIds($request->body('roles', []));

        $rules = [
            'display_name' => 'required|string|between:2,100',
            'email' => 'required|email|unique:users,email',
            'locale' => 'required|in:fr,en,it',
            'status' => 'required|in:active,disabled',
        ];

        if ($mode === 'password') {
            $rules['password'] = 'required|confirmed|password';
        }

        $validator = Validator::make(
            data: [
                'display_name' => $displayName,
                'email' => $email,
                'locale' => $locale,
                'status' => $status,
                'password' => $password,
                'password_confirmation' => $passwordConfirmation,
            ],
            rules: $rules,
            labels: [
                'display_name' => $this->t('admin.ctrl_text_user.nom_affiche'),
                'email' => 'Adresse e-mail',
                'locale' => 'Langue',
                'status' => 'Statut',
                'password' => $this->t('admin.ctrl_text_user.mot_de_passe'),
            ],
            connection: $this->connection,
            passwordPolicy: $this->passwordPolicy,
            passwordContext: [
                'email' => $email,
                'display_name' => $displayName,
                'site_name' => $this->siteName(),
            ],
        );

        $errors = $validator->errors();

        try {
            $this->guard->assertCanAssignRoles($actor, $roleIds);
        } catch (PolicyException $exception) {
            $errors['roles'] = $exception->getMessage();
        }

        if ($errors !== []) {
            $data = $this->context->build($request, $this->t('admin.ctrl_text_user.ajouter_un_utilisateur'), [
                'assignableRoles' => $this->assignableRolesFor($actor),
                'localeOptions' => $this->localeOptions(),
                'old' => [
                    'display_name' => $displayName,
                    'email' => $email,
                    'locale' => $locale,
                    'status' => $status,
                    'creation_mode' => $mode,
                    'roles' => $roleIds,
                ],
                'errors' => $errors,
                'breadcrumbs' => [
                    ['label' => $this->t('admin.ctrl_text_user.tableau_de_bord'), 'url' => '/admin'],
                    ['label' => $this->t('admin.shared.utilisateurs'), 'url' => '/admin/users'],
                    ['label' => t('admin.nav.add'), 'url' => null],
                ],
            ]);

            return Response::html($this->view->render('admin::users/create', $data), 422);
        }

        // For invite mode, store a high-entropy random password until the user sets their own via the 48h link.
        $initialPassword = $mode === 'invite'
            ? bin2hex(random_bytes(24)) . '!A9'
            : $password;

        $created = $this->users->create(
            email: $email,
            displayName: $displayName,
            plainPassword: $initialPassword,
            roleIds: $roleIds,
            status: $status,
            locale: $locale,
        );

        if ($mode === 'invite') {
            $this->passwordResets->sendAdminResetOrInvite($created, $this->siteUrl(), $this->siteName(), isInvite: true);
            $this->context->flashToast(
                sprintf($this->t('admin.ctrl_text_user.utilisateur_s_cree_et_lien_d_invitation_48h_envoye_par_e_mai'), $created->displayName),
                'success',
            );
        } else {
            $this->context->flashToast(
                sprintf($this->t('admin.ctrl_text_user.utilisateur_s_cree_avec_succes'), $created->displayName),
                'success',
            );
        }

        return Response::redirect('/admin/users', 302);
    }

    public function edit(Request $request, string $id): Response
    {
        $actor = $this->requireActor($request);
        $target = $this->findTargetOr404($id);

        if (!$actor->isAdministrator() && $target->isAdministrator()) {
            throw new ForbiddenException($this->t('admin.ctrl_text_user.seul_un_administrateur_peut_modifier_le_compte_d_un_administ'));
        }

        return Response::html($this->renderEditView($request, $actor, $target, [
            'display_name' => $target->displayName,
            'email' => $target->email,
            'locale' => $target->locale,
            'status' => $target->status,
            'roles' => $target->roleIds(),
        ], [], apiTokens: $this->apiTokens->listByUser($target->id)));
    }

    public function update(Request $request, string $id): Response
    {
        $actor = $this->requireActor($request);
        $target = $this->findTargetOr404($id);

        if (!$actor->isAdministrator() && $target->isAdministrator()) {
            throw new ForbiddenException($this->t('admin.ctrl_text_user.seul_un_administrateur_peut_modifier_le_compte_d_un_administ'));
        }

        $displayName = trim((string) $request->body('display_name', ''));
        $email = UserRepository::normalizeEmail((string) $request->body('email', ''));
        $locale = trim((string) $request->body('locale', 'fr'));
        $status = trim((string) $request->body('status', $target->status));
        $newPassword = (string) $request->body('password', '');
        $passwordConfirmation = (string) $request->body('password_confirmation', '');
        $canManageRoles = $actor->can('users.manage_roles');
        $newRoleIds = $canManageRoles ? $this->parseRoleIds($request->body('roles', [])) : null;

        $rules = [
            'display_name' => 'required|string|between:2,100',
            'email' => 'required|email|unique:users,email,' . $target->id,
            'locale' => 'required|in:fr,en,it',
            'status' => 'required|in:active,disabled',
        ];

        if ($newPassword !== '') {
            $rules['password'] = 'confirmed|password';
        }

        $validator = Validator::make(
            data: [
                'display_name' => $displayName,
                'email' => $email,
                'locale' => $locale,
                'status' => $status,
                'password' => $newPassword,
                'password_confirmation' => $passwordConfirmation,
            ],
            rules: $rules,
            labels: [
                'display_name' => $this->t('admin.ctrl_text_user.nom_affiche'),
                'email' => 'Adresse e-mail',
                'locale' => 'Langue',
                'status' => 'Statut',
                'password' => $this->t('admin.ctrl_text_user.nouveau_mot_de_passe'),
            ],
            connection: $this->connection,
            passwordPolicy: $this->passwordPolicy,
            passwordContext: [
                'email' => $email,
                'display_name' => $displayName,
                'site_name' => $this->siteName(),
            ],
        );

        $errors = $validator->errors();

        try {
            $this->guard->assertCanUpdate($actor, $target, $status, $newRoleIds);
        } catch (PolicyException $exception) {
            $errors['general'] = $exception->getMessage();
        }

        if ($errors !== []) {
            return Response::html($this->renderEditView($request, $actor, $target, [
                'display_name' => $displayName,
                'email' => $email,
                'locale' => $locale,
                'status' => $status,
                'roles' => $newRoleIds ?? $target->roleIds(),
            ], $errors), 422);
        }

        $this->users->updateFields($target->id, [
            'display_name' => $displayName,
            'email' => $email,
            'locale' => $locale,
            'status' => $status,
        ]);

        if ($status === User::STATUS_DISABLED) {
            $this->users->incrementSessionVersionAndRevokeTokens($target->id);
        }

        if ($newRoleIds !== null) {
            $this->users->syncRoles($target->id, $newRoleIds);
        }

        if ($newPassword !== '') {
            $nextVersion = $this->users->updatePassword($target->id, $newPassword);
            if ($actor->id === $target->id) {
                $this->auth->syncCurrentSessionVersion($nextVersion);
            }
        }

        $this->auth->resetCache();
        $this->context->flashToast(sprintf($this->t('admin.ctrl_text_user.le_compte_de_s_a_ete_mis_a_jour'), $displayName), 'success');

        return Response::redirect('/admin/users/' . $target->id . '/edit', 302);
    }

    public function toggleStatus(Request $request, string $id): Response
    {
        $actor = $this->requireActor($request);
        $target = $this->findTargetOr404($id);

        if ($target->isActive()) {
            try {
                $this->guard->assertCanDisable($actor, $target);
            } catch (PolicyException $exception) {
                $this->context->flashToast($exception->getMessage(), 'danger');

                return Response::redirect('/admin/users', 302);
            }

            $this->users->updateFields($target->id, ['status' => User::STATUS_DISABLED]);
            $this->users->incrementSessionVersionAndRevokeTokens($target->id);
            $this->context->flashToast(sprintf($this->t('admin.ctrl_text_user.le_compte_s_a_ete_desactive'), $target->displayName), 'warning');
        } else {
            if (!$actor->isAdministrator() && $target->isAdministrator()) {
                throw new ForbiddenException($this->t('admin.ctrl_text_user.seul_un_administrateur_peut_reactiver_un_administrateur'));
            }
            $this->users->updateFields($target->id, ['status' => User::STATUS_ACTIVE]);
            $this->context->flashToast(sprintf($this->t('admin.ctrl_text_user.le_compte_s_a_ete_reactive'), $target->displayName), 'success');
        }

        return Response::redirect('/admin/users', 302);
    }

    public function delete(Request $request, string $id): Response
    {
        $actor = $this->requireActor($request);
        $target = $this->findTargetOr404($id);

        try {
            $this->guard->assertCanDelete($actor, $target);
        } catch (PolicyException $exception) {
            $this->context->flashToast($exception->getMessage(), 'danger');

            return Response::redirect('/admin/users', 302);
        }

        $this->users->delete($target->id);
        $this->context->flashToast(sprintf($this->t('admin.ctrl_text_user.le_compte_s_a_ete_supprime'), $target->displayName), 'success');

        return Response::redirect('/admin/users', 302);
    }

    public function sendResetLink(Request $request, string $id): Response
    {
        $actor = $this->requireActor($request);
        $target = $this->findTargetOr404($id);

        try {
            $this->guard->assertCanManageSecurity($actor, $target);
        } catch (PolicyException $exception) {
            throw new ForbiddenException($exception->getMessage());
        }

        $this->passwordResets->sendAdminResetOrInvite($target, $this->siteUrl(), $this->siteName(), isInvite: false);
        $this->context->flashToast(
            sprintf($this->t('admin.ctrl_text_user.un_lien_de_reinitialisation_de_mot_de_passe_a_ete_envoye_a_s'), $target->email),
            'success',
        );

        return Response::redirect('/admin/users/' . $target->id . '/edit', 302);
    }

    public function logoutDevices(Request $request, string $id): Response
    {
        $actor = $this->requireActor($request);
        $target = $this->findTargetOr404($id);

        try {
            $this->guard->assertCanManageSecurity($actor, $target);
        } catch (PolicyException $exception) {
            throw new ForbiddenException($exception->getMessage());
        }

        $newVersion = $this->users->incrementSessionVersionAndRevokeTokens($target->id);
        if ($actor->id === $target->id) {
            $this->auth->syncCurrentSessionVersion($newVersion);
        }

        $this->context->flashToast(
            sprintf($this->t('admin.ctrl_text_user.toutes_les_sessions_de_s_ont_ete_revoquees'), $target->displayName),
            'info',
        );

        return Response::redirect('/admin/users/' . $target->id . '/edit', 302);
    }

    public function resetTwoFactor(Request $request, string $id): Response
    {
        $actor = $this->requireActor($request);
        $target = $this->findTargetOr404($id);

        try {
            $this->guard->assertCanManageSecurity($actor, $target);
        } catch (PolicyException $exception) {
            throw new ForbiddenException($exception->getMessage());
        }

        $this->twoFactor->disable($target, $actor->id);
        $this->context->flashToast(
            sprintf($this->t('admin.ctrl_text_user.la_2fa_de_s_a_ete_reinitialisee'), $target->displayName),
            'warning',
        );

        return Response::redirect('/admin/users/' . $target->id . '/edit', 302);
    }

    public function revokeApiToken(Request $request, string $id, string $tokenId): Response
    {
        $actor = $this->requireActor($request);
        $target = $this->findTargetOr404($id);

        try {
            $this->guard->assertCanManageSecurity($actor, $target);
        } catch (PolicyException $exception) {
            throw new ForbiddenException($exception->getMessage());
        }

        $token = $this->apiTokens->findById((int) $tokenId);
        if ($token === null || (int) ($token['user_id'] ?? 0) !== $target->id) {
            throw new NotFoundException('Jeton introuvable.');
        }

        $this->apiTokens->revoke((int) $tokenId);

        $this->context->flashToast($this->t('admin.ctrl_text_profile.jeton_api_revoque'), 'success');

        return Response::redirect('/admin/users/' . $target->id . '/edit', 302);
    }

    public function bulk(Request $request): Response
    {
        $actor = $this->requireActor($request);
        $action = trim((string) $request->body('bulk_action', ''));
        $rawIds = $request->body('selected_ids', []);
        $ids = $this->parseRoleIds($rawIds);

        if ($ids === [] || !in_array($action, ['disable', 'enable', 'delete'], true)) {
            $this->context->flashToast($this->t('admin.ctrl_text_user.selectionnez_au_moins_un_utilisateur_et_une_action_valide'), 'warning');

            return Response::redirect('/admin/users', 302);
        }

        if ($action === 'delete' && !$actor->can('users.delete')) {
            throw new ForbiddenException($this->t('admin.ctrl_text_user.vous_n_avez_pas_la_permission_de_supprimer_des_utilisateurs'));
        }

        $applied = 0;
        $skipped = 0;

        foreach ($ids as $uid) {
            $target = $this->users->find($uid);
            if ($target === null) {
                $skipped++;
                continue;
            }

            try {
                if ($action === 'disable') {
                    $this->guard->assertCanDisable($actor, $target);
                    $this->users->updateFields($target->id, ['status' => User::STATUS_DISABLED]);
                    $this->users->incrementSessionVersionAndRevokeTokens($target->id);
                    $applied++;
                } elseif ($action === 'enable') {
                    if (!$actor->isAdministrator() && $target->isAdministrator()) {
                        throw new PolicyException($this->t('admin.ctrl_text_user.acces_refuse'));
                    }
                    $this->users->updateFields($target->id, ['status' => User::STATUS_ACTIVE]);
                    $applied++;
                } else {
                    $this->guard->assertCanDelete($actor, $target);
                    $this->users->delete($target->id);
                    $applied++;
                }
            } catch (PolicyException) {
                $skipped++;
            }
        }

        $verb = match ($action) {
            'disable' => $this->t('admin.ctrl_text_user.desactive_s'),
            'enable' => $this->t('admin.ctrl_text_user.reactive_s'),
            default => $this->t('admin.ctrl_text_user.supprime_s'),
        };

        if ($applied > 0) {
            $msg = sprintf('%d utilisateur(s) %s.', $applied, $verb);
            if ($skipped > 0) {
                $msg .= sprintf(' %d compte(s) protégé(s) ignoré(s).', $skipped);
            }
            $this->context->flashToast($msg, $skipped > 0 ? 'warning' : 'success');
        } else {
            $this->context->flashToast($this->t('admin.ctrl_text_user.aucun_utilisateur_n_a_pu_etre_modifie_comptes_proteges'), 'danger');
        }

        return Response::redirect('/admin/users', 302);
    }

    /**
     * Format a UTC DATETIME string into a concise French relative label + exact tooltip.
     *
     * @return array{label: string, title: string}
     */
    public function formatRelativeUtc(?string $utcDateTime): array
    {
        if ($utcDateTime === null || $utcDateTime === '') {
            return ['label' => $this->t('admin.ctrl_text_user.jamais_connecte'), 'title' => $this->t('admin.ctrl_text_user.aucune_connexion_enregistree')];
        }

        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utcDateTime, new DateTimeZone('UTC'));
        if ($dt === false) {
            return ['label' => $utcDateTime, 'title' => $utcDateTime . ' UTC'];
        }

        $diff = time() - $dt->getTimestamp();
        $title = $dt->format($this->t('admin.ctrl_text_user.d_m_y_a_h_i')) . ' UTC';

        if ($diff < 60) {
            return ['label' => $this->t('admin.ctrl_text_user.a_l_instant'), 'title' => $title];
        }
        if ($diff < 3600) {
            return ['label' => sprintf($this->t('admin.ctrl_text_user.il_y_a_d_min'), intdiv($diff, 60)), 'title' => $title];
        }
        if ($diff < 86400) {
            return ['label' => sprintf($this->t('admin.ctrl_text_user.il_y_a_d_h'), intdiv($diff, 3600)), 'title' => $title];
        }
        if ($diff < 2592000) {
            return ['label' => sprintf($this->t('admin.ctrl_text_user.il_y_a_d_j'), intdiv($diff, 86400)), 'title' => $title];
        }

        return ['label' => $dt->format('d/m/Y'), 'title' => $title];
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, string> $errors
     * @param list<array<string, mixed>> $apiTokens
     */
    private function renderEditView(Request $request, User $actor, User $target, array $old, array $errors, array $apiTokens = []): string
    {
        $canManageRoles = $actor->can('users.manage_roles');
        $canDelete = $actor->can('users.delete') && $actor->id !== $target->id;

        $data = $this->context->build($request, t('admin.users.edit_title', ['name' => $target->displayName]), [
            'targetUser' => $target,
            'assignableRoles' => $canManageRoles ? $this->assignableRolesFor($actor) : $this->roles->all(),
            'canManageRoles' => $canManageRoles,
            'canDelete' => $canDelete,
            'isSelf' => $actor->id === $target->id,
            'localeOptions' => $this->localeOptions(),
            'lastLoginMeta' => $this->formatRelativeUtc($target->lastLoginAt),
            'old' => $old,
            'errors' => $errors,
            'apiTokens' => $apiTokens,
            'breadcrumbs' => [
                ['label' => $this->t('admin.ctrl_text_user.tableau_de_bord'), 'url' => '/admin'],
                ['label' => $this->t('admin.shared.utilisateurs'), 'url' => '/admin/users'],
                ['label' => $target->displayName, 'url' => null],
            ],
        ]);

        return $this->view->render('admin::users/edit', $data);
    }

    /**
     * @return list<Role>
     */
    private function assignableRolesFor(User $actor): array
    {
        $all = $this->roles->all();
        if ($actor->isAdministrator()) {
            return $all;
        }

        $allowed = [];
        foreach ($all as $role) {
            if ($role->isAdministrator()) {
                continue;
            }
            $ok = true;
            foreach ($role->capabilities as $cap) {
                if (!$actor->can($cap)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $allowed[] = $role;
            }
        }

        return $allowed;
    }

    /**
     * @return list<int>
     */
    private function parseRoleIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            if (is_string($raw) && ctype_digit($raw)) {
                return [(int) $raw];
            }

            return [];
        }

        $ids = [];
        foreach ($raw as $val) {
            if (is_int($val) && $val > 0) {
                $ids[] = $val;
            } elseif (is_string($val) && ctype_digit($val) && (int) $val > 0) {
                $ids[] = (int) $val;
            }
        }

        return array_values(array_unique($ids));
    }

    private function findTargetOr404(string $id): User
    {
        if (!ctype_digit($id) || (int) $id < 1) {
            throw new NotFoundException('Utilisateur introuvable.');
        }

        $user = $this->users->find((int) $id);
        if ($user === null) {
            throw new NotFoundException('Utilisateur introuvable.');
        }

        return $user;
    }

    private function requireActor(Request $request): User
    {
        $actor = $this->auth->user($request);
        if ($actor === null) {
            throw new ForbiddenException($this->t('admin.ctrl_text_user.session_expiree'));
        }

        return $actor;
    }

    private function siteName(): string
    {
        try {
            if ($this->app->container()->has(Settings::class)) {
                /** @var Settings $settings */
                $settings = $this->app->get(Settings::class);
                $name = $settings->get('site_name');
                if (is_string($name) && trim($name) !== '') {
                    return trim($name);
                }
            }
        } catch (Throwable) {
        }

        return (string) $this->app->config()->get('app.name', 'Prime CMS');
    }

    private function siteUrl(): string
    {
        try {
            if ($this->app->container()->has(Settings::class)) {
                /** @var Settings $settings */
                $settings = $this->app->get(Settings::class);
                $url = $settings->get('site_url');
                if (is_string($url) && trim($url) !== '') {
                    return rtrim(trim($url), '/');
                }
            }
        } catch (Throwable) {
        }

        return rtrim((string) $this->app->config()->get('app.url', 'http://localhost:8080'), '/');
    }
}
