<?php $this->layout('layouts.base', ['title' => '409 — Action impossible · Prime CMS']); ?>

<?php $this->start('content'); ?>
  <section class="pc-card pc-error-card" aria-labelledby="error-409-title">
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
    <span class="pc-badge pc-badge--brand">Erreur 409</span>
    <h1 id="error-409-title" class="pc-error-heading">Action impossible dans cet état</h1>
    <p class="pc-error-text">
      <?= $this->e(!empty($message) && is_string($message) ? $message : 'Cette opération entre en conflit avec l’état actuel de la ressource.') ?>
    </p>
    <a href="/admin" class="pc-btn pc-btn--primary">Retour au tableau de bord</a>
  </section>
<?php $this->stop(); ?>
