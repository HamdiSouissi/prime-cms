<?php

declare(strict_types=1);

/**
 * Dedicated split-screen authentication layout (`auth::layout`).
 *
 * Desktop (>= 1024px): dark `--ink-900` brand panel on the left, clean `--surface` card on `--canvas` on the right.
 * Mobile / tablet (< 1024px): centered `--surface` card on `--canvas`.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $pageTitle
 * @var string|null $siteName
 */
$title = (string) ($pageTitle ?? 'Connexion');
$brandName = (string) ($siteName ?? 'Prime CMS');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="prime-icon-sprite" content="<?= $this->e(asset('admin/icons/sprite.svg')) ?>">
    <title><?= $this->e($title) ?> · <?= $this->e($brandName) ?></title>
    <link rel="icon" href="<?= $this->e(asset('brand/favicon.ico')) ?>" sizes="32x32">
    <link rel="icon" type="image/png" href="<?= $this->e(asset('brand/favicon-32.png')) ?>" sizes="32x32">
    <link rel="apple-touch-icon" href="<?= $this->e(asset('brand/apple-touch-icon.png')) ?>">
    <link rel="preload" href="<?= $this->e(asset('fonts/inter/InterVariable.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= $this->e(asset('admin/css/admin.css')) ?>">
</head>
<body class="pc-auth-page">
    <aside class="pc-auth-brand" aria-label="<?= t('admin.attr.presentation_de_prime_cms') ?>">
        <div class="pc-auth-brand__top">
            <img
                src="<?= $this->e(asset('brand/logo-128.png')) ?>"
                alt=""
                width="36"
                height="36"
                class="pc-auth-brand__logo"
            >
            <span class="pc-auth-brand__name"><?= $this->e($brandName) ?></span>
        </div>

        <div class="pc-auth-brand__body">
            <h1 class="pc-auth-brand__headline"><?= $this->e($this->t('admin.auth.layout.espace_administration_securise')) ?></h1>
            <p class="pc-auth-brand__lead">
                <?= $this->e($this->t('admin.auth.layout.connectez_gerer_contenus_equipes_reglages')) ?>
            </p>
            <ul class="pc-auth-brand__pillars">
                <li class="pc-auth-brand__pillar">
                    <span class="pc-auth-brand__pillar-dot" aria-hidden="true"></span>
                    <span><?= $this->e($this->t('admin.auth.layout.sessions_durcies_protection_csrf_limitation')) ?></span>
                </li>
                <li class="pc-auth-brand__pillar">
                    <span class="pc-auth-brand__pillar-dot" aria-hidden="true"></span>
                    <span><?= $this->e($this->t('admin.auth.layout.authentification_a_deux_facteurs_totp')) ?></span>
                </li>
                <li class="pc-auth-brand__pillar">
                    <span class="pc-auth-brand__pillar-dot" aria-hidden="true"></span>
                    <span><?= $this->e($this->t('admin.auth.layout.controle_acces_granulaire_roles_permissions')) ?></span>
                </li>
            </ul>
        </div>

        <div class="pc-auth-brand__footer">
            <span>Prime CMS</span>
            <span><?= $this->e($this->t('admin.auth.layout.noyau_php_natif_securite_defaut')) ?></span>
        </div>
    </aside>

    <main class="pc-auth-main" id="main-content">
        <div class="pc-auth-mobile-brand">
            <img
                src="<?= $this->e(asset('brand/logo-128.png')) ?>"
                alt=""
                width="32"
                height="32"
                class="pc-auth-brand__logo"
            >
            <span class="pc-auth-mobile-brand__name"><?= $this->e($brandName) ?></span>
        </div>

        <div class="pc-auth-card">
            <?= $this->raw($this->section('content')) ?>
        </div>
    </main>

    <script type="module" src="<?= $this->e(asset('admin/js/auth.js')) ?>"></script>
</body>
</html>
