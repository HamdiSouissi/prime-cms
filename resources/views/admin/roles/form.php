<?php

declare(strict_types=1);

/**
 * Create / edit role view with grouped capability checkbox matrix.
 *
 * @var \Prime\View\TemplateContext $this
 * @var 'create'|'edit' $mode
 * @var \Prime\Auth\Role|null $role
 * @var bool $isReadOnly
 * @var array<string, array<string, string>> $groupedCapabilities
 * @var array{name: string, slug: string, description: string, capabilities: list<string>} $old
 * @var array<string, string> $errors
 * @var list<array{label: string, url: ?string}> $breadcrumbs
 */
$this->layout('admin::layouts/app');

$isCreate = $mode === 'create';
$formAction = $isCreate ? '/admin/roles' : '/admin/roles/' . ($role !== null ? $role->id : 0);
$hasWildcard = in_array('*', $old['capabilities'], true);
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => $isCreate ? $this->t('admin.view_roles_form.creer_un_role') : ($isReadOnly ? $this->t('admin.view_roles_form.role_administrateur_lecture_seule') : $this->t('admin.roles.edit_title', ['name' => $old['name']])),
    'subtitle' => $isReadOnly
        ? $this->t('admin.view_roles_form.le_role_administrateur_possede_automatiquement_l_ensemble_de')
        : $this->t('admin.view_roles_form.configurez_l_identite_du_role_et_cochez_precisement_les_perm'),
    'breadcrumbs' => $breadcrumbs,
    'actions' => $this->insert('admin::components/button', [
        'label' => $this->t('admin.view_roles_form.retour_aux_roles'),
        'variant' => 'secondary',
        'href' => '/admin/roles',
    ]),
])) ?>

<div class="pc-stack pc-stack--lg">
    <?php if ($isReadOnly): ?>
        <?= $this->raw($this->insert('admin::components/alert', [
            'variant' => 'info',
            'title' => $this->t('admin.view_roles_form.role_protege_en_lecture_seule'),
            'message' => $this->t('admin.view_roles_form.le_role_administrateur_detient_le_privilege_universel_afin_d'),
        ])) ?>
    <?php endif; ?>

    <form method="post" action="<?= $this->e($formAction) ?>" class="pc-stack pc-stack--lg" novalidate>
        <?= $this->raw($this->csrfField()) ?>

        <?php ob_start(); ?>
        <div class="pc-stack pc-stack--md">
            <div class="pc-grid pc-grid--2">
                <div class="pc-field<?= isset($errors['name']) ? ' pc-field--error' : '' ?>">
                    <label class="pc-field__label" for="role-name">
                        <span><?= $this->e($this->t('admin.roles.form.nom_role')) ?></span>
                        <span class="pc-field__required" aria-hidden="true">*</span>
                    </label>
                    <input
                        type="text"
                        id="role-name"
                        name="name"
                        class="pc-input"
                        value="<?= $this->e($old['name']) ?>"
                        placeholder="<?= $this->e($this->t('admin.roles.form.ex_moderateur')) ?>"
                        <?= $isCreate ? 'data-prime-slug-source="role-slug"' : '' ?>
                        <?= $isReadOnly ? 'disabled' : 'required' ?>
                    >
                    <?php if (isset($errors['name'])): ?>
                        <p class="pc-field__error" role="alert"><?= $this->e($errors['name']) ?></p>
                    <?php endif; ?>
                </div>

                <?= $this->raw($this->insert('admin::components/field', [
                    'id' => 'role-slug',
                    'name' => 'slug',
                    'label' => 'Identifiant (slug)',
                    'type' => 'text',
                    'value' => $old['slug'],
                    'placeholder' => 'ex. moderateur',
                    'required' => $isCreate,
                    'disabled' => !$isCreate,
                    'help' => $isCreate
                        ? $this->t('admin.view_roles_form.genere_automatiquement_depuis_le_nom_lettres_minuscules_chif')
                        : $this->t('admin.view_roles_form.l_identifiant_d_un_role_existant_ne_peut_plus_etre_modifie'),
                    'error' => $errors['slug'] ?? null,
                ])) ?>
            </div>

            <?= $this->raw($this->insert('admin::components/field', [
                'id' => 'role-description',
                'name' => 'description',
                'label' => 'Description',
                'type' => 'textarea',
                'value' => $old['description'],
                'placeholder' => $this->t('admin.view_roles_form.decrivez_le_perimetre_et_les_responsabilites_de_ce_role'),
                'disabled' => $isReadOnly,
                'error' => $errors['description'] ?? null,
            ])) ?>
        </div>
        <?php $metaBody = (string) ob_get_clean(); ?>

        <div class="pc-form-wide">
            <?= $this->raw($this->insert('admin::components/card', [
                'title' => $this->t('admin.view_roles_form.identite_du_role'),
                'body' => $metaBody,
            ])) ?>
        </div>

        <?php ob_start(); ?>
        <div class="pc-cap-matrix">
            <?php foreach ($groupedCapabilities as $groupName => $groupCaps): ?>
                <?php $groupId = 'cap-group-' . md5($groupName); ?>
                <section class="pc-cap-group" data-prime-cap-group="<?= $this->e($groupId) ?>">
                    <header class="pc-cap-group__header">
                        <h3 class="pc-cap-group__title"><?= $this->e($groupName) ?></h3>
                        <?php if (!$isReadOnly): ?>
                            <button
                                type="button"
                                class="pc-auth-link-btn"
                                data-prime-cap-group-toggle="<?= $this->e($groupId) ?>"
                            >
                                <?= t('admin.roles.select_all') ?>
                            </button>
                        <?php endif; ?>
                    </header>
                    <div class="pc-cap-group__list">
                        <?php foreach ($groupCaps as $capKey => $capLabel): ?>
                            <?php $checked = $hasWildcard || in_array($capKey, $old['capabilities'], true); ?>
                            <label class="pc-cap-item">
                                <input
                                    type="checkbox"
                                    name="capabilities[]"
                                    value="<?= $this->e($capKey) ?>"
                                    <?= $checked ? ' checked' : '' ?>
                                    <?= $isReadOnly ? ' disabled' : '' ?>
                                >
                                <span>
                                    <span><?= $this->e($capLabel) ?></span>
                                    <span class="pc-cap-item__key"><?= $this->e($capKey) ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
        <?php $matrixBody = (string) ob_get_clean(); ?>

        <?= $this->raw($this->insert('admin::components/card', [
            'title' => $this->t('admin.roles.matrix'),
            'subtitle' => $this->t('admin.view_roles_form.permissions_groupees_par_domaine_fonctionnel'),
            'body' => $matrixBody,
            'footer' => $isReadOnly
                ? '<a href="/admin/roles" class="pc-btn pc-btn--secondary">' . $this->e($this->t('admin.actions.back_to_list')) . '</a>'
                : '<a href="/admin/roles" class="pc-btn pc-btn--ghost">' . $this->e($this->t('core.actions.cancel')) . '</a><button type="submit" class="pc-btn pc-btn--primary">' . ($isCreate ? $this->t('admin.view_roles_form.creer_le_role') : $this->t('admin.roles.save_permissions')) . '</button>',
        ])) ?>
    </form>
</div>
<?php $this->stop(); ?>
