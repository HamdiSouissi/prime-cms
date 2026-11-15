<?php

declare(strict_types=1);

/**
 * Password reset e-mail template.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $displayName
 * @var string $resetUrl
 * @var int $ttlMinutes
 * @var string $siteName
 */
$this->layout('mail::layout');
?>
<?php $this->start('content'); ?>
<h1 style="margin:0 0 16px;font-size:20px;font-weight:700;color:#111827;">Réinitialisation de votre mot de passe</h1>
<p style="margin:0 0 14px;">Bonjour <?= $this->e($displayName) ?>,</p>
<p style="margin:0 0 20px;">Une demande de réinitialisation de mot de passe a été effectuée pour votre compte sur <strong><?= $this->e($siteName) ?></strong>.</p>
<p style="margin:0 0 22px;">
    <a href="<?= $this->e($resetUrl) ?>" style="display:inline-block;padding:12px 22px;background-color:#F97316;color:#FFFFFF;text-decoration:none;font-weight:600;border-radius:8px;">
        Choisir un nouveau mot de passe
    </a>
</p>
<p style="margin:0 0 12px;color:#4B5563;font-size:13px;">Ce lien est à usage unique et expire dans <strong><?= $this->e((string) $ttlMinutes) ?> minutes</strong>.</p>
<p style="margin:0;color:#6B7280;font-size:12px;word-break:break-all;">Si le bouton ne fonctionne pas, copiez-collez ce lien dans votre navigateur :<br><?= $this->e($resetUrl) ?></p>
<?php $this->stop(); ?>
