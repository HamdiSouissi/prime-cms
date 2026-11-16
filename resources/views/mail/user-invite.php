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
<h1 style="margin:0 0 16px;font-size:20px;font-weight:700;color:#111827;"><?= $this->e($this->t('mail.user-invite.votre_compte_pret', ['site' => $siteName])) ?></h1>
<p style="margin:0 0 14px;">Bonjour <?= $this->e($displayName) ?>,</p>
<p style="margin:0 0 20px;"><?= $this->raw($this->t('mail.user-invite.compte_cree', ['site' => '<strong>' . $this->e($siteName) . '</strong>'])) ?></p>
<p style="margin:0 0 22px;">
    <a href="<?= $this->e($inviteUrl) ?>" style="display:inline-block;padding:12px 22px;background-color:#F97316;color:#FFFFFF;text-decoration:none;font-weight:600;border-radius:8px;">
        <?= $this->e($this->t('mail.user-invite.definir_mon_mot_passe')) ?>
    </a>
</p>
<p style="margin:0 0 12px;color:#4B5563;font-size:13px;"><?= $this->raw($this->t('mail.user-invite.lien_invitation_heures', ['hours' => '<strong>' . $this->e((string) $ttlHours) . '</strong>'])) ?></p>
<p style="margin:0;color:#6B7280;font-size:12px;word-break:break-all;">Lien direct :<br><?= $this->e($inviteUrl) ?></p>
<?php $this->stop(); ?>
