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
    <h2 class="pc-auth-card__title">Lien invalide ou expiré</h2>
    <p class="pc-auth-card__subtitle">
        Ce lien de réinitialisation n'est plus valable. Les liens sont à usage unique et expirent automatiquement après 60 minutes.
    </p>
</div>

<?= $this->raw($this->insert('admin::components/alert', [
    'variant' => 'warning',
    'message' => 'Pour définir un nouveau mot de passe, effectuez une nouvelle demande ci-dessous.',
])) ?>

<a href="/admin/forgot-password" class="pc-btn pc-btn--primary pc-auth-submit">
    <span>Demander un nouveau lien</span>
</a>

<div class="pc-auth-card__footer">
    <a href="/admin/login" class="pc-auth-link">← Retour à la connexion</a>
</div>
<?php $this->stop(); ?>
