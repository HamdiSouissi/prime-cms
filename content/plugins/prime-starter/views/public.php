<?php

declare(strict_types=1);

/**
 * Public notebook (`GET /notes`), framed by the active theme. `html` comes from
 * `PrimeStarter\Markdown`, which escapes before it adds any tag.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $title
 * @var bool $disabled
 * @var list<array{id: int, title: string, color: string, pinned: bool, updated_at: string, html: string}> $notes
 */
?>
<div class="prime-starter">
    <header class="prime-starter__header">
        <h1 class="prime-starter__title"><?= $this->e($title) ?></h1>
    </header>
    <?php if ($disabled || $notes === []): ?>
        <p class="prime-starter__empty"><?= $this->e($disabled ? $this->t('plugin::prime-starter.public.disabled') : $this->t('plugin::prime-starter.public.empty')) ?></p>
    <?php else: ?>
        <div class="prime-starter__grid">
            <?php foreach ($notes as $note): ?>
                <article id="note-<?= $this->e((string) $note['id']) ?>" class="prime-starter__note prime-starter__note--<?= $this->e($note['color']) ?>">
                    <h2 class="prime-starter__note-title"><?= $this->e($note['title']) ?></h2>
                    <div class="prime-starter__note-body"><?= $this->raw($note['html']) ?></div>
                    <footer class="prime-starter__note-meta">
                        <?php if ($note['pinned']): ?><span class="prime-starter__pin"><?= $this->e($this->t('plugin::prime-starter.fields.pinned')) ?></span><?php endif; ?>
                        <time datetime="<?= $this->e(str_replace(' ', 'T', $note['updated_at']) . 'Z') ?>"><?= $this->e($this->formatDate($note['updated_at'] . ' UTC')) ?></time>
                    </footer>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
