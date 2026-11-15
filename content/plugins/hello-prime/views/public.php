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
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hello Prime — <?= $this->e($appName) ?></title>
    <link rel="stylesheet" href="<?= $this->e($cssUrl) ?>">
</head>
<body class="hp-public">
    <main class="hp-public__shell">
        <div class="hp-public__badge">Plugin Prime CMS · Hello Prime v<?= $this->e($pluginVersion) ?></div>
        <h1 class="hp-public__title"><?= $this->e($greeting) ?></h1>
        <p class="hp-public__subtitle">
            Cette page publique est servie directement par le plugin de référence <strong>hello-prime</strong> via <code>PluginContext::router()</code> et <code>PluginContext::views()</code>.
        </p>

        <?php if ($entries !== []): ?>
            <section class="hp-public__list" aria-label="Messages">
                <h2>Messages récents</h2>
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
            <a href="/">← Retour à l’accueil</a>
            <a href="/admin/hello-prime">Administration Hello Prime →</a>
        </footer>
    </main>
</body>
</html>
