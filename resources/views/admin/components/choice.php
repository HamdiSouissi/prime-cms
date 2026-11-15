<?php

declare(strict_types=1);

/**
 * Checkbox, radio, or toggle switch component.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $id
 * @var string $name
 * @var string $label
 * @var string|null $type checkbox|radio|switch
 * @var string|null $description
 * @var string|null $value
 * @var bool|null $checked
 * @var bool|null $disabled
 */
$choiceType = (string) ($type ?? 'checkbox');
$choiceValue = (string) ($value ?? '1');
$isChecked = (bool) ($checked ?? false);
$isDisabled = (bool) ($disabled ?? false);
$descText = isset($description) && is_string($description) && $description !== '' ? $description : null;
?>
<?php if ($choiceType === 'switch'): ?>
<label class="pc-switch" for="<?= $this->e($id) ?>">
    <input
        type="checkbox"
        role="switch"
        id="<?= $this->e($id) ?>"
        name="<?= $this->e($name) ?>"
        value="<?= $this->e($choiceValue) ?>"
        class="pc-switch__input"
        <?= $isChecked ? 'checked' : '' ?>
        <?= $isDisabled ? 'disabled' : '' ?>
    >
    <span class="pc-choice__body">
        <span class="pc-choice__title"><?= $this->e($label) ?></span>
        <?php if ($descText !== null): ?>
            <span class="pc-choice__desc"><?= $this->e($descText) ?></span>
        <?php endif; ?>
    </span>
</label>
<?php else: ?>
<label class="pc-choice" for="<?= $this->e($id) ?>">
    <input
        type="<?= $this->e($choiceType === 'radio' ? 'radio' : 'checkbox') ?>"
        id="<?= $this->e($id) ?>"
        name="<?= $this->e($name) ?>"
        value="<?= $this->e($choiceValue) ?>"
        <?= $isChecked ? 'checked' : '' ?>
        <?= $isDisabled ? 'disabled' : '' ?>
    >
    <span class="pc-choice__body">
        <span class="pc-choice__title"><?= $this->e($label) ?></span>
        <?php if ($descText !== null): ?>
            <span class="pc-choice__desc"><?= $this->e($descText) ?></span>
        <?php endif; ?>
    </span>
</label>
<?php endif; ?>
