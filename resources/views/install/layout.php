<?php

declare(strict_types=1);

/**
 * Dedicated centered layout for the Prime CMS installation wizard (`install::layout`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $pageTitle
 * @var string $currentStep
 * @var int $currentStepNumber
 * @var int $totalSteps
 * @var list<array{id: string, number: int, label: string, path: string, state: string}> $steps
 */
$activeLabel = '';
foreach ($steps as $s) {
    if ($s['state'] === 'current') {
        $activeLabel = $s['label'];
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $this->e($pageTitle) ?></title>
    <link rel="icon" href="<?= $this->e(asset('brand/favicon.ico')) ?>" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $this->e(asset('brand/favicon-32.png')) ?>">
    <link rel="apple-touch-icon" href="<?= $this->e(asset('brand/apple-touch-icon.png')) ?>">
    <link rel="preload" href="<?= $this->e(asset('fonts/inter/InterVariable.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?= $this->e(asset('fonts/plus-jakarta-sans/PlusJakartaSans-Variable.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= $this->e(asset('admin/css/admin.css')) ?>">
</head>
<body class="pc-install-page">
    <a class="pc-skip-link" href="#install-content">Aller au contenu</a>

    <div class="pc-install__shell">
        <header class="pc-install__brand">
            <picture>
                <source srcset="<?= $this->e(asset('brand/logo-128.webp')) ?>" type="image/webp">
                <img
                    src="<?= $this->e(asset('brand/logo-128.png')) ?>"
                    alt="Prime CMS"
                    width="56"
                    height="56"
                    class="pc-install__logo"
                >
            </picture>
            <div>
                <p class="pc-install__brand-name">Prime CMS</p>
                <p class="pc-install__brand-sub">Assistant d'installation</p>
            </div>
        </header>

        <nav class="pc-install__stepper" aria-label="Étapes de l'installation">
            <ol class="pc-stepper">
                <?php foreach ($steps as $step): ?>
                    <li
                        class="pc-stepper__item pc-stepper__item--<?= $this->e($step['state']) ?>"
                        <?= $step['state'] === 'current' ? 'aria-current="step"' : '' ?>
                    >
                        <span class="pc-stepper__badge" aria-hidden="true">
                            <?php if ($step['state'] === 'completed'): ?>
                                <?= $this->raw($this->icon('check', 16)) ?>
                            <?php else: ?>
                                <?= $this->e((string) $step['number']) ?>
                            <?php endif; ?>
                        </span>
                        <span class="pc-stepper__label">
                            <span class="sr-only">
                                <?= $this->e(sprintf(
                                    'Étape %d (%s) : ',
                                    $step['number'],
                                    $step['state'] === 'completed' ? 'terminée' : ($step['state'] === 'current' ? 'en cours' : 'à venir'),
                                )) ?>
                            </span>
                            <?= $this->e($step['label']) ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ol>

            <div class="pc-install__step-mobile" aria-hidden="true">
                <div class="pc-install__step-mobile-top">
                    <span>Étape <?= $this->e((string) $currentStepNumber) ?> sur <?= $this->e((string) $totalSteps) ?></span>
                    <span class="pc-install__step-mobile-name"><?= $this->e($activeLabel) ?></span>
                </div>
                <div class="pc-install__step-mobile-dots">
                    <?php foreach ($steps as $step): ?>
                        <span class="pc-install__step-dot pc-install__step-dot--<?= $this->e($step['state']) ?>"></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </nav>

        <main id="install-content" class="pc-install__card" tabindex="-1">
            <?= $this->raw($this->section('content')) ?>
        </main>

        <footer class="pc-install__footer">
            Prime CMS <?= $this->e(\Prime\Kernel\Application::VERSION) ?> — Installation sécurisée locale
        </footer>
    </div>

    <script type="module" src="<?= $this->e(asset('admin/js/install.js')) ?>"></script>
</body>
</html>
