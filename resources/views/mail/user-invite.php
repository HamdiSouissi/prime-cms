<?php

declare(strict_types=1);

/**
 * New user invitation (48h set-password link) e-mail template.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $displayName
 * @var string $inviteUrl
 * @var int $ttlHours
 * @var string $siteName
 */
$this->layout('mail::layout');
?>
<?php $this->start('content'); ?>
<h1 style="margin:0 0 16px;font-size:20px;font-weight:700;color:#111827;">Votre compte <?= $this->e($siteName) ?> est prêt</h1>
<p style="margin:0 0 14px;">Bonjour <?= $this->e($displayName) ?>,</p>
<p style="margin:0 0 20px;">Un administrateur vient de créer votre compte sur <strong><?= $this->e($siteName) ?></strong>. Cliquez ci-dessous pour définir votre mot de passe et activer votre accès.</p>
<p style="margin:0 0 22px;">
    <a href="<?= $this->e($inviteUrl) ?>" style="display:inline-block;padding:12px 22px;background-color:#F97316;color:#FFFFFF;text-decoration:none;font-weight:600;border-radius:8px;">
        Définir mon mot de passe
    </a>
</p>
<p style="margin:0 0 12px;color:#4B5563;font-size:13px;">Ce lien d'invitation est à usage unique et valable pendant <strong><?= $this->e((string) $ttlHours) ?> heures</strong>.</p>
<p style="margin:0;color:#6B7280;font-size:12px;word-break:break-all;">Lien direct :<br><?= $this->e($inviteUrl) ?></p>
<?php $this->stop(); ?>
