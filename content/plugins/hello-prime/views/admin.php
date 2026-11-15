<?php

declare(strict_types=1);

/**
 * Hello Prime admin settings & greetings page (`GET /admin/hello-prime`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $greeting
 * @var list<array<string, mixed>> $entries
 * @var string $pluginVersion
 * @var list<array{label: string, url: ?string}> $breadcrumbs
 */
$this->layout('admin::layouts/app');

$headerActions = '<a href="/hello-prime" target="_blank" rel="noopener noreferrer" class="pc-btn pc-btn--secondary">'
    . $this->icon('external-link', 16)
    . '<span>Voir /hello-prime</span></a>';
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => 'Hello Prime',
    'subtitle' => sprintf('Plugin de référence v%s — démonstration complète de l’API plugins Prime CMS.', $pluginVersion),
    'breadcrumbs' => $breadcrumbs,
    'actions' => $headerActions,
])) ?>

<div class="pc-stack pc-stack--lg hp-layout">
    <!-- Live preview banner -->
    <div class="pc-card hp-banner">
        <div class="hp-banner__badge">Hello Prime · v<?= $this->e($pluginVersion) ?></div>
        <p class="hp-banner__greeting"><?= $this->e($greeting) ?></p>
    </div>

    <div class="hp-grid">
        <!-- Settings card -->
        <section class="pc-card hp-card" aria-labelledby="hp-settings-title">
            <h2 id="hp-settings-title" class="hp-card__title">Message d’accueil</h2>
            <p class="hp-card__subtitle">Enregistré dans <code>pc_settings</code> sous la clé préfixée <code>plugin.hello-prime.greeting</code>.</p>

            <form method="post" action="/admin/hello-prime/settings" class="pc-stack pc-stack--md">
                <?= $this->raw($this->csrfField()) ?>
                <div class="pc-field">
                    <label class="pc-field__label" for="hp-greeting-input">Texte du message</label>
                    <input
                        id="hp-greeting-input"
                        type="text"
                        name="greeting"
                        value="<?= $this->e($greeting) ?>"
                        maxlength="160"
                        required
                        class="pc-input"
                    >
                    <p class="pc-field__hint">Entre 1 et 160 caractères. Affiché sur la page publique <code>/hello-prime</code>.</p>
                </div>
                <div>
                    <button type="submit" class="pc-btn pc-btn--secondary">Enregistrer le message</button>
                </div>
            </form>
        </section>

        <!-- Database form card -->
        <section class="pc-card hp-card" aria-labelledby="hp-entry-title">
            <h2 id="hp-entry-title" class="hp-card__title">Ajouter une entrée</h2>
            <p class="hp-card__subtitle">Insère une ligne dans la table dédiée <code>pc_hello_prime_greetings</code> créée par la migration du plugin.</p>

            <form method="post" action="/admin/hello-prime/greetings" class="pc-stack pc-stack--md">
                <?= $this->raw($this->csrfField()) ?>
                <div class="pc-field">
                    <label class="pc-field__label" for="hp-author-input">Auteur</label>
                    <input
                        id="hp-author-input"
                        type="text"
                        name="author"
                        maxlength="80"
                        placeholder="Votre nom"
                        class="pc-input"
                    >
                </div>
                <div class="pc-field">
                    <label class="pc-field__label" for="hp-message-input">Message</label>
                    <textarea
                        id="hp-message-input"
                        name="message"
                        rows="3"
                        maxlength="280"
                        required
                        class="pc-input"
                        placeholder="Écrivez un message de démonstration…"
                    ></textarea>
                </div>
                <div>
                    <button type="submit" class="pc-btn pc-btn--secondary">Ajouter le message</button>
                </div>
            </form>
        </section>
    </div>

    <!-- Greetings table -->
    <section class="pc-card hp-card" aria-labelledby="hp-list-title">
        <h2 id="hp-list-title" class="hp-card__title">Messages enregistrés (<?= $this->e((string) count($entries)) ?>)</h2>
        <?php if ($entries === []): ?>
            <p class="pc-text-muted">Aucun message enregistré dans <code>pc_hello_prime_greetings</code> pour le moment.</p>
        <?php else: ?>
            <div class="pc-table-wrap">
                <table class="pc-table">
                    <thead>
                        <tr>
                            <th>Auteur</th>
                            <th>Message</th>
                            <th>Date (UTC)</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $entry): ?>
                            <tr>
                                <td><strong><?= $this->e((string) ($entry['author'] ?? '')) ?></strong></td>
                                <td><?= $this->e((string) ($entry['message'] ?? '')) ?></td>
                                <td><code><?= $this->e((string) ($entry['created_at'] ?? '')) ?></code></td>
                                <td>
                                    <form method="post" action="/admin/hello-prime/greetings/<?= $this->e((string) ($entry['id'] ?? 0)) ?>/delete">
                                        <?= $this->raw($this->csrfField()) ?>
                                        <button type="submit" class="pc-btn pc-btn--ghost pc-btn--sm">Supprimer</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php $this->stop(); ?>
