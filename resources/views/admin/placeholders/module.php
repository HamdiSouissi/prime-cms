<?php

declare(strict_types=1);

/**
 * Placeholder view for modules scheduled in upcoming phases (`GET /admin/{module}`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var array{
 *   title: string,
 *   subtitle: string,
 *   icon: string,
 *   accent: string,
 *   phase: string,
 *   description: string,
 *   planned: list<string>
 * } $moduleMeta
 * @var list<array{label: string, url: ?string}> $breadcrumbs
 */
$this->layout('admin::layouts/app');
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => $moduleMeta['title'],
    'subtitle' => $moduleMeta['subtitle'],
    'breadcrumbs' => $breadcrumbs,
    'actions' => $this->insert('admin::components/badge', [
        'label' => $moduleMeta['phase'],
        'variant' => $moduleMeta['accent'],
        'dot' => true,
    ]),
])) ?>

<div class="pc-stack pc-stack--lg">
    <?php ob_start(); ?>
    <?= $this->raw($this->insert('admin::components/empty-state', [
        'icon' => $moduleMeta['icon'],
        'accent' => $moduleMeta['accent'],
        'title' => 'Bientôt disponible',
        'description' => $moduleMeta['description'],
        'actionLabel' => 'Retour au tableau de bord',
        'actionUrl' => '/admin',
        'secondaryLabel' => 'Voir le Design System',
        'secondaryUrl' => '/admin/styleguide',
    ])) ?>
    <?php $emptyHtml = (string) ob_get_clean(); ?>

    <?= $this->raw($this->insert('admin::components/card', [
        'body' => $emptyHtml,
    ])) ?>

    <?php ob_start(); ?>
    <ul class="pc-stack pc-stack--sm">
        <?php foreach ($moduleMeta['planned'] as $feature): ?>
            <li class="pc-row pc-row--sm">
                <?= $this->raw($this->icon('check-circle', 16)) ?>
                <span><?= $this->e($feature) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php $plannedHtml = (string) ob_get_clean(); ?>

    <?= $this->raw($this->insert('admin::components/card', [
        'title' => 'Fonctionnalités prévues',
        'subtitle' => 'Feuille de route — ' . $moduleMeta['phase'],
        'body' => $plannedHtml,
    ])) ?>
</div>

<?php $this->stop(); ?>
