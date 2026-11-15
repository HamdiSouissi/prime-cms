<?php $this->layout('layouts.base', ['title' => '403 — Accès refusé · Prime CMS']); ?>

<?php $this->start('content'); ?>
  <section class="pc-card pc-error-card" aria-labelledby="error-403-title">
    <picture>
      <source srcset="<?= $this->e(asset('brand/logo-128.webp')) ?>" type="image/webp">
      <img
        src="<?= $this->e(asset('brand/logo-128.png')) ?>"
        alt="Prime CMS"
        width="56"
        height="56"
        class="pc-logo"
      >
    </picture>
    <span class="pc-badge pc-badge--brand">Erreur 403</span>
    <h1 id="error-403-title" class="pc-error-heading">Accès refusé</h1>
    <p class="pc-error-text">
      <?= $this->e(!empty($message) && is_string($message) ? $message : 'Vous ne disposez pas des permissions nécessaires pour accéder à cette page.') ?>
    </p>
    <a href="/admin" class="pc-btn pc-btn--primary">Retour au tableau de bord</a>
  </section>
<?php $this->stop(); ?>
