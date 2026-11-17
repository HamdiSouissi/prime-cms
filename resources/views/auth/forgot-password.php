<?php

declare(strict_types=1);

/**
 * Forgot password request view (`GET/POST /admin/forgot-password`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $email
 * @var string|null $status
 * @var string|null $error
 * @var string $siteName
 */
$this->layout('auth::layout', [
    'pageTitle' => $this->t('admin.auth_forgot-password.mot_de_passe_oublie'),
    'siteName' => $siteName,
]);
?>
<?php $this->start('content'); ?>
<div class="pc-auth-card__header">
    <h2 class="pc-auth-card__title"><?= $this->e($this->t('admin.auth.forgot-password.mot_passe_oublie')) ?></h2>
    <p class="pc-auth-card__subtitle">
        <?= $this->e($this->t('admin.auth.forgot-password.indiquez_adresse_e_mail_recevoir')) ?>
    </p>
</div>

<?php if (!empty($status)): ?>
    <?= $this->raw($this->insert('admin::components/alert', [
        'variant' => 'info',
        'message' => $status,
    ])) ?>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <?= $this->raw($this->insert('admin::components/alert', [
        'variant' => 'danger',
        'message' => $error,
    ])) ?>
<?php endif; ?>

<form method="post" action="/admin/forgot-password" class="pc-auth-form" novalidate>
    <?= $this->raw($this->csrfField()) ?>

    <?= $this->raw($this->insert('admin::components/field', [
        'id' => 'forgot-email',
        'name' => 'email',
        'label' => 'Adresse e-mail',
        'type' => 'email',
        'value' => $email,
        'placeholder' => 'vous@exemple.fr',
        'autocomplete' => 'username',
        'required' => true,
        'autofocus' => true,
    ])) ?>

    <button type="submit" class="pc-btn pc-btn--primary pc-auth-submit">
        <span><?= $this->e($this->t('admin.auth.forgot-password.envoyer_lien_reinitialisation')) ?></span>
    </button>
</form>

<div class="pc-auth-card__footer">
    <a href="/admin/login" class="pc-auth-link"><?= $this->e($this->t('admin.auth.forgot-password.retour_a_connexion')) ?></a>
</div>
<?php $this->stop(); ?>
