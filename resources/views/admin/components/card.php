<?php

declare(strict_types=1);

/**
 * Surface card component.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string|null $title
 * @var string|null $subtitle
 * @var string|null $headerAction Trusted HTML slot
 * @var string $body Trusted HTML body
 * @var string|null $footer Trusted HTML footer
 * @var bool|null $flush
 * @var string|null $id
 * @var string|null $class
 */
$cardTitle = isset($title) && is_string($title) && $title !== '' ? $title : null;
$cardSubtitle = isset($subtitle) && is_string($subtitle) && $subtitle !== '' ? $subtitle : null;
$cardHeaderAction = isset($headerAction) && is_string($headerAction) && $headerAction !== '' ? $headerAction : null;
$cardFooter = isset($footer) && is_string($footer) && $footer !== '' ? $footer : null;
$isFlush = (bool) ($flush ?? false);
$cardId = isset($id) && is_string($id) && $id !== '' ? $id : null;
$cardClass = trim('pc-card ' . (string) ($class ?? ''));
?>
<section class="<?= $this->e($cardClass) ?>"<?= $cardId !== null ? ' id="' . $this->e($cardId) . '"' : '' ?>>
    <?php if ($cardTitle !== null || $cardHeaderAction !== null): ?>
        <header class="pc-card__header">
            <div>
                <?php if ($cardTitle !== null): ?>
                    <h2 class="pc-card__title"><?= $this->e($cardTitle) ?></h2>
                <?php endif; ?>
                <?php if ($cardSubtitle !== null): ?>
                    <p class="pc-card__subtitle"><?= $this->e($cardSubtitle) ?></p>
                <?php endif; ?>
            </div>
            <?php if ($cardHeaderAction !== null): ?>
                <div><?= $this->raw($cardHeaderAction) ?></div>
            <?php endif; ?>
        </header>
    <?php endif; ?>

    <div class="pc-card__body<?= $isFlush ? ' pc-card__body--flush' : '' ?>">
        <?= $this->raw((string) $body) ?>
    </div>

    <?php if ($cardFooter !== null): ?>
        <footer class="pc-card__footer">
            <?= $this->raw($cardFooter) ?>
        </footer>
    <?php endif; ?>
</section>
