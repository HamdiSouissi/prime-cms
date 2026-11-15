<?php $this->layout('layouts.base', ['title' => '419 — Session expirée · Prime CMS']); ?>

<?php $this->start('content'); ?>
  <section class="pc-card pc-error-card" aria-labelledby="error-419-title">
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
    <span class="pc-badge pc-badge--brand">Erreur 419</span>
    <h1 id="error-419-title" class="pc-error-heading">Session expirée</h1>
    <p class="pc-error-text">
      Le jeton de sécurité de ce formulaire est manquant, invalide ou a expiré.
      Revenez à la page précédente, rechargez-la puis soumettez à nouveau votre demande.
    </p>
    <a href="<?= $this->e(!empty($path) && is_string($path) ? $path : '/') ?>" class="pc-btn pc-btn--primary">Recharger la page</a>
  </section>
<?php $this->stop(); ?>
