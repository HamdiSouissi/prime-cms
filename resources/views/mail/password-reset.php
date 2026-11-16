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
<h1 style="margin:0 0 16px;font-size:20px;font-weight:700;color:#111827;"><?= $this->e($this->t('mail.password-reset.reinitialisation_mot_passe')) ?></h1>
<p style="margin:0 0 14px;"><?= $this->e($this->t('core.mail.greeting', ['name' => $displayName])) ?></p>
<p style="margin:0 0 20px;"><?= $this->e($this->t('mail.password-reset.demande_reinitialisation_mot_passe_ete')) ?> <strong><?= $this->e($siteName) ?></strong>.</p>
<p style="margin:0 0 22px;">
    <a href="<?= $this->e($resetUrl) ?>" style="display:inline-block;padding:12px 22px;background-color:#F97316;color:#FFFFFF;text-decoration:none;font-weight:600;border-radius:8px;">
        <?= $this->e($this->t('mail.password-reset.choisir_nouveau_mot_passe')) ?>
    </a>
</p>
<p style="margin:0 0 12px;color:#4B5563;font-size:13px;"><?= $this->raw($this->t('mail.password-reset.lien_usage_unique_minutes', ['minutes' => '<strong>' . $this->e((string) $ttlMinutes) . '</strong>'])) ?></p>
<p style="margin:0;color:#6B7280;font-size:12px;word-break:break-all;"><?= $this->e($this->t('mail.password-reset.si_bouton_ne_fonctionne_pas')) ?><br><?= $this->e($resetUrl) ?></p>
<?php $this->stop(); ?>
