<?php

declare(strict_types=1);

/**
 * « Mise à jour de sécurité » — sent to administrators when an update fixes a security issue.
 *
 * Usage: `$mailer->sendTemplate('security-update', $email, t('core.mail.security_update.subject', ['site' => …]),
 *   ['displayName' => …, 'version' => '0.9.1', 'summary' => '…', 'actionUrl' => '…/admin/tools/system'])`.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $displayName
 * @var string $version
 * @var string $summary
 * @var string $actionUrl
 * @var string $site_name
 */
$this->layout('mail::layout');
?>
<?php $this->start('content'); ?>
<h1 style="margin:0 0 16px;font-size:20px;font-weight:700;color:#1A1D29;"><?= $this->e($this->t('core.mail.security_update.title', ['version' => $version])) ?></h1>
<p style="margin:0 0 14px;"><?= $this->e($this->t('core.mail.greeting', ['name' => $displayName])) ?></p>
<p style="margin:0 0 14px;"><?= $this->e($this->t('core.mail.security_update.body', ['site' => $site_name, 'version' => $version])) ?></p>
<?php if (($summary ?? '') !== ''): ?>
    <p style="margin:0 0 20px;padding:12px 14px;background-color:#FFF4F1;border-left:3px solid #C9441F;border-radius:6px;"><?= $this->e($summary) ?></p>
<?php endif; ?>
<p style="margin:0 0 22px;">
    <a href="<?= $this->e($actionUrl) ?>" style="display:inline-block;padding:12px 22px;background-color:#C9441F;color:#FFFFFF;text-decoration:none;font-weight:600;border-radius:8px;">
        <?= $this->e($this->t('core.mail.security_update.action')) ?>
    </a>
</p>
<p style="margin:0;color:#6B7085;font-size:12px;"><?= $this->e($this->t('core.mail.security_update.footer')) ?></p>
<?php $this->stop(); ?>
