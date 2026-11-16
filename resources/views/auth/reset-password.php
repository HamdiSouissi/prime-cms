<?php

declare(strict_types=1);

/**
 * Reset password form view (`GET/POST /admin/reset-password/{token}`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $token
 * @var array<string, string> $errors
 * @var string $siteName
 */
$this->layout('auth::layout', [
    'pageTitle' => 'Nouveau mot de passe',
    'siteName' => $siteName,
]);
?>
<?php $this->start('content'); ?>
<div class="pc-auth-card__header">
    <h2 class="pc-auth-card__title"><?= $this->e($this->t('admin.auth.reset-password.choisir_nouveau_mot_passe')) ?></h2>
    <p class="pc-auth-card__subtitle">
        <?= $this->e($this->t('admin.auth.reset-password.saisissez_mot_passe_robuste_moins')) ?>
    </p>
</div>

<?php if (!empty($errors['general'])): ?>
    <?= $this->raw($this->insert('admin::components/alert', [
        'variant' => 'danger',
        'message' => $errors['general'],
    ])) ?>
<?php endif; ?>

<form method="post" action="/admin/reset-password/<?= $this->e($token) ?>" class="pc-auth-form" novalidate>
    <?= $this->raw($this->csrfField()) ?>

    <?= $this->raw($this->insert('admin::components/field', [
        'id' => 'reset-password',
        'name' => 'password',
        'label' => 'Nouveau mot de passe',
        'type' => 'password',
        'value' => '',
        'placeholder' => 'Minimum 12 caractères',
        'autocomplete' => 'new-password',
        'required' => true,
        'autofocus' => true,
        'error' => $errors['password'] ?? null,
    ])) ?>

    <div class="pc-strength" data-prime-password-strength="reset-password" data-prime-level="0" aria-live="polite">
        <div class="pc-strength__bars" aria-hidden="true">
            <span class="pc-strength__bar"></span>
            <span class="pc-strength__bar"></span>
            <span class="pc-strength__bar"></span>
            <span class="pc-strength__bar"></span>
        </div>
        <span class="pc-strength__label" data-prime-strength-label>
            <?= $this->e($this->t('admin.auth.reset-password.minimum_12_caracteres_phrase_secrete')) ?>
        </span>
    </div>

    <?= $this->raw($this->insert('admin::components/field', [
        'id' => 'reset-password-confirmation',
        'name' => 'password_confirmation',
        'label' => 'Confirmer le mot de passe',
        'type' => 'password',
        'value' => '',
        'placeholder' => 'Ressaisissez le mot de passe',
        'autocomplete' => 'new-password',
        'required' => true,
        'error' => $errors['password_confirmation'] ?? null,
    ])) ?>

    <button type="submit" class="pc-btn pc-btn--primary pc-auth-submit">
        <span><?= $this->e($this->t('admin.auth.reset-password.enregistrer_nouveau_mot_passe')) ?></span>
    </button>
</form>

<div class="pc-auth-card__footer">
    <a href="/admin/login" class="pc-auth-link"><?= $this->e($this->t('admin.auth.reset-password.retour_a_connexion')) ?></a>
</div>
<?php $this->stop(); ?>
