<?php

declare(strict_types=1);

/**
 * Expired or invalid password reset token view.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $siteName
 */
$this->layout('auth::layout', [
    'pageTitle' => 'Lien expiré',
    'siteName' => $siteName,
]);
?>
<?php $this->start('content'); ?>
<div class="pc-auth-card__header">
    <h2 class="pc-auth-card__title"><?= $this->e($this->t('admin.auth.reset-expired.lien_invalide_expire')) ?></h2>
    <p class="pc-auth-card__subtitle">
        <?= $this->e($this->t('admin.auth.reset-expired.lien_reinitialisation_valable_liens_sont')) ?>
    </p>
</div>

<?= $this->raw($this->insert('admin::components/alert', [
    'variant' => 'warning',
    'message' => 'Pour définir un nouveau mot de passe, effectuez une nouvelle demande ci-dessous.',
])) ?>

<a href="/admin/forgot-password" class="pc-btn pc-btn--primary pc-auth-submit">
    <span><?= $this->e($this->t('admin.auth.reset-expired.demander_nouveau_lien')) ?></span>
</a>

<div class="pc-auth-card__footer">
    <a href="/admin/login" class="pc-auth-link"><?= $this->e($this->t('admin.auth.reset-expired.retour_a_connexion')) ?></a>
</div>
<?php $this->stop(); ?>
