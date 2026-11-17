<?php

declare(strict_types=1);

/**
 * Create user form (`GET /admin/users/create`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var list<\Prime\Auth\Role> $assignableRoles
 * @var array<string, string> $localeOptions
 * @var array{display_name: string, email: string, locale: string, status: string, creation_mode: string, roles: list<int>} $old
 * @var array<string, string> $errors
 * @var list<array{label: string, url: ?string}> $breadcrumbs
 */
$this->layout('admin::layouts/app');
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => $this->t('admin.view_users_create.ajouter_un_utilisateur'),
    'subtitle' => $this->t('admin.view_users_create.creez_un_compte_directement_ou_envoyez_un_lien_d_invitation_'),
    'breadcrumbs' => $breadcrumbs,
    'actions' => $this->insert('admin::components/button', [
        'label' => $this->t('admin.view_users_create.retour_a_la_liste'),
        'variant' => 'secondary',
        'href' => '/admin/users',
    ]),
])) ?>

<form method="post" action="/admin/users" class="pc-stack pc-stack--lg" novalidate>
    <?= $this->raw($this->csrfField()) ?>

    <div class="pc-form-grid pc-form-grid--aside">
        <div class="pc-stack pc-stack--lg">
            <?php ob_start(); ?>
            <div class="pc-stack pc-stack--md">
                <?= $this->raw($this->insert('admin::components/field', [
                    'id' => 'user-display-name',
                    'name' => 'display_name',
                    'label' => $this->t('admin.view_users_create.nom_affiche'),
                    'type' => 'text',
                    'value' => $old['display_name'],
                    'placeholder' => 'Ex. Camille Laurent',
                    'required' => true,
                    'error' => $errors['display_name'] ?? null,
                ])) ?>

                <?= $this->raw($this->insert('admin::components/field', [
                    'id' => 'user-email',
                    'name' => 'email',
                    'label' => 'Adresse e-mail',
                    'type' => 'email',
                    'value' => $old['email'],
                    'placeholder' => 'camille@exemple.fr',
                    'required' => true,
                    'error' => $errors['email'] ?? null,
                ])) ?>

                <div class="pc-grid pc-grid--2">
                    <?= $this->raw($this->insert('admin::components/field', [
                        'id' => 'user-locale',
                        'name' => 'locale',
                        'label' => 'Langue',
                        'type' => 'select',
                        'value' => $old['locale'],
                        'options' => $localeOptions,
                        'error' => $errors['locale'] ?? null,
                    ])) ?>

                    <?= $this->raw($this->insert('admin::components/field', [
                        'id' => 'user-status',
                        'name' => 'status',
                        'label' => $this->t('admin.view_users_create.statut_du_compte'),
                        'type' => 'select',
                        'value' => $old['status'],
                        'options' => [
                            'active' => 'Actif',
                            'disabled' => $this->t('core.statuses.disabled'),
                        ],
                        'error' => $errors['status'] ?? null,
                    ])) ?>
                </div>
            </div>
            <?php $identityBody = (string) ob_get_clean(); ?>

            <?= $this->raw($this->insert('admin::components/card', [
                'title' => $this->t('admin.view_users_create.identite_du_compte'),
                'body' => $identityBody,
            ])) ?>

            <?php ob_start(); ?>
            <div class="pc-stack pc-stack--md">
                <div class="pc-checkbox-list">
                    <label class="pc-checkbox-card">
                        <input type="radio" name="creation_mode" value="password" data-prime-creation-mode<?= $old['creation_mode'] !== 'invite' ? ' checked' : '' ?>>
                        <span>
                            <span class="pc-checkbox-card__title"><?= $this->e($this->t('admin.users.create.definir_mot_passe_maintenant')) ?></span>
                            <span class="pc-checkbox-card__desc"><?= $this->e($this->t('admin.users.create.compte_utilisable_immediatement_mot_passe')) ?></span>
                        </span>
                    </label>
                    <label class="pc-checkbox-card">
                        <input type="radio" name="creation_mode" value="invite" data-prime-creation-mode<?= $old['creation_mode'] === 'invite' ? ' checked' : '' ?>>
                        <span>
                            <span class="pc-checkbox-card__title"><?= $this->e($this->t('admin.users.create.envoyer_lien_invitation_e_mail')) ?></span>
                            <span class="pc-checkbox-card__desc"><?= $this->e($this->t('admin.users.create.utilisateur_choisira_lui_meme_mot')) ?></span>
                        </span>
                    </label>
                </div>

                <div class="pc-stack pc-stack--md" data-prime-password-section<?= $old['creation_mode'] === 'invite' ? ' hidden' : '' ?>>
                    <?= $this->raw($this->insert('admin::components/field', [
                        'id' => 'user-password',
                        'name' => 'password',
                        'label' => $this->t('admin.view_users_create.mot_de_passe'),
                        'type' => 'password',
                        'value' => '',
                        'placeholder' => $this->t('admin.view_users_create.minimum_12_caracteres'),
                        'autocomplete' => 'new-password',
                        'error' => $errors['password'] ?? null,
                    ])) ?>

                    <div class="pc-strength" data-prime-password-strength="user-password" data-prime-level="0" aria-live="polite">
                        <div class="pc-strength__bars" aria-hidden="true">
                            <span class="pc-strength__bar"></span>
                            <span class="pc-strength__bar"></span>
                            <span class="pc-strength__bar"></span>
                            <span class="pc-strength__bar"></span>
                        </div>
                        <span class="pc-strength__label" data-prime-strength-label>
                            <?= $this->e($this->t('admin.users.create.minimum_12_caracteres_majuscules_chiffres')) ?>
                        </span>
                    </div>

                    <?= $this->raw($this->insert('admin::components/field', [
                        'id' => 'user-password-confirmation',
                        'name' => 'password_confirmation',
                        'label' => $this->t('admin.view_users_create.confirmer_le_mot_de_passe'),
                        'type' => 'password',
                        'value' => '',
                        'placeholder' => $this->t('admin.view_users_create.ressaisissez_le_mot_de_passe'),
                        'autocomplete' => 'new-password',
                        'error' => $errors['password_confirmation'] ?? null,
                    ])) ?>
                </div>
            </div>
            <?php $pwdBody = (string) ob_get_clean(); ?>

            <?= $this->raw($this->insert('admin::components/card', [
                'title' => $this->t('admin.view_users_create.mot_de_passe_et_activation'),
                'body' => $pwdBody,
            ])) ?>
        </div>

        <div class="pc-stack pc-stack--lg">
            <?php ob_start(); ?>
            <div class="pc-stack pc-stack--sm">
                <?php if (!empty($errors['roles'])): ?>
                    <?= $this->raw($this->insert('admin::components/alert', [
                        'variant' => 'danger',
                        'message' => $errors['roles'],
                    ])) ?>
                <?php endif; ?>

                <div class="pc-checkbox-list">
                    <?php foreach ($assignableRoles as $role): ?>
                        <?php $checked = in_array($role->id, $old['roles'], true); ?>
                        <label class="pc-checkbox-card">
                            <input type="checkbox" name="roles[]" value="<?= $this->e((string) $role->id) ?>"<?= $checked ? ' checked' : '' ?>>
                            <span>
                                <span class="pc-checkbox-card__title"><?= $this->e($role->label()) ?></span>
                                <?php if ($role->description !== null): ?>
                                    <span class="pc-checkbox-card__desc"><?= $this->e((string) $role->summary()) ?></span>
                                <?php endif; ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php $rolesBody = (string) ob_get_clean(); ?>

            <?= $this->raw($this->insert('admin::components/card', [
                'title' => $this->t('admin.view_users_create.roles_attribues'),
                'subtitle' => $this->t('admin.view_users_create.un_utilisateur_peut_cumuler_plusieurs_roles'),
                'body' => $rolesBody,
                'footer' => '<button type="submit" class="pc-btn pc-btn--primary pc-auth-submit">' . $this->e($this->t('admin.users.create_submit')) . '</button>',
            ])) ?>
        </div>
    </div>
</form>
<?php $this->stop(); ?>
