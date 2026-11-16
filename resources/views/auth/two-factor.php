<?php

declare(strict_types=1);

/**
 * Intermediate 2FA challenge view (`GET/POST /admin/login/two-factor`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var string|null $error
 * @var string $siteName
 */
$this->layout('auth::layout', [
    'pageTitle' => 'Vérification en deux étapes',
    'siteName' => $siteName,
]);
?>
<?php $this->start('content'); ?>
<div class="pc-auth-card__header">
    <h2 class="pc-auth-card__title"><?= $this->e($this->t('admin.auth.two-factor.verification_deux_etapes')) ?></h2>
    <p class="pc-auth-card__subtitle">
        <?= $this->e($this->t('admin.auth.two-factor.saisissez_code_genere_application_authentification')) ?>
    </p>
</div>

<?php if (!empty($error)): ?>
    <?= $this->raw($this->insert('admin::components/alert', [
        'variant' => 'danger',
        'message' => $error,
    ])) ?>
<?php endif; ?>

<form method="post" action="/admin/login/two-factor" class="pc-auth-form" novalidate>
    <?= $this->raw($this->csrfField()) ?>

    <?= $this->raw($this->insert('admin::components/field', [
        'id' => 'two-factor-code',
        'name' => 'code',
        'label' => 'Code à 6 chiffres',
        'type' => 'text',
        'value' => '',
        'placeholder' => '000000',
        'autocomplete' => 'one-time-code',
        'inputmode' => 'numeric',
        'maxlength' => 12,
        'inputClass' => 'pc-auth-otp-input',
        'required' => true,
        'autofocus' => true,
        'help' => 'Ouvrez votre application TOTP (Aegis, Google Authenticator, 1Password…) et saisissez le code affiché.',
    ])) ?>

    <button type="submit" class="pc-btn pc-btn--primary pc-auth-submit">
        <span><?= $this->e($this->t('admin.auth.two-factor.verifier_connecter')) ?></span>
    </button>

    <div class="pc-auth-form__row">
        <button type="button" class="pc-auth-link-btn" data-prime-2fa-toggle>
            <?= $this->e($this->t('admin.auth.two-factor.utiliser_code_recuperation')) ?>
        </button>
    </div>
</form>

<div class="pc-auth-card__footer">
    <a href="/admin/login?cancel_2fa=1" class="pc-auth-link"><?= $this->e($this->t('admin.auth.two-factor.retour_a_connexion')) ?></a>
</div>
<?php $this->stop(); ?>
