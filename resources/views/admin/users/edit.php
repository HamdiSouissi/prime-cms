<?php

declare(strict_types=1);

/**
 * Edit user form & administrative security actions (`GET /admin/users/{id}/edit`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var \Prime\Auth\User $targetUser
 * @var list<\Prime\Auth\Role> $assignableRoles
 * @var bool $canManageRoles
 * @var bool $canDelete
 * @var bool $isSelf
 * @var array<string, string> $localeOptions
 * @var array{label: string, title: string} $lastLoginMeta
 * @var array{display_name: string, email: string, locale: string, status: string, roles: list<int>} $old
 * @var array<string, string> $errors
 * @var list<array{label: string, url: ?string}> $breadcrumbs
 * @var list<array<string, mixed>> $apiTokens
 */
$this->layout('admin::layouts/app');
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => $targetUser->displayName,
    'subtitle' => $targetUser->email . ' · ' . $targetUser->primaryRoleName(),
    'breadcrumbs' => $breadcrumbs,
    'actions' => $this->insert('admin::components/button', [
        'label' => $this->t('admin.view_users_edit.retour_a_la_liste'),
        'variant' => 'secondary',
        'href' => '/admin/users',
    ]),
])) ?>

<div class="pc-stack pc-stack--lg">
    <?php if (!empty($errors['general'])): ?>
        <?= $this->raw($this->insert('admin::components/alert', [
            'variant' => 'danger',
            'message' => $errors['general'],
        ])) ?>
    <?php endif; ?>

    <div class="pc-form-grid pc-form-grid--aside">
        <form method="post" action="/admin/users/<?= $this->e((string) $targetUser->id) ?>" class="pc-stack pc-stack--lg" novalidate>
            <?= $this->raw($this->csrfField()) ?>

            <?php ob_start(); ?>
            <div class="pc-stack pc-stack--md">
                <?= $this->raw($this->insert('admin::components/field', [
                    'id' => 'edit-display-name',
                    'name' => 'display_name',
                    'label' => $this->t('admin.view_users_edit.nom_affiche'),
                    'type' => 'text',
                    'value' => $old['display_name'],
                    'required' => true,
                    'error' => $errors['display_name'] ?? null,
                ])) ?>

                <?= $this->raw($this->insert('admin::components/field', [
                    'id' => 'edit-email',
                    'name' => 'email',
                    'label' => 'Adresse e-mail',
                    'type' => 'email',
                    'value' => $old['email'],
                    'required' => true,
                    'error' => $errors['email'] ?? null,
                ])) ?>

                <div class="pc-grid pc-grid--2">
                    <?= $this->raw($this->insert('admin::components/field', [
                        'id' => 'edit-locale',
                        'name' => 'locale',
                        'label' => 'Langue',
                        'type' => 'select',
                        'value' => $old['locale'],
                        'options' => $localeOptions,
                        'error' => $errors['locale'] ?? null,
                    ])) ?>

                    <?= $this->raw($this->insert('admin::components/field', [
                        'id' => 'edit-status',
                        'name' => 'status',
                        'label' => $this->t('admin.view_users_edit.statut_du_compte'),
                        'type' => 'select',
                        'value' => $old['status'],
                        'disabled' => $isSelf,
                        'options' => [
                            'active' => 'Actif',
                            'disabled' => $this->t('core.statuses.disabled'),
                        ],
                        'help' => $isSelf ? $this->t('admin.view_users_edit.vous_ne_pouvez_pas_desactiver_votre_propre_compte') : null,
                        'error' => $errors['status'] ?? null,
                    ])) ?>
                </div>
            </div>
            <?php $infoBody = (string) ob_get_clean(); ?>

            <?= $this->raw($this->insert('admin::components/card', [
                'title' => $this->t('admin.users.account_info'),
                'body' => $infoBody,
            ])) ?>

            <?php ob_start(); ?>
            <div class="pc-stack pc-stack--sm">
                <?php if (!$canManageRoles): ?>
                    <p class="pc-text-sm pc-text-muted"><?= $this->e($this->t('admin.users.edit.avez_pas_permission_modifier_roles')) ?></p>
                    <div class="pc-badges-wrap">
                        <?php foreach ($targetUser->roles as $role): ?>
                            <?= $this->raw($this->insert('admin::components/badge', [
                                'label' => $role->label(),
                                'variant' => $role->isAdministrator() ? 'brand' : 'info',
                            ])) ?>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
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
                <?php endif; ?>
            </div>
            <?php $rolesBody = (string) ob_get_clean(); ?>

            <?= $this->raw($this->insert('admin::components/card', [
                'title' => $this->t('admin.users.roles'),
                'body' => $rolesBody,
            ])) ?>

            <?php ob_start(); ?>
            <div class="pc-stack pc-stack--md">
                <?= $this->raw($this->insert('admin::components/field', [
                    'id' => 'edit-password',
                    'name' => 'password',
                    'label' => $this->t('admin.view_users_edit.nouveau_mot_de_passe_optionnel'),
                    'type' => 'password',
                    'value' => '',
                    'placeholder' => 'Laisser vide pour ne pas changer',
                    'autocomplete' => 'new-password',
                    'error' => $errors['password'] ?? null,
                ])) ?>

                <div class="pc-strength" data-prime-password-strength="edit-password" data-prime-level="0" aria-live="polite">
                    <div class="pc-strength__bars" aria-hidden="true">
                        <span class="pc-strength__bar"></span>
                        <span class="pc-strength__bar"></span>
                        <span class="pc-strength__bar"></span>
                        <span class="pc-strength__bar"></span>
                    </div>
                    <span class="pc-strength__label" data-prime-strength-label>
                        <?= $this->e($this->t('admin.users.edit.laissez_vide_conserver_mot_passe')) ?>
                    </span>
                </div>

                <?= $this->raw($this->insert('admin::components/field', [
                    'id' => 'edit-password-confirmation',
                    'name' => 'password_confirmation',
                    'label' => $this->t('admin.view_users_edit.confirmer_le_nouveau_mot_de_passe'),
                    'type' => 'password',
                    'value' => '',
                    'placeholder' => $this->t('admin.view_users_edit.ressaisissez_le_nouveau_mot_de_passe'),
                    'autocomplete' => 'new-password',
                    'error' => $errors['password_confirmation'] ?? null,
                ])) ?>
            </div>
            <?php $pwdBody = (string) ob_get_clean(); ?>

            <?= $this->raw($this->insert('admin::components/card', [
                'title' => $this->t('admin.view_users_edit.definir_un_nouveau_mot_de_passe'),
                'body' => $pwdBody,
                'footer' => '<button type="submit" class="pc-btn pc-btn--primary">' . $this->e($this->t('core.actions.save_changes')) . '</button>',
            ])) ?>
        </form>

        <!-- Security sidebar -->
        <div class="pc-stack pc-stack--lg">
            <?php ob_start(); ?>
            <dl class="pc-kv-list">
                <div class="pc-kv-row">
                    <dt><?= $this->e($this->t('core.labels.status')) ?></dt>
                    <dd>
                        <?= $this->raw($this->insert('admin::components/badge', [
                            'label' => $targetUser->isActive() ? $this->t('core.statuses.active') : $this->t('core.statuses.disabled'),
                            'variant' => $targetUser->isActive() ? 'success' : 'neutral',
                            'dot' => true,
                        ])) ?>
                    </dd>
                </div>
                <div class="pc-kv-row">
                    <dt><?= t('admin.users.edit.two_factor') ?></dt>
                    <dd>
                        <?= $this->raw($this->insert('admin::components/badge', [
                            'label' => $targetUser->hasTwoFactor() ? $this->t('admin.users.two_factor_on') : $this->t('admin.view_users_edit.non_activee'),
                            'variant' => $targetUser->hasTwoFactor() ? 'teal' : 'neutral',
                        ])) ?>
                    </dd>
                </div>
                <div class="pc-kv-row">
                    <dt><?= $this->e($this->t('admin.users.edit.derniere_connexion')) ?></dt>
                    <dd title="<?= $this->e($lastLoginMeta['title']) ?>"><?= $this->e($lastLoginMeta['label']) ?></dd>
                </div>
                <div class="pc-kv-row">
                    <dt><?= $this->e($this->t('admin.users.edit.version_session')) ?></dt>
                    <dd class="pc-mono">v<?= $this->e((string) $targetUser->sessionVersion) ?></dd>
                </div>
            </dl>
            <?php $statusBody = (string) ob_get_clean(); ?>

            <?= $this->raw($this->insert('admin::components/card', [
                'title' => $this->t('admin.view_users_edit.etat_securite'),
                'body' => $statusBody,
            ])) ?>

            <?php ob_start(); ?>
            <div class="pc-stack pc-stack--sm">
                <form method="post" action="/admin/users/<?= $this->e((string) $targetUser->id) ?>/reset-password">
                    <?= $this->raw($this->csrfField()) ?>
                    <button type="submit" class="pc-btn pc-btn--secondary pc-auth-submit">
                        <?= $this->e($this->t('admin.users.edit.envoyer_lien_reinitialisation')) ?>
                    </button>
                </form>

                <form method="post" action="/admin/users/<?= $this->e((string) $targetUser->id) ?>/logout-devices">
                    <?= $this->raw($this->csrfField()) ?>
                    <button type="submit" class="pc-btn pc-btn--secondary pc-auth-submit">
                        <?= $this->e($this->t('admin.users.edit.deconnecter_appareils')) ?>
                    </button>
                </form>

                <?php if ($targetUser->hasTwoFactor()): ?>
                    <form
                        method="post"
                        action="/admin/users/<?= $this->e((string) $targetUser->id) ?>/reset-2fa"
                        data-prime-confirm-form="<?= $this->e($this->t('admin.users.edit.confirmer_reinitialisation_2fa', ['name' => $targetUser->displayName])) ?>"
                    >
                        <?= $this->raw($this->csrfField()) ?>
                        <button type="submit" class="pc-btn pc-btn--secondary pc-auth-submit">
                            <?= $this->e($this->t('admin.users.edit.reinitialiser_2fa')) ?>
                        </button>
                    </form>
                <?php endif; ?>

                <?php if ($canDelete): ?>
                    <form
                        method="post"
                        action="/admin/users/<?= $this->e((string) $targetUser->id) ?>/delete"
                        data-prime-confirm-form="<?= $this->e($this->t('admin.users.edit.confirmer_suppression_compte', ['name' => $targetUser->displayName])) ?>"
                    >
                        <?= $this->raw($this->csrfField()) ?>
                        <button type="submit" class="pc-btn pc-btn--danger pc-auth-submit">
                            <?= $this->e($this->t('admin.users.edit.supprimer_compte')) ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
            <?php $actionsBody = (string) ob_get_clean(); ?>

            <?= $this->raw($this->insert('admin::components/card', [
                'title' => $this->t('admin.view_users_edit.actions_de_securite'),
                'body' => $actionsBody,
            ])) ?>

            <?php ob_start(); ?>
            <div class="pc-stack pc-stack--md">
                <?php if ($apiTokens === []): ?>
                    <p class="pc-text-sm pc-text-muted"><?= $this->e($this->t('admin.view_profile_index.jeton_api_aucun')) ?></p>
                <?php else: ?>
                    <table class="pc-table">
                        <thead>
                        <tr>
                            <th><?= $this->e($this->t('admin.view_profile_index.jeton_api_col_nom')) ?></th>
                            <th><?= $this->e($this->t('admin.view_profile_index.jeton_api_col_prefixe')) ?></th>
                            <th><?= $this->e($this->t('admin.view_profile_index.jeton_api_col_statut')) ?></th>
                            <th><?= $this->e($this->t('admin.view_profile_index.jeton_api_col_action')) ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($apiTokens as $token): ?>
                            <?php
                            $tokenId = (int) ($token['id'] ?? 0);
                            $revoked = $token['revoked_at'] !== null && $token['revoked_at'] !== '';
                            $expired = $token['expires_at'] !== null && $token['expires_at'] !== '' && $token['expires_at'] < date('Y-m-d H:i:s');
                            $status = $revoked ? 'revoked' : ($expired ? 'expired' : 'active');
                            $statusLabel = match ($status) {
                                'active' => $this->t('admin.view_profile_index.jeton_api_statut_actif'),
                                'expired' => $this->t('admin.view_profile_index.jeton_api_statut_expire'),
                                default => $this->t('admin.view_profile_index.jeton_api_statut_revoque'),
                            };
                            $statusVariant = match ($status) {
                                'active' => 'success',
                                'expired' => 'warning',
                                default => 'danger',
                            };
                            ?>
                            <tr>
                                <td><?= $this->e((string) ($token['name'] ?? '')) ?></td>
                                <td style="font-family: var(--pc-font-mono, monospace);"><?= $this->e('pcat_' . substr((string) ($token['token_prefix'] ?? ''), 0, 8) . '…') ?></td>
                                <td><?= $this->raw($this->insert('admin::components/badge', [
                                    'label' => $statusLabel,
                                    'variant' => $statusVariant,
                                ])) ?></td>
                                <td>
                                    <?php if ($status === 'active'): ?>
                                        <form
                                            method="post"
                                            action="/admin/users/<?= $this->e((string) $targetUser->id) ?>/api-tokens/<?= $tokenId ?>/revoke"
                                            data-prime-confirm-form="<?= $this->e($this->t('admin.view_profile_index.jeton_api_confirmer_revoke')) ?>"
                                        >
                                            <?= $this->raw($this->csrfField()) ?>
                                            <button type="submit" class="pc-btn pc-btn--danger pc-btn--sm"><?= $this->e($this->t('admin.view_profile_index.jeton_api_revoquer')) ?></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <?php $tokensBody = (string) ob_get_clean(); ?>

            <?= $this->raw($this->insert('admin::components/card', [
                'title' => $this->t('admin.view_profile_index.jeton_api_liste_titre'),
                'body' => $tokensBody,
            ])) ?>
        </div>
    </div>
</div>
<?php $this->stop(); ?>
