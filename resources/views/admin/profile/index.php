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
    'title' => $this->t('admin.view_profile_index.mon_profil'),
    'subtitle' => $profileUser->displayName . ' · ' . $profileUser->primaryRoleName(),
    'breadcrumbs' => $breadcrumbs,
])) ?>

<?php
// ---- Tab 1: Informations ----
ob_start();
?>
<form method="post" action="/admin/profile/info" class="pc-stack pc-stack--lg pc-form-narrow" novalidate>
    <?= $this->raw($this->csrfField()) ?>

    <?php ob_start(); ?>
    <div class="pc-stack pc-stack--md">
        <?php if (!empty($canPickAvatar)): ?>
            <div class="pc-field">
                <span class="pc-field__label"><?= $this->e($this->t('admin.profile.index.photo_profil_avatar')) ?></span>
                <div
                    class="pc-media-field"
                    data-prime-media-field
                    data-prime-media-multiple="0"
                    data-prime-media-kinds="image"
                    data-prime-media-title="<?= $this->e($this->t('admin.profile.index.choisir_avatar')) ?>"
                >
                    <script type="application/json" data-prime-media-initial><?= $this->raw((string) ($avatarInitialJson ?? '[]')) ?></script>
                    <input
                        type="hidden"
                        name="avatar_media_id"
                        value="<?= $this->e($profileUser->avatarMediaId !== null ? (string) $profileUser->avatarMediaId : '') ?>"
                        data-prime-media-input
                    >
                    <div class="pc-media-field__preview" data-prime-media-preview hidden></div>
                    <div class="pc-media-field__buttons">
                        <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-media-choose>
                            <?= $this->raw($this->icon('image', 14)) ?>
                            <span data-prime-media-choose-label><?= $this->e($this->t('admin.profile.index.choisir_photo')) ?></span>
                        </button>
                        <button type="button" class="pc-btn pc-btn--ghost pc-btn--sm" data-prime-media-clear hidden><?= t('admin.profile.remove_avatar') ?></button>
                    </div>
                </div>
                <?php if (isset($infoErrors['avatar_media_id'])): ?>
                    <span class="pc-field__error"><?= $this->e($infoErrors['avatar_media_id']) ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?= $this->raw($this->insert('admin::components/field', [
            'id' => 'profile-display-name',
            'name' => 'display_name',
            'label' => $this->t('admin.view_profile_index.nom_affiche'),
            'type' => 'text',
            'value' => $oldInfo['display_name'],
            'required' => true,
            'error' => $infoErrors['display_name'] ?? null,
        ])) ?>

        <?= $this->raw($this->insert('admin::components/field', [
            'id' => 'profile-username',
            'name' => 'username',
            'label' => 'Identifiant public (URL d’auteur)',
            'type' => 'text',
            'value' => (string) ($oldInfo['username'] ?? ''),
            'help' => $this->t('admin.view_profile_index.utilise_pour_votre_page_auteur_publique_auteur') . $profileUser->authorSlug(),
            'error' => $infoErrors['username'] ?? null,
        ])) ?>

        <div class="pc-field">
            <label class="pc-label" for="profile-bio"><?= t('admin.profile.public_bio') ?></label>
            <textarea
                id="profile-bio"
                name="bio"
                rows="3"
                maxlength="1000"
                class="pc-textarea"
                placeholder="<?= $this->e($this->t('admin.profile.bio_placeholder')) ?>"
            ><?= $this->e((string) ($oldInfo['bio'] ?? '')) ?></textarea>
            <?php if (isset($infoErrors['bio'])): ?>
                <span class="pc-field__error"><?= $this->e($infoErrors['bio']) ?></span>
            <?php endif; ?>
        </div>

        <?= $this->raw($this->insert('admin::components/field', [
            'id' => 'profile-email',
            'name' => 'email',
            'label' => 'Adresse e-mail',
            'type' => 'email',
            'value' => $oldInfo['email'],
            'required' => true,
            'help' => $this->t('admin.view_profile_index.pour_changer_votre_adresse_e_mail_confirmez_avec_votre_mot_d'),
            'error' => $infoErrors['email'] ?? null,
        ])) ?>

        <?= $this->raw($this->insert('admin::components/field', [
            'id' => 'profile-current-password-email',
            'name' => 'current_password_email',
            'label' => $this->t('admin.view_profile_index.mot_de_passe_actuel_requis_uniquement_en_cas_de_changement_d'),
            'type' => 'password',
            'value' => '',
            'autocomplete' => 'current-password',
            'error' => $infoErrors['current_password_email'] ?? null,
        ])) ?>

        <?= $this->raw($this->insert('admin::components/field', [
            'id' => 'profile-locale',
            'name' => 'locale',
            'label' => $this->t('admin.view_profile_index.langue_de_l_interface'),
            'type' => 'select',
            'value' => $oldInfo['locale'],
            'options' => $localeOptions,
            'error' => $infoErrors['locale'] ?? null,
        ])) ?>

        <div>
            <span class="pc-field__label"><?= $this->e($this->t('admin.profile.index.roles_attribues')) ?></span>
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
        'subtitle' => $this->t('admin.view_profile_index.gerez_votre_nom_public_votre_adresse_de_connexion_et_la_lang'),
        'body' => $infoCardBody,
        'footer' => '<button type="submit" class="pc-btn pc-btn--primary">Enregistrer</button>',
    ])) ?>
