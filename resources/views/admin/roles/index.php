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
    'title' => $this->t('admin.view_roles_index.roles_et_permissions'),
    'subtitle' => $this->t('admin.view_roles_index.definissez_les_groupes_de_permissions_attribuables_aux_utili'),
    'breadcrumbs' => $breadcrumbs,
    'actions' => $this->insert('admin::components/button', [
        'label' => $this->t('admin.view_roles_index.creer_un_role'),
        'variant' => 'primary',
        'icon' => 'plus',
        'href' => '/admin/roles/create',
    ]),
])) ?>

<div class="pc-table-wrap">
    <div class="pc-table-scroll">
        <table class="pc-table pc-table--stacked">
            <thead>
                <tr>
                    <th scope="col"><?= $this->e($this->t('core.fields.role')) ?></th>
                    <th scope="col">Slug</th>
                    <th scope="col">Permissions</th>
                    <th scope="col">Utilisateurs</th>
                    <th scope="col" class="pc-table__actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($roles as $role): ?>
                    <tr>
                        <td class="pc-table__primary">
                            <div class="pc-stack pc-stack--xs">
                                <div class="pc-row pc-row--sm">
                                    <a href="/admin/roles/<?= $this->e((string) $role->id) ?>/edit" class="pc-user-cell__name">
                                        <?= $this->e($role->label()) ?>
                                    </a>
                                    <?php if ($role->isSystem): ?>
                                        <?= $this->raw($this->insert('admin::components/badge', [
                                            'label' => $this->t('admin.common.system'),
                                            'variant' => $role->isAdministrator() ? 'brand' : 'neutral',
                                        ])) ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($role->description !== null): ?>
                                    <span class="pc-text-xs pc-text-muted"><?= $this->e((string) $role->summary()) ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td data-prime-label="Slug"><code class="pc-mono"><?= $this->e($role->slug) ?></code></td>
                        <td data-prime-label="Permissions">
                            <?php if ($role->isAdministrator()): ?>
                                <?= $this->raw($this->insert('admin::components/badge', [
                                    'label' => $this->t('admin.view_roles_index.toutes'),
                                    'variant' => 'brand',
                                ])) ?>
                            <?php else: ?>
                                <?= $this->raw($this->insert('admin::components/badge', [
                                    'label' => count($role->capabilities) . ' permission' . (count($role->capabilities) > 1 ? 's' : ''),
                                    'variant' => 'info',
                                ])) ?>
                            <?php endif; ?>
                        </td>
                        <td data-prime-label="Utilisateurs">
                            <?= $this->raw($this->insert('admin::components/badge', [
                                'label' => $role->usersCount . ' utilisateur' . ($role->usersCount > 1 ? 's' : ''),
                                'variant' => 'neutral',
                                'pill' => true,
                            ])) ?>
                        </td>
                        <td class="pc-table__actions">
                            <div class="pc-row pc-row--sm">
                                <a href="/admin/roles/<?= $this->e((string) $role->id) ?>/edit" class="pc-btn pc-btn--ghost pc-btn--sm">
                                    <?= $this->e($role->isAdministrator() ? $this->t('admin.actions.consult') : $this->t('core.actions.edit')) ?>
                                </a>

                                <?php if (!$role->isSystem): ?>
                                    <?php if ($role->usersCount > 0): ?>
                                        <span
                                            class="pc-text-xs pc-text-muted"
                                            title="<?= $this->e($this->t('admin.roles.reassignez_utilisateurs', ['count' => $role->usersCount])) ?>"
                                        >
                                            <?= $this->e($this->t('admin.roles.assigne', ['count' => $role->usersCount])) ?>
                                        </span>
                                    <?php else: ?>
                                        <form
                                            method="post"
                                            action="/admin/roles/<?= $this->e((string) $role->id) ?>/delete"
                                            class="pc-inline-form"
                                            data-prime-confirm-form="<?= $this->e($this->t('admin.roles.confirmer_suppression', ['name' => $role->name])) ?>"
                                        >
                                            <?= $this->raw($this->csrfField()) ?>
                                            <button type="submit" class="pc-btn pc-btn--ghost pc-btn--sm pc-text-danger">
                                                <?= $this->e($this->t('core.actions.delete')) ?>
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
