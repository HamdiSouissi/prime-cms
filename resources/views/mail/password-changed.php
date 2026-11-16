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
<h1 style="margin:0 0 16px;font-size:20px;font-weight:700;color:#111827;"><?= $this->e($this->t('mail.password-changed.mot_passe_ete_modifie')) ?></h1>
<p style="margin:0 0 14px;">Bonjour <?= $this->e($displayName) ?>,</p>
<p style="margin:0 0 14px;"><?= $this->raw($this->t('mail.password-changed.mot_passe_modifie_le', ['site' => '<strong>' . $this->e($siteName) . '</strong>', 'date' => $this->e($changedAt)])) ?></p>
<p style="margin:0 0 14px;"><?= $this->e($this->t('mail.password-changed.autres_sessions_connexions_memorisees_ont')) ?></p>
<p style="margin:0;color:#B91C1C;font-size:13px;"><?= $this->raw($this->t('mail.password-changed.pas_a_l_origine')) ?></p>
<?php $this->stop(); ?>
