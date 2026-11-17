<?php

declare(strict_types=1);

/**
 * « Nouveau contenu à relire » — sent to publishers when a contributor submits content.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $displayName
 * @var string $entryTitle
 * @var string $authorName
 * @var string $editUrl
 * @var string $site_name
 */
$this->layout('mail::layout');
?>
<?php $this->start('content'); ?>
<h1 style="margin:0 0 16px;font-size:20px;font-weight:700;color:#1A1D29;"><?= $this->e($this->t('core.mail.review.title')) ?></h1>
<p style="margin:0 0 14px;"><?= $this->e($this->t('core.mail.greeting', ['name' => $displayName])) ?></p>
<p style="margin:0 0 20px;">
    <?= $this->e($authorName !== ''
        ? $this->t('core.mail.review.body_author', ['author' => $authorName, 'title' => $entryTitle, 'site' => $site_name])
        : $this->t('core.mail.review.body', ['title' => $entryTitle, 'site' => $site_name])) ?>
</p>
<p style="margin:0 0 22px;">
    <a href="<?= $this->e($editUrl) ?>" style="display:inline-block;padding:12px 22px;background-color:#C9441F;color:#FFFFFF;text-decoration:none;font-weight:600;border-radius:8px;">
        <?= $this->e($this->t('core.mail.review.action')) ?>
    </a>
</p>
<p style="margin:0;color:#6B7085;font-size:12px;word-break:break-all;"><?= $this->e($editUrl) ?></p>
<?php $this->stop(); ?>
