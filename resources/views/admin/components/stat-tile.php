<?php

declare(strict_types=1);

/**
 * Dashboard KPI stat tile with 12% tinted vibrant icon container and trend indicator.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $label
 * @var string $value
 * @var string $icon
 * @var string|null $accent brand|violet|teal|sky|amber|success|neutral
 * @var string|null $trend
 * @var string|null $trendDirection up|down|flat
 * @var string|null $hint
 * @var string|null $url
 */
$accent = (string) ($accent ?? 'brand');
$trendText = isset($trend) && is_string($trend) && $trend !== '' ? $trend : null;
$trendDir = (string) ($trendDirection ?? 'flat');
$hintText = isset($hint) && is_string($hint) && $hint !== '' ? $hint : null;
$targetUrl = isset($url) && is_string($url) && $url !== '' ? $url : null;
$trendIcon = $trendDir === 'up' ? 'arrow-up-right' : ($trendDir === 'down' ? 'arrow-down-right' : null);
?>
<article class="pc-stat">
    <div class="pc-stat__top">
        <div>
            <p class="pc-stat__label"><?= $this->e($label) ?></p>
            <p class="pc-stat__value"><?= $this->e($value) ?></p>
        </div>
        <span class="pc-accent-tile pc-accent-tile--<?= $this->e($accent) ?>" aria-hidden="true">
            <?= $this->raw($this->icon($icon, 22)) ?>
        </span>
    </div>
    <div class="pc-stat__footer">
        <?php if ($trendText !== null): ?>
            <span class="pc-stat__trend pc-stat__trend--<?= $this->e($trendDir) ?>">
                <?php if ($trendIcon !== null): ?>
                    <?= $this->raw($this->icon($trendIcon, 14)) ?>
                <?php endif; ?>
                <span><?= $this->e($trendText) ?></span>
            </span>
        <?php endif; ?>
        <?php if ($targetUrl !== null): ?>
            <a class="pc-stat__link" href="<?= $this->e($targetUrl) ?>"><?= $this->e($hintText ?? $this->t('admin.view_components_stat-tile.voir_tout')) ?></a>
        <?php elseif ($hintText !== null): ?>
            <span><?= $this->e($hintText) ?></span>
        <?php endif; ?>
    </div>
</article>
