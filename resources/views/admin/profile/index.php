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
            'label' => $this->t('admin.view_profile_index.identifiant_public'),
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
            'label' => $this->t('admin.view_profile_index.adresse_email'),
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
                        'label' => $role->label(),
                        'variant' => $role->isAdministrator() ? 'brand' : 'info',
                    ])) ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php $infoCardBody = (string) ob_get_clean(); ?>

    <?= $this->raw($this->insert('admin::components/card', [
        'title' => $this->t('admin.profile.personal_info'),
        'subtitle' => $this->t('admin.view_profile_index.gerez_votre_nom_public_votre_adresse_de_connexion_et_la_lang'),
        'body' => $infoCardBody,
        'footer' => '<button type="submit" class="pc-btn pc-btn--primary">' . $this->e($this->t('core.actions.save')) . '</button>',
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

            <label class="pc-checkbox-card">
                <input type="checkbox" name="revoke_api_tokens" value="1" checked>
                <span>
                    <span class="pc-checkbox-card__title"><?= $this->e($this->t('admin.api_tokens.revoke_on_password')) ?></span>
                    <span class="pc-checkbox-card__desc"><?= $this->e($this->t('admin.api_tokens.revoke_on_password_help')) ?></span>
                </span>
            </label>
        </div>
        <?php $pwdCardBody = (string) ob_get_clean(); ?>

        <?= $this->raw($this->insert('admin::components/card', [
            'title' => $this->t('admin.view_profile_index.changer_le_mot_de_passe'),
            'subtitle' => $this->t('admin.view_profile_index.la_modification_deconnecte_automatiquement_vos_autres_appare'),
            'body' => $pwdCardBody,
            'footer' => '<button type="submit" class="pc-btn pc-btn--primary">' . $this->e($this->t('admin.profile.update_password')) . '</button>',
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
            <?php if (!empty($twoFactorRequired)): ?>
                <div class="pc-alert pc-alert--warning" role="alert" data-prime-two-factor-required>
                    <?= $this->raw($this->icon('shield', 18)) ?>
                    <p><?= $this->e($this->t('admin.profile.two_factor_required')) ?></p>
                </div>
            <?php endif; ?>
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
                    <?= $this->raw($this->t('admin.profile.two_factor_step1')) ?>
                </p>

                <div class="pc-totp-qr">
                    <div class="pc-totp-qr__code" data-prime-totp-qr>
                        <?= $this->raw(str_replace('aria-label="QR Code"', 'aria-label="' . $this->e($this->t('admin.profile.two_factor_qr_label')) . '"', \Prime\Support\Barcode::toSvg($pendingTwoFactor['uri'], 4, 4))) ?>
                    </div>
                    <div class="pc-totp-qr__aside">
                        <p class="pc-text-sm pc-text-muted"><?= $this->e($this->t('admin.profile.two_factor_manual_key')) ?></p>
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
                            <a href="<?= $this->e($pendingTwoFactor['uri']) ?>" class="pc-auth-link"><?= $this->e($this->t('admin.profile.two_factor_open_app')) ?></a>
                        </p>
                    </div>
                </div>

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
            'footer' => '<button type="submit" class="pc-btn pc-btn--secondary">' . $this->e($this->t('admin.profile.logout_others')) . '</button>',
        ])) ?>
    </form>
</div>
<?php
$securityTabHtml = (string) ob_get_clean();

// ---- Tab 3: Jetons d'API ----
ob_start();
?>
<div class="pc-stack pc-stack--lg">
    <?php if ($apiNewToken !== null): ?>
        <section class="pc-card pc-new-token" aria-labelledby="api-new-token-title">
            <div class="pc-card__body pc-stack pc-stack--md">
                <?= $this->raw($this->insert('admin::components/alert', [
                    'variant' => 'warning',
                    'title' => $this->t('admin.view_profile_index.jeton_api_nouveau_titre'),
                    'message' => $this->t('admin.view_profile_index.jeton_api_nouveau_message'),
                ])) ?>
                <h3 class="pc-sr-only" id="api-new-token-title"><?= $this->e($this->t('admin.view_profile_index.jeton_api_nouveau_titre')) ?></h3>
                <div class="pc-new-token__row">
                    <code class="pc-new-token__value" data-prime-new-token><?= $this->e($apiNewToken['token']) ?></code>
                    <button type="button" class="pc-btn pc-btn--secondary"
                            data-prime-copy-text="<?= $this->e($apiNewToken['token']) ?>"
                            data-prime-copy-feedback="<?= $this->e($this->t('admin.profile.index.jeton_copie')) ?>">
                        <?= $this->raw($this->icon('copy', 16)) ?>
                        <span><?= $this->e($this->t('admin.profile.index.copier')) ?></span>
                    </button>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <form method="post" action="/admin/profile/api-tokens" class="pc-card" novalidate data-prime-api-token-form>
        <?= $this->raw($this->csrfField()) ?>
        <header class="pc-card__header">
            <div>
                <h2 class="pc-card__title"><?= $this->e($this->t('admin.api_tokens.create_title')) ?></h2>
                <p class="pc-card__subtitle"><?= $this->e($this->t('admin.view_profile_index.jeton_api_capacites_aide')) ?></p>
            </div>
        </header>
        <div class="pc-card__body pc-stack pc-stack--lg">
            <div class="pc-token-form__basics">
                <?= $this->raw($this->insert('admin::components/field', [
                    'id' => 'api-token-name',
                    'name' => 'name',
                    'label' => $this->t('admin.view_profile_index.jeton_api_nom'),
                    'type' => 'text',
                    'value' => '',
                    'placeholder' => $this->t('admin.view_profile_index.jeton_api_nom_placeholder'),
                    'required' => true,
                    'maxlength' => 100,
                ])) ?>
                <div class="pc-field">
                    <label class="pc-field__label" for="api-token-expiration"><?= $this->e($this->t('admin.view_profile_index.jeton_api_expiration')) ?></label>
                    <select id="api-token-expiration" name="expires_days" class="pc-select">
                        <?php if ($apiTokenMaxDays === 0): ?>
                            <option value="0" selected><?= $this->e($this->t('admin.view_profile_index.jeton_api_expiration_none')) ?></option>
                        <?php endif; ?>
                        <option value="30"><?= $this->e($this->t('admin.view_profile_index.jeton_api_expiration_30')) ?></option>
                        <option value="90" <?= $apiTokenMaxDays !== 0 ? 'selected' : '' ?>><?= $this->e($this->t('admin.view_profile_index.jeton_api_expiration_90')) ?></option>
                        <option value="365"><?= $this->e($this->t('admin.view_profile_index.jeton_api_expiration_365')) ?></option>
                    </select>
                </div>
            </div>

            <div class="pc-stack pc-stack--md">
                <div class="pc-row pc-row--between">
                    <span class="pc-field__label" id="api-token-abilities"><?= $this->e($this->t('admin.view_profile_index.jeton_api_capacites')) ?></span>
                    <div class="pc-row pc-row--sm" role="group" aria-labelledby="api-token-abilities">
                        <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-api-token-select="readonly"><?= $this->e($this->t('admin.view_profile_index.jeton_api_raccourci_lecture_seule')) ?></button>
                        <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-api-token-select="all"><?= $this->e($this->t('admin.view_profile_index.jeton_api_raccourci_tout_cocher')) ?></button>
                        <button type="button" class="pc-btn pc-btn--ghost pc-btn--sm" data-prime-api-token-select="none"><?= $this->e($this->t('admin.api_tokens.select_none')) ?></button>
                    </div>
                </div>
                <div class="pc-cap-matrix">
                    <?php foreach ($apiAbilityGroups as $groupName => $group): ?>
                        <?php $owned = array_filter($group, static fn (string $label, string $cap): bool => $profileUser->can($cap), ARRAY_FILTER_USE_BOTH); ?>
                        <?php if ($owned === []): ?>
                            <?php continue; ?>
                        <?php endif; ?>
                        <fieldset class="pc-cap-group pc-cap-group--fieldset">
                            <legend class="pc-cap-group__legend"><?= $this->e($groupName) ?></legend>
                            <div class="pc-cap-group__list">
                                <?php foreach ($owned as $capKey => $capLabel): ?>
                                    <label class="pc-cap-item">
                                        <input type="checkbox" name="abilities[]" value="<?= $this->e($capKey) ?>">
                                        <span><?= $this->e($capLabel) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <footer class="pc-card__footer">
            <span class="pc-text-sm pc-text-muted"><?= $this->e($this->t('admin.view_profile_index.jeton_api_afficher_une_fois')) ?></span>
            <button type="submit" class="pc-btn pc-btn--primary"><?= $this->e($this->t('admin.view_profile_index.jeton_api_creer')) ?></button>
        </footer>
    </form>

    <section class="pc-card" aria-labelledby="api-tokens-list-title">
        <header class="pc-card__header">
            <h2 class="pc-card__title" id="api-tokens-list-title"><?= $this->e($this->t('admin.view_profile_index.jeton_api_liste_titre')) ?></h2>
        </header>
        <?php if ($apiTokens !== []): ?>
            <div class="pc-card__body pc-card__body--flush">
                <?= $this->raw($this->insert('admin::components/api-tokens-table', [
                    'tokens' => $apiTokens,
                    'revokeAction' => '/admin/profile/api-tokens/{id}/revoke',
                ])) ?>
            </div>
        <?php else: ?>
            <div class="pc-card__body"><p class="pc-text-sm pc-text-muted"><?= $this->e($this->t('admin.view_profile_index.jeton_api_aucun')) ?></p></div>
        <?php endif; ?>
    </section>
</div>
<?php
$apiTokensTabHtml = (string) ob_get_clean();
?>

<?= $this->raw($this->insert('admin::components/tabs', [
    'id' => 'profile-tabs',
    'label' => $this->t('admin.view_profile_index.sections_du_profil'),
    'activeTab' => $activeTab,
    'tabs' => [
        [
            'id' => 'info',
            'label' => $this->t('admin.view_profile_index.onglet_informations'),
            'content' => $infoTabHtml,
        ],
        [
            'id' => 'security',
            'label' => $this->t('admin.profile.security'),
            'badge' => $profileUser->hasTwoFactor() ? '2FA' : null,
            'content' => $securityTabHtml,
        ],
        [
            'id' => 'api-tokens',
            'label' => $this->t('admin.view_profile_index.jeton_api_onglet'),
            'content' => $apiTokensTabHtml,
        ],
    ],
])) ?>

<?php $this->stop(); ?>
