<?php

declare(strict_types=1);

/** @var Prime\Site\View\SiteView $site */
/** @var string $content */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="preload" href="/assets/fonts/inter/InterVariable.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="/assets/fonts/plus-jakarta-sans/PlusJakartaSans-Variable.woff2" as="font" type="font/woff2" crossorigin>
  <?= $site->head() ?>
</head>
<body class="<?= $site->bodyClass() ?>">
  <a class="skip-link" href="#main-content">Aller au contenu principal</a>

  <?= $site->partial('header') ?>

  <main id="main-content" class="site-main" tabindex="-1">
    <?= $content ?>
  </main>

  <?= $site->partial('footer') ?>

  <?= $site->foot() ?>
</body>
</html>
