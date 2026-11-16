<?php

declare(strict_types=1);

/**
 * Hello Prime public demonstration page (`GET /hello-prime`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $appName
 * @var string $greeting
 * @var list<array<string, mixed>> $entries
 * @var string $pluginVersion
 * @var string $cssUrl
 */
?><!DOCTYPE html>
<html lang="<?= $this->e($locale ?? 'fr') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $this->e($this->t('plugin::hello-prime.views.public.title_suffix', ['site' => $appName])) ?></title>
    <link rel="stylesheet" href="<?= $this->e($cssUrl) ?>">
</head>
<body class="hp-public">
    <main class="hp-public__shell">
        <div class="hp-public__badge"><?= $this->e($this->t('plugin::hello-prime.views.public.tagline', ['version' => $pluginVersion])) ?></div>
        <h1 class="hp-public__title"><?= $this->e($greeting) ?></h1>
        <p class="hp-public__subtitle">
            <?= $this->raw($this->t('plugin::hello-prime.views.public.page_publique_servie_directement_plugin', ['router' => 'PluginContext::router()', 'views' => 'PluginContext::views()'])) ?>
        </p>

        <?php if ($entries !== []): ?>
            <section class="hp-public__list" aria-label="<?= $this->e($this->t('plugin::hello-prime.views.public.messages_recents')) ?>">
                <h2><?= $this->e($this->t('plugin::hello-prime.views.public.messages_recents')) ?></h2>
                <ul>
                    <?php foreach ($entries as $entry): ?>
                        <li>
                            <strong><?= $this->e((string) ($entry['author'] ?? '')) ?></strong>
                            <p><?= $this->e((string) ($entry['message'] ?? '')) ?></p>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <footer class="hp-public__footer">
            <a href="/"><?= $this->e($this->t('plugin::hello-prime.views.public.retour_a_accueil')) ?></a>
            <a href="/admin/hello-prime"><?= $this->e($this->t('plugin::hello-prime.views.public.admin_link')) ?></a>
        </footer>
    </main>
</body>
</html>
