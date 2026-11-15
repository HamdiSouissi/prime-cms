<?php $this->layout('layouts.base', ['title' => '405 — Méthode non autorisée · Prime CMS']); ?>

<?php $this->start('content'); ?>
  <section class="pc-card pc-error-card" aria-labelledby="error-405-title">
    <img
      src="<?= $this->e(asset('brand/logo.svg')) ?>"
      alt="Prime CMS"
      width="56"
      height="56"
      class="pc-logo"
    >
    <span class="pc-badge pc-badge--brand">Erreur 405</span>
    <h1 id="error-405-title" class="pc-error-heading">Méthode non autorisée</h1>
    <p class="pc-error-text">
      La méthode HTTP utilisée n'est pas acceptée pour cette adresse.
      <?php if (!empty($allowedMethods)): ?>
        <br>Méthodes autorisées : <code class="pc-mono"><?= $this->e(implode(', ', $allowedMethods)) ?></code>
      <?php endif; ?>
    </p>
    <a href="/" class="pc-btn pc-btn--primary">Retourner à l'accueil</a>
  </section>
<?php $this->stop(); ?>
