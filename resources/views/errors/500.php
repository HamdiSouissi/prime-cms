<?php $this->layout('layouts.base', ['title' => '500 — Erreur serveur · Prime CMS']); ?>

<?php $this->start('content'); ?>
  <section class="pc-card pc-error-card" aria-labelledby="error-500-title">
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
    <span class="pc-badge pc-badge--danger">Erreur <?= $this->e($status ?? 500) ?></span>
    <h1 id="error-500-title" class="pc-error-heading">Une erreur est survenue</h1>
    <p class="pc-error-text">
      Le serveur n'a pas pu traiter votre demande pour le moment. L'incident a été journalisé.
    </p>
    <a href="/" class="pc-btn pc-btn--primary">Retourner à l'accueil</a>
  </section>
<?php $this->stop(); ?>
