<?php

declare(strict_types=1);

/**
 * Users management list (`GET /admin/users`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var array{items: list<\Prime\Auth\User>, total: int, page: int, perPage: int, totalPages: int} $pagination
 * @var list<\Prime\Auth\Role> $allRoles
 * @var array{search: string, role: string, status: string, sort: string, dir: string} $filters
 * @var bool $hasFilters
 * @var array<int, array{label: string, title: string}> $relativeDates
 * @var bool $canCreate
 * @var bool $canEdit
 * @var bool $canDelete
 * @var bool $canManageRoles
 * @var array{id: ?int, name: string} $currentUser
 * @var list<array{label: string, url: ?string}> $breadcrumbs
 */
$this->layout('admin::layouts/app');

$items = $pagination['items'];
$buildQuery = static function (array $overrides) use ($filters): string {
    $params = array_filter(array_merge($filters, $overrides), static fn ($v): bool => $v !== '' && $v !== null);

    return $params === [] ? '/admin/users' : '/admin/users?' . http_build_query($params);
};
$sortUrl = static function (string $col) use ($filters, $buildQuery): string {
    $nextDir = ($filters['sort'] === $col && $filters['dir'] === 'asc') ? 'desc' : 'asc';

    return $buildQuery(['sort' => $col, 'dir' => $nextDir, 'page' => 1]);
};
?>
<?php $this->start('content'); ?>

<?php
$headerActions = '';
if ($canManageRoles) {
    $headerActions .= $this->insert('admin::components/button', [
        'label' => $this->t('admin.view_users_index.gerer_les_roles'),
        'variant' => 'secondary',
        'icon' => 'shield',
        'href' => '/admin/roles',
    ]);
}
if ($canCreate) {
    $headerActions .= $this->insert('admin::components/button', [
        'label' => $this->t('admin.view_users_index.ajouter_un_utilisateur'),
        'variant' => 'primary',
        'icon' => 'plus',
        'href' => '/admin/users/create',
    ]);
}
?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => $this->t('admin.shared.utilisateurs'),
    'subtitle' => sprintf($this->t('admin.view_users_index.d_compte_s_enregistre_s_sur_le_site'), $pagination['total'], $pagination['total'] > 1 ? 's' : '', $pagination['total'] > 1 ? 's' : ''),
    'breadcrumbs' => $breadcrumbs,
    'actions' => $headerActions,
])) ?>