</form>
<?php
$infoTabHtml = (string) ob_get_clean();

// ---- Tab 2: Sécurité ----
ob_start();
?>
<div class="pc-stack pc-stack--lg pc-form-narrow">
    <!-- 1. Password change -->
    <form method="post" action="/admin/profile/password" novalidate>
        <?= $this->raw($this->csrfField()) ?>
        <?php ob_start(); ?>
        <div class="pc-stack pc-stack--md">
            <?= $this->raw($this->insert('admin::components/field', [
                'id' => 'profile-current-password',
                'name' => 'current_password',
                'label' => $this->t('admin.view_profile_index.mot_de_passe_actuel'),
                'type' => 'password',
                'value' => '',
                'autocomplete' => 'current-password',
                'required' => true,
                'error' => $passwordErrors['current_password'] ?? null,
            ])) ?>

            <?= $this->raw($this->insert('admin::components/field', [
                'id' => 'profile-new-password',
                'name' => 'password',
                'label' => $this->t('admin.view_profile_index.nouveau_mot_de_passe'),
                'type' => 'password',
                'value' => '',
                'placeholder' => $this->t('admin.view_profile_index.minimum_12_caracteres'),
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
                    <?= $this->e($this->t('admin.profile.index.minimum_12_caracteres_majuscules_chiffres')) ?>
                </span>
            </div>

            <?= $this->raw($this->insert('admin::components/field', [
                'id' => 'profile-new-password-confirmation',
                'name' => 'password_confirmation',
                'label' => $this->t('admin.view_profile_index.confirmer_le_nouveau_mot_de_passe'),
                'type' => 'password',
                'value' => '',
                'autocomplete' => 'new-password',
                'required' => true,
                'error' => $passwordErrors['password_confirmation'] ?? null,
            ])) ?>
        </div>
        <?php $pwdCardBody = (string) ob_get_clean(); ?>

        <?= $this->raw($this->insert('admin::components/card', [
            'title' => $this->t('admin.view_profile_index.changer_le_mot_de_passe'),
            'subtitle' => $this->t('admin.view_profile_index.la_modification_deconnecte_automatiquement_vos_autres_appare'),
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
                'title' => $this->t('admin.view_profile_index.enregistrez_vos_codes_de_recuperation_maintenant'),
                'message' => $this->t('admin.view_profile_index.ces_8_codes_a_usage_unique_ne_seront_plus_jamais_affiches_co'),
            ])) ?>

            <ul class="pc-recovery-grid" aria-label="<?= t('admin.attr.codes_de_recuperation_2fa') ?>">
                <?php foreach ($recoveryCodesOnce as $rc): ?>
                    <li class="pc-recovery-grid__item"><?= $this->e($rc) ?></li>
                <?php endforeach; ?>
            </ul>

            <div class="pc-row pc-row--sm">
                <button
                    type="button"
                    class="pc-btn pc-btn--secondary pc-btn--sm"
                    data-prime-copy-text="<?= $this->e($recoveryText) ?>"
                    data-prime-copy-feedback="<?= $this->e($this->t('admin.profile.index.codes_recuperation_copies')) ?>"
                >
                    <?= $this->e($this->t('admin.profile.index.copier_codes')) ?>
                </button>
                <button
                    type="button"
                    class="pc-btn pc-btn--secondary pc-btn--sm"
                    data-prime-download-text="<?= $this->e($recoveryText . "\n") ?>"
                    data-prime-download-filename="prime-cms-recovery-codes.txt"
                >
                    <?= $this->e($this->t('admin.profile.index.telecharger_txt')) ?>
                </button>
            </div>
        <?php endif; ?>

        <?php if (!$profileUser->hasTwoFactor()): ?>
            <?php if ($pendingTwoFactor === null): ?>
                <p class="pc-text-sm pc-text-muted">
                    <?= $this->e($this->t('admin.profile.code_temporaire_application_totp')) ?>
                    <?= t('admin.profile.authenticator_hint') ?>
                </p>
                <form method="post" action="/admin/profile/2fa/start">
                    <?= $this->raw($this->csrfField()) ?>
                    <button type="submit" class="pc-btn pc-btn--secondary">
                        <?= $this->raw($this->icon('shield', 16)) ?>
                        <span><?= $this->e($this->t('admin.profile.index.activer_double_authentification')) ?></span>
                    </button>
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
                        data-prime-copy-feedback="<?= $this->e($this->t('admin.profile.index.cle_secrete_copiee')) ?>"
                    >
                        <?= $this->e($this->t('admin.profile.index.copier_cle')) ?>
                    </button>
                </div>

                <p class="pc-text-xs pc-text-muted">
                    <?= t('admin.profile.direct_link') ?>
                    <a href="<?= $this->e($pendingTwoFactor['uri']) ?>" class="pc-auth-link">
                        Ouvrir dans l'application d'authentification (otpauth://)
                    </a>
                </p>

                <form method="post" action="/admin/profile/2fa/confirm" class="pc-stack pc-stack--md">
                    <?= $this->raw($this->csrfField()) ?>
                    <?= $this->raw($this->insert('admin::components/field', [
                        'id' => 'profile-2fa-confirm-code',
                        'name' => 'code',
                        'label' => $this->t('admin.view_profile_index.etape_2_saisissez_le_code_a_6_chiffres_affiche'),
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
                        <button type="submit" class="pc-btn pc-btn--secondary">
                            <?= $this->raw($this->icon('shield', 16)) ?>
                            <span><?= $this->e($this->t('admin.profile.index.confirmer_activer')) ?></span>
                        </button>
                        <button type="submit" formaction="/admin/profile/2fa/cancel" class="pc-btn pc-btn--ghost" formnovalidate><?= $this->e($this->t('core.actions.cancel')) ?></button>
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
                    <?= $this->e($this->choice('admin.profile.codes_recuperation_restants', $remainingRecoveryCount)) ?>
                </span>
            </div>

            <form method="post" action="/admin/profile/2fa/recovery" class="pc-stack pc-stack--sm">
                <?= $this->raw($this->csrfField()) ?>
                <?= $this->raw($this->insert('admin::components/field', [
                    'id' => 'profile-2fa-regen-password',
                    'name' => 'current_password',
                    'label' => $this->t('admin.view_profile_index.mot_de_passe_actuel_pour_regenerer_les_8_codes_de_recuperati'),
                    'type' => 'password',
                    'value' => '',
                    'autocomplete' => 'current-password',
                    'required' => true,
                ])) ?>
                <div>
                    <button type="submit" class="pc-btn pc-btn--secondary"><?= $this->e($this->t('admin.profile.index.regenerer_codes_recuperation')) ?></button>
                </div>
            </form>

            <hr>

            <form
                method="post"
                action="/admin/profile/2fa/disable"
                class="pc-stack pc-stack--sm"
                data-prime-confirm-form="<?= $this->e($this->t('admin.profile.confirmer_desactivation_2fa')) ?>"
            >
                <?= $this->raw($this->csrfField()) ?>
                <div class="pc-stack pc-stack--md">
                    <?= $this->raw($this->insert('admin::components/field', [
                        'id' => 'profile-2fa-disable-password',
                        'name' => 'current_password',
                        'label' => $this->t('admin.view_profile_index.mot_de_passe_actuel'),
                        'type' => 'password',
                        'value' => '',
                        'autocomplete' => 'current-password',
                        'required' => true,
                    ])) ?>
                    <?= $this->raw($this->insert('admin::components/field', [
                        'id' => 'profile-2fa-disable-code',
                        'name' => 'code',
                        'label' => $this->t('admin.view_profile_index.code_totp_ou_code_de_recuperation'),
                        'type' => 'text',
                        'value' => '',
                        'placeholder' => '000000 ou xxxx-xxxx',
                        'autocomplete' => 'one-time-code',
                        'required' => true,
                    ])) ?>
                </div>
                <div>
                    <button type="submit" class="pc-btn pc-btn--danger"><?= $this->e($this->t('admin.profile.index.desactiver_2fa')) ?></button>
                </div>
            </form>
        <?php endif; ?>
    </div>
    <?php $twoFactorCardBody = (string) ob_get_clean(); ?>

    <?= $this->raw($this->insert('admin::components/card', [
        'title' => $this->t('admin.view_profile_index.authentification_a_deux_facteurs_totp'),
        'subtitle' => $this->t('admin.view_profile_index.double_verification_via_application_d_authentification_stand'),
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
                'label' => $this->t('admin.view_profile_index.confirmez_votre_mot_de_passe_actuel'),
                'type' => 'password',
                'value' => '',
                'autocomplete' => 'current-password',
                'required' => true,
            ])) ?>
        </div>
        <?php $devicesCardBody = (string) ob_get_clean(); ?>

        <?= $this->raw($this->insert('admin::components/card', [
            'title' => $this->t('admin.view_profile_index.sessions_et_appareils_connectes'),
            'subtitle' => $this->t('admin.view_profile_index.revoquez_immediatement_toutes_vos_autres_sessions_actives_et'),
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
    'label' => $this->t('admin.view_profile_index.sections_du_profil'),
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
