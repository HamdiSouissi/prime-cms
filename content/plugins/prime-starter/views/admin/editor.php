<?php

declare(strict_types=1);

/**
 * Markdown editor swapped in place of Prime Editor (`content.editor` → `prime-starter-markdown`).
 * The core only reads `content_json`: the script keeps it in step with the textarea.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $markdown
 * @var string $contentJson
 */
?>
<div class="ps-md-editor" data-prime-starter-markdown>
    <label class="pc-field__label" for="ps-md-input"><?= $this->e($this->t('plugin::prime-starter.editor.label')) ?></label>
    <textarea id="ps-md-input" class="pc-textarea ps-md-editor__input" rows="18" spellcheck="true"
              aria-describedby="ps-md-help" data-prime-starter-markdown-input><?= $this->e($markdown) ?></textarea>
    <p id="ps-md-help" class="pc-field__help"><?= $this->e($this->t('plugin::prime-starter.editor.help')) ?></p>
    <textarea name="content_json" data-prime-custom-editor-input hidden><?= $this->e($contentJson) ?></textarea>
</div>
