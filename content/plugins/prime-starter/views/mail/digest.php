<?php

declare(strict_types=1);

/**
 * Weekly digest (`prime-starter::mail/digest`), sent by the `prime-starter.digest` job.
 *
 * @var \Prime\View\TemplateContext $this
 * @var list<array{title: string, url: string}> $notes
 * @var int $days
 * @var string $adminUrl
 * @var string $site_name
 */
$this->layout('mail::layout');
?>
<?php $this->start('content'); ?>
<h1 style="margin:0 0 16px;font-size:20px;font-weight:700;color:#1A1D29;"><?= $this->e($this->t('plugin::prime-starter.mail.title')) ?></h1>
<p style="margin:0 0 14px;"><?= $this->e($this->choice('plugin::prime-starter.mail.intro', count($notes), ['count' => count($notes), 'days' => $days, 'site' => $site_name])) ?></p>
<ul style="margin:0 0 20px;padding-left:20px;">
    <?php foreach ($notes as $note): ?>
        <li style="margin:0 0 6px;"><a href="<?= $this->e($note['url']) ?>" style="color:#C9441F;"><?= $this->e($note['title']) ?></a></li>
    <?php endforeach; ?>
</ul>
<p style="margin:0;">
    <a href="<?= $this->e($adminUrl) ?>" style="display:inline-block;padding:12px 22px;background-color:#C9441F;color:#FFFFFF;text-decoration:none;font-weight:600;border-radius:8px;"><?= $this->e($this->t('plugin::prime-starter.mail.action')) ?></a>
</p>
<?php $this->stop(); ?>
