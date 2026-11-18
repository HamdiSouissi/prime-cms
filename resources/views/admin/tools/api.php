<?php

declare(strict_types=1);

/**
 * « Outils › API ».
 *
 * @var \Prime\View\TemplateContext $this
 * @var bool $enabled
 * @var bool $publicRead
 * @var int $limitAuthenticated
 * @var int $limitAnonymous
 * @var list<string> $origins
 * @var int $routeCount
 * @var list<array<string, mixed>> $activeTokens
 * @var list<array{label: string, url: ?string}> $breadcrumbs
 */
$this->layout('admin::layouts/app');
$yes = $this->t('admin.api_tools.yes');
$no = $this->t('admin.api_tools.no');
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => $this->t('admin.tools.title'),
    'subtitle' => $this->t('admin.tools.subtitle'),
    'breadcrumbs' => $breadcrumbs,
])) ?>

<?= $this->raw($this->insert('admin::tools/_nav', ['activeTab' => 'api', 'failedCount' => 0])) ?>

<div class="pc-tools-stack">
    <section class="pc-card" aria-labelledby="api-state">
        <header class="pc-card__header">
            <div>
                <h2 class="pc-card__title" id="api-state"><?= $this->e($this->t('admin.api_tools.state_title')) ?></h2>
                <p class="pc-card__subtitle"><?= $this->e($this->t('admin.api_tools.state_help')) ?></p>
            </div>
            <?= $this->raw($this->insert('admin::components/badge', [
                'label' => $enabled ? $this->t('admin.api_tools.enabled') : $this->t('admin.api_tools.disabled'),
                'variant' => $enabled ? 'success' : 'neutral',
            ])) ?>
        </header>
        <div class="pc-card__body">
            <dl class="pc-kv-list">
                <div class="pc-kv-row"><dt><?= $this->e($this->t('admin.api_tools.base_url')) ?></dt><dd><code class="pc-mono"><?= $this->e(url('/api/v1')) ?></code></dd></div>
                <div class="pc-kv-row"><dt><?= $this->e($this->t('admin.api_tools.public_read')) ?></dt><dd><?= $this->e($publicRead ? $yes : $no) ?></dd></div>
                <div class="pc-kv-row"><dt><?= $this->e($this->t('admin.api_tools.limits')) ?></dt><dd><?= $this->e($this->t('admin.api_tools.limits_value', ['auth' => (string) $limitAuthenticated, 'anon' => (string) $limitAnonymous])) ?></dd></div>
                <div class="pc-kv-row"><dt><?= $this->e($this->t('admin.api_tools.origins')) ?></dt><dd><?= $this->e($origins === [] ? $this->t('admin.api_tools.origins_none') : implode(', ', $origins)) ?></dd></div>
                <div class="pc-kv-row"><dt><?= $this->e($this->t('admin.api_tools.routes')) ?></dt><dd><?= $this->e((string) $routeCount) ?></dd></div>
            </dl>
        </div>
        <footer class="pc-card__footer">
            <div class="pc-row pc-row--sm">
                <a class="pc-btn pc-btn--secondary pc-btn--sm" href="/admin/settings/security#api"><?= $this->raw($this->icon('settings', 16)) ?><span><?= $this->e($this->t('admin.api_tools.settings_link')) ?></span></a>
                <a class="pc-btn pc-btn--ghost pc-btn--sm" href="/api/v1/openapi.json" target="_blank" rel="noopener"><?= $this->raw($this->icon('code', 16)) ?><span><?= $this->e($this->t('admin.api_tools.openapi_link')) ?></span></a>
                <a class="pc-btn pc-btn--ghost pc-btn--sm" href="https://github.com/HamdiSouissi/prime-cms/blob/main/docs/api.md" target="_blank" rel="noopener"><?= $this->raw($this->icon('file-text', 16)) ?><span><?= $this->e($this->t('admin.api_tools.docs_link')) ?></span></a>
            </div>
        </footer>
    </section>

    <section class="pc-card" aria-labelledby="api-tokens">
        <header class="pc-card__header">
            <div>
                <h2 class="pc-card__title" id="api-tokens"><?= $this->e($this->t('admin.api_tools.tokens_title')) ?></h2>
                <p class="pc-card__subtitle"><?= $this->e($this->t('admin.api_tools.tokens_help')) ?></p>
            </div>
        </header>
        <?php if ($activeTokens === []): ?>
            <div class="pc-card__body">
                <p class="pc-text-muted"><?= $this->e($this->t('admin.api_tools.tokens_empty')) ?></p>
            </div>
        <?php else: ?>
            <div class="pc-card__body pc-card__body--flush">
                <?= $this->raw($this->insert('admin::components/api-tokens-table', [
                    'tokens' => $activeTokens,
                    'revokeAction' => '/admin/tools/api/tokens/{id}/revoke',
                    'showOwner' => true,
                ])) ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php $this->stop(); ?>
