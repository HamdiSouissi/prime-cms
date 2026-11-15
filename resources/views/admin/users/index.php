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
        'label' => 'Gérer les rôles',
        'variant' => 'secondary',
        'icon' => 'shield',
        'href' => '/admin/roles',
    ]);
}
if ($canCreate) {
    $headerActions .= $this->insert('admin::components/button', [
        'label' => 'Ajouter un utilisateur',
        'variant' => 'primary',
        'icon' => 'plus',
        'href' => '/admin/users/create',
    ]);
}
?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => 'Utilisateurs',
    'subtitle' => sprintf('%d compte%s enregistré%s sur le site.', $pagination['total'], $pagination['total'] > 1 ? 's' : '', $pagination['total'] > 1 ? 's' : ''),
    'breadcrumbs' => $breadcrumbs,
    'actions' => $headerActions,
])) ?>

<div class="pc-stack pc-stack--lg">
    <!-- Filter & search bar -->
    <form method="get" action="/admin/users" class="pc-filter-bar" role="search" aria-label="Filtrer les utilisateurs">
        <div class="pc-filter-bar__search">
            <label class="pc-field__label" for="users-search">Rechercher</label>
            <input
                type="search"
                id="users-search"
                name="search"
                class="pc-input"
                value="<?= $this->e($filters['search']) ?>"
                placeholder="Nom affiché ou adresse e-mail…"
            >
        </div>

        <div class="pc-filter-bar__select">
            <label class="pc-field__label" for="users-filter-role">Rôle</label>
            <select id="users-filter-role" name="role" class="pc-select">
                <option value="">Tous les rôles</option>
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
                <option value="">Tous les statuts</option>
                <option value="active"<?= $filters['status'] === 'active' ? ' selected' : '' ?>>Actif</option>
                <option value="disabled"<?= $filters['status'] === 'disabled' ? ' selected' : '' ?>>Désactivé</option>
            </select>
        </div>

        <div class="pc-filter-bar__actions">
            <button type="submit" class="pc-btn pc-btn--secondary">Filtrer</button>
            <?php if ($hasFilters): ?>
                <a href="/admin/users" class="pc-btn pc-btn--ghost">Réinitialiser</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($items === []): ?>
        <?= $this->raw($this->insert('admin::components/card', [
            'body' => $this->insert('admin::components/empty-state', [
                'icon' => 'users',
                'title' => $hasFilters ? 'Aucun utilisateur ne correspond à ces critères' : 'Aucun utilisateur',
                'description' => $hasFilters
                    ? 'Essayez d\'élargir votre recherche ou de réinitialiser les filtres actifs.'
                    : 'Commencez par créer un compte utilisateur pour votre équipe.',
                'secondaryLabel' => $hasFilters ? 'Réinitialiser les filtres' : null,
                'secondaryUrl' => $hasFilters ? '/admin/users' : null,
            ]),
        ])) ?>
    <?php else: ?>
        <div class="pc-table-wrap" data-prime-table-bulk>
            <?php if ($canEdit): ?>
            <form method="post" action="/admin/users/bulk" id="pc-users-bulk-form">
                <?= $this->raw($this->csrfField()) ?>
                <div class="pc-table-bulk" data-prime-table-bulk-bar hidden>
                    <span class="pc-table-bulk__count" data-prime-table-bulk-count>0 élément sélectionné</span>
                    <div class="pc-row pc-row--sm">
                        <select name="bulk_action" class="pc-select" aria-label="Action groupée" data-prime-bulk-action-select>
                            <option value="">Choisir une action…</option>
                            <option value="enable">Activer</option>
                            <option value="disable">Désactiver</option>
                            <?php if ($canDelete): ?>
                                <option value="delete">Supprimer</option>
                            <?php endif; ?>
                        </select>
                        <button type="submit" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-bulk-submit>Appliquer</button>
                        <button type="button" class="pc-btn pc-btn--ghost pc-btn--sm" data-prime-table-bulk-clear>Annuler</button>
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
                                <input type="checkbox" class="pc-checkbox" data-prime-table-select-all aria-label="Tout sélectionner">
                            </th>
                            <?php endif; ?>
                            <th scope="col">
                                <a href="<?= $this->e($sortUrl('display_name')) ?>" class="pc-table__sort">Utilisateur</a>
                            </th>
                            <th scope="col">Rôles</th>
                            <th scope="col">
                                <a href="<?= $this->e($sortUrl('status')) ?>" class="pc-table__sort">Statut</a>
                            </th>
                            <th scope="col">2FA</th>
                            <th scope="col">
                                <a href="<?= $this->e($sortUrl('last_login_at')) ?>" class="pc-table__sort">Dernière connexion</a>
                            </th>
                            <th scope="col" class="pc-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $user): ?>
                            <?php
                            $isSelf = $currentUser['id'] === $user->id;
                            $rel = $relativeDates[$user->id] ?? ['label' => 'Jamais connecté', 'title' => ''];
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
                                        aria-label="Sélectionner <?= $this->e($user->displayName) ?>"
                                        <?= $isSelf ? 'disabled' : '' ?>
                                    >
                                </td>
                                <?php endif; ?>

                                <td class="pc-table__primary">
                                    <div class="pc-user-cell">
                                        <?= $this->raw($this->insert('admin::components/avatar', [
                                            'initials' => $user->initials(),
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
                                            <?= $this->raw($this->insert('admin::components/badge', ['label' => 'Sans rôle', 'variant' => 'neutral'])) ?>
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
                                                data-prime-confirm-form="Supprimer définitivement le compte de <?= $this->e($user->displayName) ?> ?"
                                            >
                                                <?= $this->raw($this->csrfField()) ?>
                                                <button type="submit" class="pc-btn pc-btn--ghost pc-btn--sm pc-text-danger">
                                                    Supprimer
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
                    Page <?= $this->e((string) $pagination['page']) ?> sur <?= $this->e((string) $pagination['totalPages']) ?>
                    (<?= $this->e((string) $pagination['total']) ?> résultats)
                </span>
                <nav class="pc-pagination__nav" aria-label="Pagination des utilisateurs">
                    <?php if ($pagination['page'] > 1): ?>
                        <a href="<?= $this->e($buildQuery(['page' => $pagination['page'] - 1])) ?>" class="pc-btn pc-btn--secondary pc-btn--sm">
                            ← Précédent
                        </a>
                    <?php endif; ?>
                    <?php if ($pagination['page'] < $pagination['totalPages']): ?>
                        <a href="<?= $this->e($buildQuery(['page' => $pagination['page'] + 1])) ?>" class="pc-btn pc-btn--secondary pc-btn--sm">
                            Suivant →
                        </a>
                    <?php endif; ?>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php $this->stop(); ?>
