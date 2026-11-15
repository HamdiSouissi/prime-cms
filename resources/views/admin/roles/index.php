<?php

declare(strict_types=1);

/**
 * Roles listing view (`GET /admin/roles`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var list<\Prime\Auth\Role> $roles
 * @var list<array{label: string, url: ?string}> $breadcrumbs
 */
$this->layout('admin::layouts/app');
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => 'Rôles et permissions',
    'subtitle' => 'Définissez les groupes de permissions attribuables aux utilisateurs du site.',
    'breadcrumbs' => $breadcrumbs,
    'actions' => $this->insert('admin::components/button', [
        'label' => 'Créer un rôle',
        'variant' => 'primary',
        'icon' => 'plus',
        'href' => '/admin/roles/create',
    ]),
])) ?>

<div class="pc-card">
    <div class="pc-table-wrap">
        <table class="pc-table">
            <thead>
                <tr>
                    <th scope="col">Rôle</th>
                    <th scope="col">Slug</th>
                    <th scope="col">Permissions</th>
                    <th scope="col">Utilisateurs</th>
                    <th scope="col" class="pc-table__actions-col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($roles as $role): ?>
                    <tr>
                        <td>
                            <div class="pc-stack pc-stack--xs">
                                <div class="pc-row pc-row--sm">
                                    <a href="/admin/roles/<?= $this->e((string) $role->id) ?>/edit" class="pc-user-cell__name">
                                        <?= $this->e($role->name) ?>
                                    </a>
                                    <?php if ($role->isSystem): ?>
                                        <?= $this->raw($this->insert('admin::components/badge', [
                                            'label' => 'Système',
                                            'variant' => $role->isAdministrator() ? 'brand' : 'neutral',
                                        ])) ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($role->description !== null): ?>
                                    <span class="pc-text-xs pc-text-muted"><?= $this->e($role->description) ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><code class="pc-mono"><?= $this->e($role->slug) ?></code></td>
                        <td>
                            <?php if ($role->isAdministrator()): ?>
                                <?= $this->raw($this->insert('admin::components/badge', [
                                    'label' => 'Toutes (*)',
                                    'variant' => 'brand',
                                ])) ?>
                            <?php else: ?>
                                <?= $this->raw($this->insert('admin::components/badge', [
                                    'label' => count($role->capabilities) . ' permission' . (count($role->capabilities) > 1 ? 's' : ''),
                                    'variant' => 'info',
                                ])) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $this->raw($this->insert('admin::components/badge', [
                                'label' => $role->usersCount . ' utilisateur' . ($role->usersCount > 1 ? 's' : ''),
                                'variant' => $role->usersCount > 0 ? 'neutral' : 'neutral',
                                'pill' => true,
                            ])) ?>
                        </td>
                        <td class="pc-table__actions-col">
                            <div class="pc-row pc-row--sm">
                                <a href="/admin/roles/<?= $this->e((string) $role->id) ?>/edit" class="pc-btn pc-btn--ghost pc-btn--sm">
                                    <?= $role->isAdministrator() ? 'Consulter' : 'Modifier' ?>
                                </a>

                                <?php if (!$role->isSystem): ?>
                                    <?php if ($role->usersCount > 0): ?>
                                        <span
                                            class="pc-text-xs pc-text-muted"
                                            title="Réassignez les <?= $this->e((string) $role->usersCount) ?> utilisateur(s) avant suppression."
                                        >
                                            Assigné (<?= $this->e((string) $role->usersCount) ?>)
                                        </span>
                                    <?php else: ?>
                                        <form
                                            method="post"
                                            action="/admin/roles/<?= $this->e((string) $role->id) ?>/delete"
                                            class="pc-inline-form"
                                            data-prime-confirm-form="Supprimer définitivement le rôle « <?= $this->e($role->name) ?> » ?"
                                        >
                                            <?= $this->raw($this->csrfField()) ?>
                                            <button type="submit" class="pc-btn pc-btn--ghost pc-btn--sm pc-text-danger">
                                                Supprimer
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php $this->stop(); ?>
