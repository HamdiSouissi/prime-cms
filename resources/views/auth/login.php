<?php

declare(strict_types=1);

/**
 * Login view (`GET/POST /admin/login`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $email
 * @var bool $remember
 * @var string|null $error
 * @var string|null $status
 * @var string $siteName
 */
$this->layout('auth::layout', [
    'pageTitle' => 'Connexion',
    'siteName' => $siteName,
]);
?>
<?php $this->start('content'); ?>
<div class="pc-auth-card__header">
    <h2 class="pc-auth-card__title">Connexion</h2>
    <p class="pc-auth-card__subtitle"><?= $this->e($this->t('admin.auth.login.sous_titre', ['site' => $siteName])) ?></p>
</div>

<?php if (!empty($status)): ?>
    <?= $this->raw($this->insert('admin::components/alert', [
        'variant' => 'success',
        'message' => $status,
    ])) ?>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <?= $this->raw($this->insert('admin::components/alert', [
        'variant' => 'danger',
        'message' => $error,
    ])) ?>
<?php endif; ?>

<form method="post" action="/admin/login" class="pc-auth-form" novalidate>
    <?= $this->raw($this->csrfField()) ?>

    <?= $this->raw($this->insert('admin::components/field', [
        'id' => 'login-email',
        'name' => 'email',
        'label' => 'Adresse e-mail',
        'type' => 'email',
        'value' => $email,
        'placeholder' => 'vous@exemple.fr',
        'autocomplete' => 'username',
        'required' => true,
        'autofocus' => $email === '',
    ])) ?>

    <?= $this->raw($this->insert('admin::components/field', [
        'id' => 'login-password',
        'name' => 'password',
        'label' => 'Mot de passe',
        'type' => 'password',
        'value' => '',
        'placeholder' => 'Votre mot de passe',
        'autocomplete' => 'current-password',
        'required' => true,
        'autofocus' => $email !== '',
    ])) ?>

    <div class="pc-auth-form__row">
        <label class="pc-check">
            <input type="checkbox" name="remember" value="1"<?= $remember ? ' checked' : '' ?>>
            <span><?= $this->e($this->t('admin.auth.login.rester_connecte_30_jours')) ?></span>
        </label>
        <a href="/admin/forgot-password" class="pc-auth-link"><?= $this->e($this->t('core.auth.forgot_password')) ?></a>
    </div>

    <button type="submit" class="pc-btn pc-btn--primary pc-auth-submit">
        <span>Se connecter</span>
    </button>
</form>

<div class="pc-auth-card__footer">
    <a href="/" class="pc-auth-link">← Retour au site public</a>
</div>
<?php $this->stop(); ?>
