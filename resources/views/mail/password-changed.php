<?php

declare(strict_types=1);

/**
 * Password changed confirmation e-mail template.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $displayName
 * @var string $siteName
 * @var string $changedAt
 */
$this->layout('mail::layout');
?>
<?php $this->start('content'); ?>
<h1 style="margin:0 0 16px;font-size:20px;font-weight:700;color:#111827;">Votre mot de passe a été modifié</h1>
<p style="margin:0 0 14px;">Bonjour <?= $this->e($displayName) ?>,</p>
<p style="margin:0 0 14px;">Le mot de passe associé à votre compte sur <strong><?= $this->e($siteName) ?></strong> a été modifié le <?= $this->e($changedAt) ?> (UTC).</p>
<p style="margin:0 0 14px;">Toutes vos autres sessions et connexions mémorisées ont été automatiquement révoquées par mesure de sécurité.</p>
<p style="margin:0;color:#B91C1C;font-size:13px;"><strong>Vous n'êtes pas à l'origine de ce changement ?</strong> Contactez immédiatement un administrateur du site.</p>
<?php $this->stop(); ?>
