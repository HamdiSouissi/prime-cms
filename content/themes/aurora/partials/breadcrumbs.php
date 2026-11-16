<?php

declare(strict_types=1);

/** @var Prime\Site\View\SiteView $site */
$crumbs = $site->breadcrumbs();
if (count($crumbs) <= 1) {
    return;
}
?>
<nav class="aurora-breadcrumbs" aria-label="Fil d'Ariane">
  <ol>
    <?php foreach ($crumbs as $i => $crumb): ?>
      <?php $isLast = $i === count($crumbs) - 1; ?>
      <li>
        <?php if ($isLast): ?>
          <span aria-current="page"><?= $crumb['label'] ?></span>
        <?php else: ?>
          <a href="<?= $crumb['url'] ?>"><?= $crumb['label'] ?></a>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>
</nav>
