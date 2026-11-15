<?php

declare(strict_types=1);

/**
 * Self-service profile & security view (`GET /admin/profile`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var \Prime\Auth\User $profileUser
 * @var string $activeTab
 * @var array<string, string> $localeOptions
 * @var array{display_name: string, email: string, locale: string} $oldInfo
 * @var array<string, string> $infoErrors
 * @var array<string, string> $passwordErrors
 * @var string|null $twoFactorError
 * @var string|null $devicesError
 * @var array{secret: string, formatted: string, uri: string}|null $pendingTwoFactor
 * @var list<string>|null $recoveryCodesOnce
 * @var int $remainingRecoveryCount
 * @var list<array{label: string, url: ?string}> $breadcrumbs
 */
$this->layout('admin::layouts/app');
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => 'Mon profil',
    'subtitle' => $profileUser->displayName . ' · ' . $profileUser->primaryRoleName(),
    'breadcrumbs' => $breadcrumbs,
])) ?>

<?php
// ---- Tab 1: Informations ----
ob_start();
?>
<form method="post" action="/admin/profile/info" class="pc-stack pc-stack--lg" novalidate>
    <?= $this->raw($this->csrfField()) ?>

    <?php ob_start(); ?>
    <div class="pc-stack pc-stack--md">
        <?= $this->raw($this->insert('admin::components/field', [
            'id' => 'profile-display-name',
            'name' => 'display_name',
            'label' => 'Nom affiché',
            'type' => 'text',
            'value' => $oldInfo['display_name'],
            'required' => true,
            'error' => $infoErrors['display_name'] ?? null,
        ])) ?>

        <?= $this->raw($this->insert('admin::components/field', [
            'id' => 'profile-email',
            'name' => 'email',
            'label' => 'Adresse e-mail',
            'type' => 'email',
            'value' => $oldInfo['email'],
            'required' => true,
            'help' => 'Pour changer votre adresse e-mail, confirmez avec votre mot de passe actuel ci-dessous.',
            'error' => $infoErrors['email'] ?? null,
        ])) ?>

        <?= $this->raw($this->insert('admin::components/field', [
            'id' => 'profile-current-password-email',
            'name' => 'current_password_email',
            'label' => 'Mot de passe actuel (requis uniquement en cas de changement d\'e-mail)',
            'type' => 'password',
            'value' => '',
            'autocomplete' => 'current-password',
            'error' => $infoErrors['current_password_email'] ?? null,
        ])) ?>

        <?= $this->raw($this->insert('admin::components/field', [
            'id' => 'profile-locale',
            'name' => 'locale',
            'label' => 'Langue de l\'interface',
            'type' => 'select',
            'value' => $oldInfo['locale'],
            'options' => $localeOptions,
            'error' => $infoErrors['locale'] ?? null,
        ])) ?>

        <div>
            <span class="pc-field__label">Rôles attribués</span>
            <div class="pc-badges-wrap">
                <?php foreach ($profileUser->roles as $role): ?>
                    <?= $this->raw($this->insert('admin::components/badge', [
                        'label' => $role->name,
                        'variant' => $role->isAdministrator() ? 'brand' : 'info',
                    ])) ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php $infoCardBody = (string) ob_get_clean(); ?>

    <?= $this->raw($this->insert('admin::components/card', [
        'title' => 'Informations personnelles',
        'subtitle' => 'Gérez votre nom public, votre adresse de connexion et la langue du tableau de bord.',
        'body' => $infoCardBody,
        'footer' => '<button type="submit" class="pc-btn pc-btn--primary">Enregistrer</button>',
    ])) ?>
</form>
<?php
$infoTabHtml = (string) ob_get_clean();