<div class="pc-stack pc-stack--lg">
    <!-- Filter & search bar -->
    <form method="get" action="/admin/users" class="pc-filter-bar" role="search" aria-label="<?= t('admin.attr.filtrer_les_utilisateurs') ?>">
        <div class="pc-filter-bar__search">
            <label class="pc-field__label" for="users-search"><?= $this->e($this->t('core.actions.search')) ?></label>
            <input
                type="search"
                id="users-search"
                name="search"
                class="pc-input"
                value="<?= $this->e($filters['search']) ?>"
                placeholder="<?= $this->e($this->t('admin.users.index.nom_affiche_adresse_e_mail')) ?>"
            >
        </div>

        <div class="pc-filter-bar__select">
            <label class="pc-field__label" for="users-filter-role"><?= $this->e($this->t('core.fields.role')) ?></label>
            <select id="users-filter-role" name="role" class="pc-select">
                <option value=""><?= $this->e($this->t('admin.users.index.roles')) ?></option>
                <?php foreach ($allRoles as $role): ?>
                    <option value="<?= $this->e($role->slug) ?>"<?= $filters['role'] === $role->slug ? ' selected' : '' ?>>
                        <?= $this->e($role->name) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="pc-filter-bar__select">
            <label class="pc-field__label" for="users-filter-status">Statut</label>
            <select id="users-filter-status" name="status" class="pc-select">
                <option value=""><?= $this->e($this->t('admin.users.index.statuts')) ?></option>
                <option value="active"<?= $filters['status'] === 'active' ? ' selected' : '' ?>>Actif</option>
                <option value="disabled"<?= $filters['status'] === 'disabled' ? ' selected' : '' ?>><?= $this->e($this->t('admin.users.index.desactive')) ?></option>
            </select>
        </div>

        <div class="pc-filter-bar__actions">
            <button type="submit" class="pc-btn pc-btn--secondary">Filtrer</button>
            <?php if ($hasFilters): ?>
                <a href="/admin/users" class="pc-btn pc-btn--ghost"><?= $this->e($this->t('core.actions.reset')) ?></a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($items === []): ?>
        <?= $this->raw($this->insert('admin::components/card', [
            'body' => $this->insert('admin::components/empty-state', [
                'icon' => 'users',
                'title' => $hasFilters ? $this->t('admin.view_users_index.aucun_utilisateur_ne_correspond_a_ces_criteres') : $this->t('admin.view_users_index.aucun_utilisateur'),
                'description' => $hasFilters
                    ? $this->t('admin.view_users_index.essayez_d_elargir_votre_recherche_ou_de_reinitialiser_les_fi')
                    : $this->t('admin.view_users_index.commencez_par_creer_un_compte_utilisateur_pour_votre_equipe'),
                'secondaryLabel' => $hasFilters ? $this->t('admin.view_users_index.reinitialiser_les_filtres') : null,
                'secondaryUrl' => $hasFilters ? '/admin/users' : null,
            ]),
        ])) ?>
    <?php else: ?>
        <div class="pc-table-wrap" data-prime-table-bulk>
            <?php if ($canEdit): ?>
            <form method="post" action="/admin/users/bulk" id="pc-users-bulk-form">
                <?= $this->raw($this->csrfField()) ?>
                <div class="pc-table-bulk" data-prime-table-bulk-bar hidden>
                    <span class="pc-table-bulk__count" data-prime-table-bulk-count><?= $this->e($this->t('admin.users.index.0_element_selectionne')) ?></span>
                    <div class="pc-row pc-row--sm">
                        <select name="bulk_action" class="pc-select" aria-label="<?= t('admin.attr.action_groupee') ?>" data-prime-bulk-action-select>
                            <option value=""><?= $this->e($this->t('admin.users.index.choisir_action')) ?></option>
                            <option value="enable">Activer</option>
                            <option value="disable"><?= $this->e($this->t('admin.users.index.desactiver')) ?></option>
                            <?php if ($canDelete): ?>
                                <option value="delete"><?= $this->e($this->t('core.actions.delete')) ?></option>
                            <?php endif; ?>
                        </select>
                        <button type="submit" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-bulk-submit>Appliquer</button>
                        <button type="button" class="pc-btn pc-btn--ghost pc-btn--sm" data-prime-table-bulk-clear><?= $this->e($this->t('core.actions.cancel')) ?></button>
                    </div>
                </div>
            </form>
            <?php endif; ?>

            <div class="pc-table-scroll">
                <table class="pc-table pc-table--stacked">
                    <thead>
                        <tr>
                            <?php if ($canEdit): ?>
                            <th scope="col" class="pc-table__check">
                                <input type="checkbox" class="pc-checkbox" data-prime-table-select-all aria-label="<?= t('admin.attr.tout_selectionner') ?>">
                            </th>
                            <?php endif; ?>
                            <th scope="col">
                                <a href="<?= $this->e($sortUrl('display_name')) ?>" class="pc-table__sort"><?= $this->e($this->t('admin.users.index.utilisateur')) ?></a>
                            </th>
                            <th scope="col"><?= $this->e($this->t('admin.users.index.roles')) ?></th>
                            <th scope="col">
                                <a href="<?= $this->e($sortUrl('status')) ?>" class="pc-table__sort">Statut</a>
                            </th>
                            <th scope="col">2FA</th>
                            <th scope="col">
                                <a href="<?= $this->e($sortUrl('last_login_at')) ?>" class="pc-table__sort"><?= $this->e($this->t('admin.users.index.derniere_connexion')) ?></a>
                            </th>
                            <th scope="col" class="pc-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $user): ?>
                            <?php
                            $isSelf = $currentUser['id'] === $user->id;
                            $rel = $relativeDates[$user->id] ?? ['label' => $this->t('admin.view_users_index.jamais_connecte'), 'title' => ''];
                            ?>
                            <tr>
                                <?php if ($canEdit): ?>
                                <td class="pc-table__check">
                                    <input
                                        type="checkbox"
                                        class="pc-checkbox"
                                        name="selected_ids[]"
                                        form="pc-users-bulk-form"
                                        value="<?= $this->e((string) $user->id) ?>"
                                        data-prime-table-select-row
                                        aria-label="<?= $this->e($this->t('admin.users.index.selectionner', ['name' => $user->displayName])) ?>"
                                        <?= $isSelf ? 'disabled' : '' ?>
                                    >
                                </td>
                                <?php endif; ?>

                                <td class="pc-table__primary">
                                    <div class="pc-user-cell">
                                        <?= $this->raw($this->insert('admin::components/avatar', [
                                            'initials' => $user->initials(),
                                            'src' => $avatarUrls[$user->id] ?? null,
                                            'alt' => $user->displayName,
                                            'size' => 'md',
                                            'accent' => $user->avatarAccent(),
                                        ])) ?>
                                        <div class="pc-user-cell__meta">
                                            <?php if ($canEdit): ?>
                                                <a href="/admin/users/<?= $this->e((string) $user->id) ?>/edit" class="pc-user-cell__name">
                                                    <?= $this->e($user->displayName) ?>
                                                    <?php if ($isSelf): ?>
                                                        <span class="pc-badge pc-badge--brand pc-badge--pill">Vous</span>
                                                    <?php endif; ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="pc-user-cell__name"><?= $this->e($user->displayName) ?></span>
                                            <?php endif; ?>
                                            <span class="pc-user-cell__email"><?= $this->e($user->email) ?></span>
                                        </div>
                                    </div>
                                </td>

                                <td data-prime-label="Rôles">
                                    <div class="pc-badges-wrap">
                                        <?php if ($user->roles === []): ?>
                                            <?= $this->raw($this->insert('admin::components/badge', ['label' => $this->t('admin.view_users_index.sans_role'), 'variant' => 'neutral'])) ?>
                                        <?php else: ?>
                                            <?php foreach ($user->roles as $role): ?>
                                                <?= $this->raw($this->insert('admin::components/badge', [
                                                    'label' => $role->name,
                                                    'variant' => $role->isAdministrator() ? 'brand' : 'info',
                                                ])) ?>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td data-prime-label="Statut">
                                    <?= $this->raw($this->insert('admin::components/badge', [
                                        'label' => $user->isActive() ? 'Actif' : 'Désactivé',
                                        'variant' => $user->isActive() ? 'success' : 'neutral',
                                        'dot' => true,
                                    ])) ?>
                                </td>

                                <td data-prime-label="2FA">
                                    <?= $this->raw($this->insert('admin::components/badge', [
                                        'label' => $user->hasTwoFactor() ? 'Activée' : 'Inactive',
                                        'variant' => $user->hasTwoFactor() ? 'teal' : 'neutral',
                                    ])) ?>
                                </td>

                                <td data-prime-label="Dernière connexion">
                                    <span title="<?= $this->e($rel['title']) ?>" data-prime-tooltip="<?= $this->e($rel['title']) ?>">
                                        <?= $this->e($rel['label']) ?>
                                    </span>
                                </td>

                                <td class="pc-table__actions">
                                    <div class="pc-row pc-row--sm">
                                        <?php if ($canEdit): ?>
                                            <a href="/admin/users/<?= $this->e((string) $user->id) ?>/edit" class="pc-btn pc-btn--ghost pc-btn--sm">
                                                Modifier
                                            </a>
                                            <?php if (!$isSelf): ?>
                                                <form method="post" action="/admin/users/<?= $this->e((string) $user->id) ?>/toggle-status" class="pc-inline-form">
                                                    <?= $this->raw($this->csrfField()) ?>
                                                    <button type="submit" class="pc-btn pc-btn--ghost pc-btn--sm">
                                                        <?= $user->isActive() ? 'Désactiver' : 'Activer' ?>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <?php if ($canDelete && !$isSelf): ?>
                                            <form
                                                method="post"
                                                action="/admin/users/<?= $this->e((string) $user->id) ?>/delete"
                                                class="pc-inline-form"
                                                data-prime-confirm-form="<?= $this->e($this->t('admin.users.index.confirmer_suppression_compte', ['name' => $user->displayName])) ?>"
                                            >
                                                <?= $this->raw($this->csrfField()) ?>
                                                <button type="submit" class="pc-btn pc-btn--ghost pc-btn--sm pc-text-danger">
                                                    <?= $this->e($this->t('core.actions.delete')) ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($pagination['totalPages'] > 1): ?>
            <div class="pc-pagination">
                <span>
                    <?= t('admin.users.list.page_of', ['page' => $this->e((string) $pagination['page']), 'total' => $this->e((string) $pagination['totalPages'])]) ?>
                    (<?= $this->e($this->choice('core.pagination.results', (int) $pagination['total'])) ?>)
                </span>
                <nav class="pc-pagination__nav" aria-label="<?= t('admin.attr.pagination_des_utilisateurs') ?>">
                    <?php if ($pagination['page'] > 1): ?>
                        <a href="<?= $this->e($buildQuery(['page' => $pagination['page'] - 1])) ?>" class="pc-btn pc-btn--secondary pc-btn--sm">
                            <?= $this->e($this->t('admin.users.index.precedent')) ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($pagination['page'] < $pagination['totalPages']): ?>
                        <a href="<?= $this->e($buildQuery(['page' => $pagination['page'] + 1])) ?>" class="pc-btn pc-btn--secondary pc-btn--sm">
                            <?= t('admin.users.list.next') ?>
                        </a>
                    <?php endif; ?>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php $this->stop(); ?>
