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
    . '<span>' . $this->e($this->t('plugin::hello-prime.ui.view_public')) . '</span></a>';
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => 'Hello Prime',
    'subtitle' => $this->t('plugin::hello-prime.ui.subtitle', ['version' => $pluginVersion]),
    'breadcrumbs' => $breadcrumbs,
    'actions' => $headerActions,
])) ?>

<div class="pc-stack pc-stack--lg hp-layout">
    <!-- Live preview banner -->
    <div class="pc-card hp-banner">
        <div class="hp-banner__badge"><?= $this->e($this->t('plugin::hello-prime.views.admin.version', ['version' => $pluginVersion])) ?></div>
        <p class="hp-banner__greeting"><?= $this->e($greeting) ?></p>
    </div>

    <div class="hp-grid">
        <!-- Settings card -->
        <section class="pc-card hp-card" aria-labelledby="hp-settings-title">
            <h2 id="hp-settings-title" class="hp-card__title"><?= $this->e($this->t('plugin::hello-prime.views.admin.greeting_label')) ?></h2>
            <p class="hp-card__subtitle"><?= $this->raw($this->t('plugin::hello-prime.views.admin.enregistre_cle_prefixee', ['key' => 'plugin.hello-prime.greeting'])) ?></p>

            <p class="pc-text-sm"><?= $this->e($this->t('plugin::hello-prime.settings.moved')) ?></p>
            <p class="pc-text-sm pc-text-strong">« <?= $this->e($greeting) ?> »</p>
            <div>
                <a class="pc-btn pc-btn--secondary" href="/admin/settings/general#settings-section-hello-prime">
                    <?= $this->e($this->t('plugin::hello-prime.settings.open')) ?>
                </a>
            </div>
        </section>

        <!-- Database form card -->
        <section class="pc-card hp-card" aria-labelledby="hp-entry-title">
            <h2 id="hp-entry-title" class="hp-card__title"><?= $this->e($this->t('plugin::hello-prime.views.admin.ajouter_entree')) ?></h2>
            <p class="hp-card__subtitle"><?= $this->raw($this->t('plugin::hello-prime.views.admin.insere_ligne_table_dediee', ['table' => 'pc_hello_prime_greetings'])) ?></p>

            <form method="post" action="/admin/hello-prime/greetings" class="pc-stack pc-stack--md">
                <?= $this->raw($this->csrfField()) ?>
                <div class="pc-field">
                    <label class="pc-field__label" for="hp-author-input"><?= $this->e($this->t('core.fields.author')) ?></label>
                    <input
                        id="hp-author-input"
                        type="text"
                        name="author"
                        maxlength="80"
                        placeholder="<?= $this->e($this->t('plugin::hello-prime.views.admin.nom')) ?>"
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
                        placeholder="<?= $this->e($this->t('plugin::hello-prime.views.admin.ecrivez_message_demonstration')) ?>"
                    ></textarea>
                </div>
                <div>
                    <button type="submit" class="pc-btn pc-btn--secondary"><?= $this->e($this->t('plugin::hello-prime.views.admin.ajouter_message')) ?></button>
                </div>
            </form>
        </section>
    </div>

    <!-- Greetings table -->
    <section class="pc-card hp-card" aria-labelledby="hp-list-title">
        <h2 id="hp-list-title" class="hp-card__title"><?= $this->e($this->choice('plugin::hello-prime.views.admin.messages_enregistres', count($entries))) ?></h2>
        <?php if ($entries === []): ?>
            <p class="pc-text-muted"><?= $this->raw($this->t('plugin::hello-prime.views.admin.aucun_message_enregistre', ['table' => 'pc_hello_prime_greetings'])) ?></p>
        <?php else: ?>
            <div class="pc-table-wrap">
                <table class="pc-table">
                    <thead>
                        <tr>
                            <th><?= $this->e($this->t('plugin::hello-prime.views.admin.author')) ?></th>
                            <th><?= $this->e($this->t('plugin::hello-prime.views.admin.message')) ?></th>
                            <th><?= $this->e($this->t('plugin::hello-prime.views.admin.date_utc')) ?></th>
                            <th><?= $this->e($this->t('plugin::hello-prime.views.admin.action')) ?></th>
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
                                        <button type="submit" class="pc-btn pc-btn--ghost pc-btn--sm"><?= $this->e($this->t('core.actions.delete')) ?></button>
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