// ---- Tab 2: Sécurité ----
ob_start();
?>
<div class="pc-stack pc-stack--lg">
    <!-- 1. Password change -->
    <form method="post" action="/admin/profile/password" novalidate>
        <?= $this->raw($this->csrfField()) ?>
        <?php ob_start(); ?>
        <div class="pc-stack pc-stack--md">
            <?= $this->raw($this->insert('admin::components/field', [
                'id' => 'profile-current-password',
                'name' => 'current_password',
                'label' => 'Mot de passe actuel',
                'type' => 'password',
                'value' => '',
                'autocomplete' => 'current-password',
                'required' => true,
                'error' => $passwordErrors['current_password'] ?? null,
            ])) ?>

            <?= $this->raw($this->insert('admin::components/field', [
                'id' => 'profile-new-password',
                'name' => 'password',
                'label' => 'Nouveau mot de passe',
                'type' => 'password',
                'value' => '',
                'placeholder' => 'Minimum 12 caractères',
                'autocomplete' => 'new-password',
                'required' => true,
                'error' => $passwordErrors['password'] ?? null,
            ])) ?>

            <div class="pc-strength" data-prime-password-strength="profile-new-password" data-prime-level="0" aria-live="polite">
                <div class="pc-strength__bars" aria-hidden="true">
                    <span class="pc-strength__bar"></span>
                    <span class="pc-strength__bar"></span>
                    <span class="pc-strength__bar"></span>
                    <span class="pc-strength__bar"></span>
                </div>
                <span class="pc-strength__label" data-prime-strength-label>
                    Minimum 12 caractères (majuscules, chiffres et symboles recommandés).
                </span>
            </div>

            <?= $this->raw($this->insert('admin::components/field', [
                'id' => 'profile-new-password-confirmation',
                'name' => 'password_confirmation',
                'label' => 'Confirmer le nouveau mot de passe',
                'type' => 'password',
                'value' => '',
                'autocomplete' => 'new-password',
                'required' => true,
                'error' => $passwordErrors['password_confirmation'] ?? null,
            ])) ?>
        </div>
        <?php $pwdCardBody = (string) ob_get_clean(); ?>

        <?= $this->raw($this->insert('admin::components/card', [
            'title' => 'Changer le mot de passe',
            'subtitle' => 'La modification déconnecte automatiquement vos autres appareils tout en maintenant cette session active.',
            'body' => $pwdCardBody,
            'footer' => '<button type="submit" class="pc-btn pc-btn--primary">Mettre à jour le mot de passe</button>',
        ])) ?>
    </form>

    <!-- 2. Two-factor authentication (TOTP) -->
    <?php ob_start(); ?>
    <div class="pc-stack pc-stack--md">
        <?php if ($twoFactorError !== null): ?>
            <?= $this->raw($this->insert('admin::components/alert', [
                'variant' => 'danger',
                'message' => $twoFactorError,
            ])) ?>
        <?php endif; ?>

        <?php if ($recoveryCodesOnce !== null && $recoveryCodesOnce !== []): ?>
            <?php $recoveryText = implode("\n", $recoveryCodesOnce); ?>
            <?= $this->raw($this->insert('admin::components/alert', [
                'variant' => 'warning',
                'title' => 'Enregistrez vos codes de récupération maintenant',
                'message' => 'Ces 8 codes à usage unique ne seront plus jamais affichés. Conservez-les dans un gestionnaire de mots de passe sécurisé.',
            ])) ?>

            <ul class="pc-recovery-grid" aria-label="Codes de récupération 2FA">
                <?php foreach ($recoveryCodesOnce as $rc): ?>
                    <li class="pc-recovery-grid__item"><?= $this->e($rc) ?></li>
                <?php endforeach; ?>
            </ul>

            <div class="pc-row pc-row--sm">
                <button
                    type="button"
                    class="pc-btn pc-btn--secondary pc-btn--sm"
                    data-prime-copy-text="<?= $this->e($recoveryText) ?>"
                    data-prime-copy-feedback="Codes de récupération copiés !"
                >
                    Copier les codes
                </button>
                <button
                    type="button"
                    class="pc-btn pc-btn--secondary pc-btn--sm"
                    data-prime-download-text="<?= $this->e($recoveryText . "\n") ?>"
                    data-prime-download-filename="prime-cms-recovery-codes.txt"
                >
                    Télécharger (.txt)
                </button>
            </div>
        <?php endif; ?>

        <?php if (!$profileUser->hasTwoFactor()): ?>
            <?php if ($pendingTwoFactor === null): ?>
                <p class="pc-text-sm pc-text-muted">
                    Protégez votre compte avec un code temporaire à 6 chiffres généré par votre application TOTP
                    (Aegis, 1Password, Bitwarden, Google Authenticator…).
                </p>
                <form method="post" action="/admin/profile/2fa/start">
                    <?= $this->raw($this->csrfField()) ?>
                    <button type="submit" class="pc-btn pc-btn--primary">Activer la 2FA (TOTP)</button>
                </form>
            <?php else: ?>
                <p class="pc-text-sm">
                    <strong>Étape 1 :</strong> Ajoutez cette clé secrète dans votre application d'authentification (ou ouvrez le lien <code>otpauth://</code>) :
                </p>

                <div class="pc-totp-secret-box">
                    <span class="pc-totp-secret-code" id="pc-totp-secret-display"><?= $this->e($pendingTwoFactor['formatted']) ?></span>
                    <button
                        type="button"
                        class="pc-btn pc-btn--secondary pc-btn--sm"
                        data-prime-copy-text="<?= $this->e($pendingTwoFactor['secret']) ?>"
                        data-prime-copy-feedback="Clé secrète copiée !"
                    >
                        Copier la clé
                    </button>
                </div>

                <p class="pc-text-xs pc-text-muted">
                    Lien direct :
                    <a href="<?= $this->e($pendingTwoFactor['uri']) ?>" class="pc-auth-link">
                        Ouvrir dans l'application d'authentification (otpauth://)
                    </a>
                </p>

                <form method="post" action="/admin/profile/2fa/confirm" class="pc-stack pc-stack--md">
                    <?= $this->raw($this->csrfField()) ?>
                    <?= $this->raw($this->insert('admin::components/field', [
                        'id' => 'profile-2fa-confirm-code',
                        'name' => 'code',
                        'label' => 'Étape 2 : Saisissez le code à 6 chiffres affiché',
                        'type' => 'text',
                        'value' => '',
                        'placeholder' => '000000',
                        'inputmode' => 'numeric',
                        'autocomplete' => 'one-time-code',
                        'maxlength' => 6,
                        'inputClass' => 'pc-auth-otp-input',
                        'required' => true,
                    ])) ?>
                    <div class="pc-row pc-row--sm">
                        <button type="submit" class="pc-btn pc-btn--primary">Confirmer et activer</button>
                        <button type="submit" formaction="/admin/profile/2fa/cancel" class="pc-btn pc-btn--ghost" formnovalidate>Annuler</button>
                    </div>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <div class="pc-row pc-row--sm">
                <?= $this->raw($this->insert('admin::components/badge', [
                    'label' => '2FA active',
                    'variant' => 'success',
                    'dot' => true,
                ])) ?>
                <span class="pc-text-sm pc-text-muted">
                    <?= $this->e((string) $remainingRecoveryCount) ?> code<?= $remainingRecoveryCount > 1 ? 's' : '' ?> de récupération restant<?= $remainingRecoveryCount > 1 ? 's' : '' ?>.
                </span>
            </div>

            <form method="post" action="/admin/profile/2fa/recovery" class="pc-stack pc-stack--sm">
                <?= $this->raw($this->csrfField()) ?>
                <?= $this->raw($this->insert('admin::components/field', [
                    'id' => 'profile-2fa-regen-password',
                    'name' => 'current_password',
                    'label' => 'Mot de passe actuel (pour régénérer les 8 codes de récupération)',
                    'type' => 'password',
                    'value' => '',
                    'autocomplete' => 'current-password',
                    'required' => true,
                ])) ?>
                <div>
                    <button type="submit" class="pc-btn pc-btn--secondary">Régénérer les codes de récupération</button>
                </div>
            </form>

            <hr>

            <form
                method="post"
                action="/admin/profile/2fa/disable"
                class="pc-stack pc-stack--sm"
                data-prime-confirm-form="Désactiver l'authentification à deux facteurs sur votre compte ?"
            >
                <?= $this->raw($this->csrfField()) ?>
                <div class="pc-grid pc-grid--2">
                    <?= $this->raw($this->insert('admin::components/field', [
                        'id' => 'profile-2fa-disable-password',
                        'name' => 'current_password',
                        'label' => 'Mot de passe actuel',
                        'type' => 'password',
                        'value' => '',
                        'autocomplete' => 'current-password',
                        'required' => true,
                    ])) ?>
                    <?= $this->raw($this->insert('admin::components/field', [
                        'id' => 'profile-2fa-disable-code',
                        'name' => 'code',
                        'label' => 'Code TOTP ou code de récupération',
                        'type' => 'text',
                        'value' => '',
                        'placeholder' => '000000 ou xxxx-xxxx',
                        'autocomplete' => 'one-time-code',
                        'required' => true,
                    ])) ?>
                </div>
                <div>
                    <button type="submit" class="pc-btn pc-btn--danger">Désactiver la 2FA</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
    <?php $twoFactorCardBody = (string) ob_get_clean(); ?>

    <?= $this->raw($this->insert('admin::components/card', [
        'title' => 'Authentification à deux facteurs (TOTP)',
        'subtitle' => 'Double vérification via application d\'authentification standard RFC 6238.',
        'body' => $twoFactorCardBody,
    ])) ?>

    <!-- 3. Logout other devices -->
    <form method="post" action="/admin/profile/logout-devices" novalidate>
        <?= $this->raw($this->csrfField()) ?>
        <?php ob_start(); ?>
        <div class="pc-stack pc-stack--md">
            <?php if ($devicesError !== null): ?>
                <?= $this->raw($this->insert('admin::components/alert', [
                    'variant' => 'danger',
                    'message' => $devicesError,
                ])) ?>
            <?php endif; ?>
            <?= $this->raw($this->insert('admin::components/field', [
                'id' => 'profile-devices-password',
                'name' => 'current_password',
                'label' => 'Confirmez votre mot de passe actuel',
                'type' => 'password',
                'value' => '',
                'autocomplete' => 'current-password',
                'required' => true,
            ])) ?>
        </div>
        <?php $devicesCardBody = (string) ob_get_clean(); ?>

        <?= $this->raw($this->insert('admin::components/card', [
            'title' => 'Sessions et appareils connectés',
            'subtitle' => 'Révoquez immédiatement toutes vos autres sessions actives et cookies « Rester connecté ».',
            'body' => $devicesCardBody,
            'footer' => '<button type="submit" class="pc-btn pc-btn--secondary">Déconnecter tous mes autres appareils</button>',
        ])) ?>
    </form>
</div>
<?php
$securityTabHtml = (string) ob_get_clean();
?>

<?= $this->raw($this->insert('admin::components/tabs', [
    'id' => 'profile-tabs',
    'label' => 'Sections du profil',
    'activeTab' => $activeTab,
    'tabs' => [
        [
            'id' => 'info',
            'label' => 'Informations',
            'content' => $infoTabHtml,
        ],
        [
            'id' => 'security',
            'label' => 'Sécurité',
            'badge' => $profileUser->hasTwoFactor() ? '2FA' : null,
            'content' => $securityTabHtml,
        ],
    ],
])) ?>

<?php $this->stop(); ?>
