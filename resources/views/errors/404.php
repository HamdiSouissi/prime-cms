<?php $this->layout('layouts.base', ['title' => '404 — Page introuvable · Prime CMS']); ?>

<?php $this->start('content'); ?>
  <section class="pc-card pc-error-card" aria-labelledby="error-404-title">
    <img
      src="<?= $this->e(asset('brand/logo.svg')) ?>"
      alt="Prime CMS"
      width="56"
      height="56"
      class="pc-logo"
    >
    <span class="pc-badge pc-badge--brand">Erreur 404</span>
    <h1 id="error-404-title" class="pc-error-heading">Page introuvable</h1>
    <p class="pc-error-text">
      La ressource demandée n'existe pas ou a été déplacée.
      <?php if (!empty($path)): ?>
        <br><code class="pc-mono"><?= $this->e($path) ?></code>
      <?php endif; ?>
    </p>
    <a href="/" class="pc-btn pc-btn--primary">Retourner à l'accueil</a>
  </section>
<?php $this->stop(); ?>
