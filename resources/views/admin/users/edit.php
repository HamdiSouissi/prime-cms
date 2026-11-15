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
 */
$this->layout('admin::layouts/app');
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => $targetUser->displayName,
    'subtitle' => $targetUser->email . ' · ' . $targetUser->primaryRoleName(),
    'breadcrumbs' => $breadcrumbs,
    'actions' => $this->insert('admin::components/button', [
        'label' => 'Retour à la liste',
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
                    'label' => 'Nom affiché',
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
                        'label' => 'Statut du compte',
                        'type' => 'select',
                        'value' => $old['status'],
                        'disabled' => $isSelf,
                        'options' => [
                            'active' => 'Actif',
                            'disabled' => 'Désactivé',
                        ],
                        'help' => $isSelf ? 'Vous ne pouvez pas désactiver votre propre compte.' : null,
                        'error' => $errors['status'] ?? null,
                    ])) ?>
                </div>
            </div>
            <?php $infoBody = (string) ob_get_clean(); ?>

            <?= $this->raw($this->insert('admin::components/card', [
                'title' => 'Informations du compte',
                'body' => $infoBody,
            ])) ?>

            <?php ob_start(); ?>
            <div class="pc-stack pc-stack--sm">
                <?php if (!$canManageRoles): ?>
                    <p class="pc-text-sm pc-text-muted">Vous n'avez pas la permission de modifier les rôles de cet utilisateur.</p>
                    <div class="pc-badges-wrap">
                        <?php foreach ($targetUser->roles as $role): ?>
                            <?= $this->raw($this->insert('admin::components/badge', [
                                'label' => $role->name,
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
                                    <span class="pc-checkbox-card__title"><?= $this->e($role->name) ?></span>
                                    <?php if ($role->description !== null): ?>
                                        <span class="pc-checkbox-card__desc"><?= $this->e($role->description) ?></span>
                                    <?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php $rolesBody = (string) ob_get_clean(); ?>

            <?= $this->raw($this->insert('admin::components/card', [
                'title' => 'Rôles',
                'body' => $rolesBody,
            ])) ?>

            <?php ob_start(); ?>
            <div class="pc-stack pc-stack--md">
                <?= $this->raw($this->insert('admin::components/field', [
                    'id' => 'edit-password',
                    'name' => 'password',
                    'label' => 'Nouveau mot de passe (optionnel)',
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
                        Laissez vide pour conserver le mot de passe actuel.
                    </span>
                </div>

                <?= $this->raw($this->insert('admin::components/field', [
                    'id' => 'edit-password-confirmation',
                    'name' => 'password_confirmation',
                    'label' => 'Confirmer le nouveau mot de passe',
                    'type' => 'password',
                    'value' => '',
                    'placeholder' => 'Ressaisissez le nouveau mot de passe',
                    'autocomplete' => 'new-password',
                    'error' => $errors['password_confirmation'] ?? null,
                ])) ?>
            </div>
            <?php $pwdBody = (string) ob_get_clean(); ?>

            <?= $this->raw($this->insert('admin::components/card', [
                'title' => 'Définir un nouveau mot de passe',
                'body' => $pwdBody,
                'footer' => '<button type="submit" class="pc-btn pc-btn--primary">Enregistrer les modifications</button>',
            ])) ?>
        </form>

        <!-- Security sidebar -->
        <div class="pc-stack pc-stack--lg">
            <?php ob_start(); ?>
            <dl class="pc-kv-list">
                <div class="pc-kv-row">
                    <dt>Statut</dt>
                    <dd>
                        <?= $this->raw($this->insert('admin::components/badge', [
                            'label' => $targetUser->isActive() ? 'Actif' : 'Désactivé',
                            'variant' => $targetUser->isActive() ? 'success' : 'neutral',
                            'dot' => true,
                        ])) ?>
                    </dd>
                </div>
                <div class="pc-kv-row">
                    <dt>2FA (TOTP)</dt>
                    <dd>
                        <?= $this->raw($this->insert('admin::components/badge', [
                            'label' => $targetUser->hasTwoFactor() ? 'Activée' : 'Non activée',
                            'variant' => $targetUser->hasTwoFactor() ? 'teal' : 'neutral',
                        ])) ?>
                    </dd>
                </div>
                <div class="pc-kv-row">
                    <dt>Dernière connexion</dt>
                    <dd title="<?= $this->e($lastLoginMeta['title']) ?>"><?= $this->e($lastLoginMeta['label']) ?></dd>
                </div>
                <div class="pc-kv-row">
                    <dt>Version de session</dt>
                    <dd class="pc-mono">v<?= $this->e((string) $targetUser->sessionVersion) ?></dd>
                </div>
            </dl>
            <?php $statusBody = (string) ob_get_clean(); ?>

            <?= $this->raw($this->insert('admin::components/card', [
                'title' => 'État sécurité',
                'body' => $statusBody,
            ])) ?>

            <?php ob_start(); ?>
            <div class="pc-stack pc-stack--sm">
                <form method="post" action="/admin/users/<?= $this->e((string) $targetUser->id) ?>/reset-password">
                    <?= $this->raw($this->csrfField()) ?>
                    <button type="submit" class="pc-btn pc-btn--secondary pc-auth-submit">
                        Envoyer un lien de réinitialisation
                    </button>
                </form>

                <form method="post" action="/admin/users/<?= $this->e((string) $targetUser->id) ?>/logout-devices">
                    <?= $this->raw($this->csrfField()) ?>
                    <button type="submit" class="pc-btn pc-btn--secondary pc-auth-submit">
                        Déconnecter tous les appareils
                    </button>
                </form>

                <?php if ($targetUser->hasTwoFactor()): ?>
                    <form
                        method="post"
                        action="/admin/users/<?= $this->e((string) $targetUser->id) ?>/reset-2fa"
                        data-prime-confirm-form="Réinitialiser la 2FA de <?= $this->e($targetUser->displayName) ?> ? L'utilisateur pourra se connecter sans second facteur jusqu'à réactivation."
                    >
                        <?= $this->raw($this->csrfField()) ?>
                        <button type="submit" class="pc-btn pc-btn--secondary pc-auth-submit">
                            Réinitialiser la 2FA
                        </button>
                    </form>
                <?php endif; ?>

                <?php if ($canDelete): ?>
                    <form
                        method="post"
                        action="/admin/users/<?= $this->e((string) $targetUser->id) ?>/delete"
                        data-prime-confirm-form="Supprimer définitivement le compte de <?= $this->e($targetUser->displayName) ?> ? Cette action est irréversible."
                    >
                        <?= $this->raw($this->csrfField()) ?>
                        <button type="submit" class="pc-btn pc-btn--danger pc-auth-submit">
                            Supprimer ce compte
                        </button>
                    </form>
                <?php endif; ?>
            </div>
            <?php $actionsBody = (string) ob_get_clean(); ?>

            <?= $this->raw($this->insert('admin::components/card', [
                'title' => 'Actions de sécurité',
                'body' => $actionsBody,
            ])) ?>
        </div>
    </div>
</div>
<?php $this->stop(); ?>
