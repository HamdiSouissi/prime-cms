<?php

declare(strict_types=1);

namespace Prime\Editor;

use InvalidArgumentException;
use Prime\Auth\User;
use Prime\Hooks\Hooks;
use Prime\Security\HtmlSanitizer;
use Prime\Support\Str;

/**
 * Registry of all Prime Editor blocks (11 core blocks + plugin extensions via `editor.blocks`).
 */
final class BlockRegistry
{
    /**
     * @var array<string, BlockDefinition>
     */
    private array $blocks = [];

    /**
     * @var list<string>
     */
    private array $extensionScripts = [];

    private bool $filtered = false;

    public function __construct(
        private readonly ?Hooks $hooks = null,
    ) {
        $this->registerCoreBlocks();
    }

    /**
     * Register a block definition (overwriting if the same type already exists).
     */
    public function register(BlockDefinition $block): void
    {
        $this->blocks[$block->type] = $block;
        $this->filtered = false;
    }

    /**
     * Register a local ES2022 module script URL to be loaded by Prime Editor for plugin blocks.
     */
    public function enqueueScript(string $url): void
    {
        $clean = trim($url);
        if ($clean === '' || !str_starts_with($clean, '/') || str_starts_with($clean, '//') || str_contains($clean, '..')) {
            return;
        }

        if (!in_array($clean, $this->extensionScripts, true)) {
            $this->extensionScripts[] = $clean;
        }
    }

    /**
     * @return list<string>
     */
    public function extensionScripts(): array
    {
        return $this->extensionScripts;
    }

    public function has(string $type): bool
    {
        $this->resolveFilters();

        return isset($this->blocks[$type]);
    }

    public function get(string $type): ?BlockDefinition
    {
        $this->resolveFilters();

        return $this->blocks[$type] ?? null;
    }

    /**
     * Return all registered blocks keyed by type.
     *
     * @return array<string, BlockDefinition>
     */
    public function all(): array
    {
        $this->resolveFilters();

        return $this->blocks;
    }

    /**
     * Return client metadata for all blocks accessible to `$user`.
     *
     * @return list<array{
     *   type: string,
     *   title: string,
     *   description: string,
     *   icon: string,
     *   category: string,
     *   keywords: list<string>,
     *   requiresCapability: ?string,
     *   source: string,
     *   allowed: bool
     * }>
     */
    public function clientDefinitions(?User $user = null): array
    {
        $this->resolveFilters();
        $list = [];

        foreach ($this->blocks as $block) {
            $allowed = $block->requiresCapability === null
                || ($user !== null && $user->can($block->requiresCapability));

            $meta = $block->toClientMeta();
            $meta['allowed'] = $allowed;
            $list[] = $meta;
        }

        return $list;
    }

    /**
     * Parse and whitelist a YouTube or Vimeo embed URL.
     *
     * @return ?array{provider: 'youtube'|'vimeo', id: string, embedUrl: string, defaultTitle: string}
     */
    public static function parseEmbedUrl(string $rawUrl): ?array
    {
        $url = trim($rawUrl);
        if ($url === '' || strlen($url) > 2048) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https' && $scheme !== 'http') {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $query = (string) ($parts['query'] ?? '');

        // YouTube domains -> strictly youtube-nocookie.com
        if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'], true)) {
            $videoId = null;

            if ($path === '/watch' || $path === '/watch/') {
                parse_str($query, $params);
                if (isset($params['v']) && is_string($params['v'])) {
                    $videoId = $params['v'];
                }
            } elseif (preg_match('#^/(?:embed|shorts|live)/([A-Za-z0-9_-]{11})(?:/|$)#', $path, $m) === 1) {
                $videoId = $m[1];
            }

            if (is_string($videoId) && preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) === 1) {
                return [
                    'provider' => 'youtube',
                    'id' => $videoId,
                    'embedUrl' => 'https://www.youtube-nocookie.com/embed/' . $videoId,
                    'defaultTitle' => 'Vidéo YouTube',
                ];
            }

            return null;
        }

        if ($host === 'youtu.be' || $host === 'www.youtu.be') {
            if (preg_match('#^/([A-Za-z0-9_-]{11})(?:/|$)#', $path, $m) === 1) {
                return [
                    'provider' => 'youtube',
                    'id' => $m[1],
                    'embedUrl' => 'https://www.youtube-nocookie.com/embed/' . $m[1],
                    'defaultTitle' => 'Vidéo YouTube',
                ];
            }

            return null;
        }

        // Vimeo domains -> strictly player.vimeo.com
        if (in_array($host, ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], true)) {
            if (preg_match('#^/(?:video/|channels/[^/]+/|groups/[^/]+/videos/)?([0-9]{4,15})(?:/|$)#', $path, $m) === 1) {
                return [
                    'provider' => 'vimeo',
                    'id' => $m[1],
                    'embedUrl' => 'https://player.vimeo.com/video/' . $m[1],
                    'defaultTitle' => 'Vidéo Vimeo',
                ];
            }

            return null;
        }

        return null;
    }

    private function resolveFilters(): void
    {
        if ($this->filtered || $this->hooks === null) {
            return;
        }

        $this->filtered = true;
        $filtered = $this->hooks->apply('editor.blocks', $this->blocks);

        if (!is_array($filtered)) {
            return;
        }

        $validated = [];
        foreach ($filtered as $key => $block) {
            if ($block instanceof BlockDefinition) {
                $validated[$block->type] = $block;
            } elseif (is_string($key)) {
                throw new InvalidArgumentException(sprintf(
                    'Filter "editor.blocks" returned invalid definition for block [%s].',
                    $key,
                ));
            }
        }

        $this->blocks = $validated;
    }

    private function registerCoreBlocks(): void
    {
        // 1. Paragraph
        $this->register(new BlockDefinition(
            type: 'paragraph',
            title: 'Paragraphe',
            description: 'Bloc de texte enrichi standard.',
            icon: 'file-text',
            category: 'text',
            validator: static function (array $data, HtmlSanitizer $sanitizer): array {
                $rawText = $data['text'] ?? '';
                if (!is_string($rawText)) {
                    return ['data' => ['text' => ''], 'errors' => ['Le texte du paragraphe doit être une chaîne.']];
                }

                return [
                    'data' => ['text' => $sanitizer->sanitizeInline($rawText)],
                    'errors' => [],
                ];
            },
            renderer: static function (array $data, HtmlSanitizer $sanitizer, array $context): string {
                $text = $sanitizer->sanitizeInline((string) ($data['text'] ?? ''));

                return '<p class="pc-block pc-block--paragraph">' . $text . '</p>';
            },
            textExtractor: static fn (array $data): string => Str::plainText((string) ($data['text'] ?? '')),
            keywords: ['texte', 'p', 'paragraph', 'paragraphe'],
        ));

        // 2. Heading (h2 - h4 + auto anchor)
        $this->register(new BlockDefinition(
            type: 'heading',
            title: 'Titre',
            description: 'Sous-titre hiérarchique (H2, H3 ou H4) avec ancre automatique.',
            icon: 'heading',
            category: 'text',
            validator: static function (array $data, HtmlSanitizer $sanitizer): array {
                $errors = [];
                $level = is_numeric($data['level'] ?? null) ? (int) $data['level'] : 2;
                if (!in_array($level, [2, 3, 4], true)) {
                    $errors[] = 'Le niveau de titre doit être compris entre H2 et H4.';
                    $level = 2;
                }

                $rawText = $data['text'] ?? '';
                if (!is_string($rawText)) {
                    $errors[] = 'Le texte du titre doit être une chaîne.';
                    $rawText = '';
                }

                $cleanText = $sanitizer->sanitizeInline($rawText);
                $rawAnchor = isset($data['anchor']) && is_string($data['anchor']) ? trim($data['anchor']) : '';
                $anchor = $rawAnchor !== '' ? Str::slug($rawAnchor, '-', 80) : Str::slug(Str::plainText($cleanText), '-', 80);

                return [
                    'data' => [
                        'level' => $level,
                        'text' => $cleanText,
                        'anchor' => $anchor,
                    ],
                    'errors' => $errors,
                ];
            },
            renderer: static function (array $data, HtmlSanitizer $sanitizer, array $context): string {
                $level = in_array((int) ($data['level'] ?? 2), [2, 3, 4], true) ? (int) ($data['level'] ?? 2) : 2;
                $text = $sanitizer->sanitizeInline((string) ($data['text'] ?? ''));
                $anchor = isset($context['anchor']) && is_string($context['anchor'])
                    ? $context['anchor']
                    : (string) ($data['anchor'] ?? '');

                $idAttr = $anchor !== '' ? ' id="' . htmlspecialchars($anchor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' : '';

                return sprintf('<h%d%s class="pc-block pc-block--heading">%s</h%d>', $level, $idAttr, $text, $level);
            },
            textExtractor: static fn (array $data): string => Str::plainText((string) ($data['text'] ?? '')),
            keywords: ['h2', 'h3', 'h4', 'titre', 'heading', 'section'],
        ));

        // 3. List (unordered/ordered + 1 nesting level)
        $this->register(new BlockDefinition(
            type: 'list',
            title: 'Liste',
            description: 'Liste à puces ou numérotée (avec sous-éléments).',
            icon: 'list',
            category: 'text',
            validator: static function (array $data, HtmlSanitizer $sanitizer): array {
                $errors = [];
                $style = is_string($data['style'] ?? null) ? $data['style'] : 'unordered';
                if (!in_array($style, ['unordered', 'ordered'], true)) {
                    $errors[] = 'Le style de liste doit être "unordered" ou "ordered".';
                    $style = 'unordered';
                }

                $rawItems = $data['items'] ?? [];
                if (!is_array($rawItems)) {
                    $errors[] = 'Les éléments de liste doivent être un tableau.';
                    $rawItems = [];
                }

                $items = [];
                foreach (array_slice($rawItems, 0, 200) as $item) {
                    if (is_string($item)) {
                        $items[] = [
                            'text' => $sanitizer->sanitizeInline($item),
                            'children' => [],
                        ];
                    } elseif (is_array($item)) {
                        $text = is_string($item['text'] ?? null) ? $sanitizer->sanitizeInline($item['text']) : '';
                        $children = [];
                        if (isset($item['children']) && is_array($item['children'])) {
                            foreach (array_slice($item['children'], 0, 100) as $child) {
                                if (is_string($child)) {
                                    $children[] = $sanitizer->sanitizeInline($child);
                                } elseif (is_array($child) && is_string($child['text'] ?? null)) {
                                    $children[] = $sanitizer->sanitizeInline($child['text']);
                                }
                            }
                        }
                        $items[] = [
                            'text' => $text,
                            'children' => $children,
                        ];
                    }
                }

                return [
                    'data' => [
                        'style' => $style,
                        'items' => $items,
                    ],
                    'errors' => $errors,
                ];
            },
            renderer: static function (array $data, HtmlSanitizer $sanitizer, array $context): string {
                $tag = ($data['style'] ?? 'unordered') === 'ordered' ? 'ol' : 'ul';
                $items = is_array($data['items'] ?? null) ? $data['items'] : [];
                $html = '<' . $tag . ' class="pc-block pc-block--list">';

                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $text = $sanitizer->sanitizeInline((string) ($item['text'] ?? ''));
                    $children = is_array($item['children'] ?? null) ? $item['children'] : [];
                    $nested = '';

                    if ($children !== []) {
                        $nested .= '<' . $tag . '>';
                        foreach ($children as $child) {
                            if (is_string($child)) {
                                $nested .= '<li>' . $sanitizer->sanitizeInline($child) . '</li>';
                            }
                        }
                        $nested .= '</' . $tag . '>';
                    }

                    $html .= '<li>' . $text . $nested . '</li>';
                }

                return $html . '</' . $tag . '>';
            },
            textExtractor: static function (array $data): string {
                $parts = [];
                $items = is_array($data['items'] ?? null) ? $data['items'] : [];
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $text = Str::plainText((string) ($item['text'] ?? ''));
                    if ($text !== '') {
                        $parts[] = $text;
                    }
                    $children = is_array($item['children'] ?? null) ? $item['children'] : [];
                    foreach ($children as $child) {
                        if (is_string($child)) {
                            $cText = Str::plainText($child);
                            if ($cText !== '') {
                                $parts[] = $cText;
                            }
                        }
                    }
                }

                return implode("\n", $parts);
            },
            keywords: ['liste', 'puces', 'numéros', 'ul', 'ol', 'list'],
        ));

        // 4. Quote
        $this->register(new BlockDefinition(
            type: 'quote',
            title: 'Citation',
            description: 'Citation mise en valeur avec auteur ou source optionnelle.',
            icon: 'quote',
            category: 'text',
            validator: static function (array $data, HtmlSanitizer $sanitizer): array {
                $rawText = $data['text'] ?? '';
                $rawCitation = $data['citation'] ?? '';
                $errors = [];

                if (!is_string($rawText)) {
                    $errors[] = 'Le texte de la citation doit être une chaîne.';
                    $rawText = '';
                }
                if (!is_string($rawCitation)) {
                    $errors[] = 'La source de la citation doit être une chaîne.';
                    $rawCitation = '';
                }

                return [
                    'data' => [
                        'text' => $sanitizer->sanitizeInline($rawText),
                        'citation' => $sanitizer->sanitizeInline($rawCitation),
                    ],
                    'errors' => $errors,
                ];
            },
            renderer: static function (array $data, HtmlSanitizer $sanitizer, array $context): string {
                $text = $sanitizer->sanitizeInline((string) ($data['text'] ?? ''));
                $citation = trim($sanitizer->sanitizeInline((string) ($data['citation'] ?? '')));

                $html = '<blockquote class="pc-block pc-block--quote"><p>' . $text . '</p>';
                if ($citation !== '') {
                    $html .= '<cite>' . $citation . '</cite>';
                }

                return $html . '</blockquote>';
            },
            textExtractor: static function (array $data): string {
                $text = Str::plainText((string) ($data['text'] ?? ''));
                $citation = Str::plainText((string) ($data['citation'] ?? ''));

                return trim($text . ($citation !== '' ? ' — ' . $citation : ''));
            },
            keywords: ['citation', 'quote', 'blockquote'],
        ));

        // 5. Image (URL, required alt unless decorative, caption, normal/wide/full)
        $this->register(new BlockDefinition(
            type: 'image',
            title: 'Image',
            description: 'Image via URL avec texte alternatif accessible et légende.',
            icon: 'image',
            category: 'media',
            validator: static function (array $data, HtmlSanitizer $sanitizer): array {
                $errors = [];
                $rawUrl = is_string($data['url'] ?? null) ? trim($data['url']) : '';
                $safeUrl = $rawUrl !== '' ? $sanitizer->sanitizeResourceUrl($rawUrl) : null;

                if ($rawUrl === '') {
                    $errors[] = 'L\'URL de l\'image est obligatoire.';
                } elseif ($safeUrl === null) {
                    $errors[] = 'L\'URL de l\'image doit utiliser http(s) ou un chemin relatif sûr.';
                }

                $decorative = (bool) ($data['decorative'] ?? false);
                $alt = is_string($data['alt'] ?? null) ? trim($data['alt']) : '';

                if (!$decorative && $alt === '') {
                    $errors[] = 'Le texte alternatif (alt) est obligatoire sauf si l\'image est marquée comme décorative.';
                }

                $caption = is_string($data['caption'] ?? null) ? $sanitizer->sanitizeInline($data['caption']) : '';
                $alignment = is_string($data['alignment'] ?? null) ? $data['alignment'] : 'normal';
                if (!in_array($alignment, ['normal', 'wide', 'full'], true)) {
                    $errors[] = 'L\'alignement de l\'image doit être "normal", "wide" ou "full".';
                    $alignment = 'normal';
                }

                return [
                    'data' => [
                        'url' => $safeUrl ?? '',
                        'alt' => $decorative ? '' : $alt,
                        'decorative' => $decorative,
                        'caption' => $caption,
                        'alignment' => $alignment,
                    ],
                    'errors' => $errors,
                ];
            },
            renderer: static function (array $data, HtmlSanitizer $sanitizer, array $context): string {
                $url = $sanitizer->sanitizeResourceUrl((string) ($data['url'] ?? ''));
                if ($url === null || $url === '') {
                    return '';
                }

                $decorative = (bool) ($data['decorative'] ?? false);
                $alt = $decorative ? '' : (string) ($data['alt'] ?? '');
                $alignment = in_array((string) ($data['alignment'] ?? 'normal'), ['normal', 'wide', 'full'], true)
                    ? (string) ($data['alignment'] ?? 'normal')
                    : 'normal';
                $caption = trim($sanitizer->sanitizeInline((string) ($data['caption'] ?? '')));

                $imgAttrs = sprintf(
                    'src="%s" alt="%s" loading="lazy"',
                    htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                );
                if ($decorative) {
                    $imgAttrs .= ' aria-hidden="true"';
                }

                $html = sprintf(
                    '<figure class="pc-block pc-block--image pc-block--align-%s"><img %s>',
                    $alignment,
                    $imgAttrs,
                );
                if ($caption !== '') {
                    $html .= '<figcaption>' . $caption . '</figcaption>';
                }

                return $html . '</figure>';
            },
            textExtractor: static function (array $data): string {
                $parts = [];
                $alt = trim((string) ($data['alt'] ?? ''));
                $caption = Str::plainText((string) ($data['caption'] ?? ''));
                if ($alt !== '') {
                    $parts[] = $alt;
                }
                if ($caption !== '') {
                    $parts[] = $caption;
                }

                return implode(' ', $parts);
            },
            keywords: ['image', 'photo', 'visuel', 'img', 'media'],
        ));

        // 6. Code
        $this->register(new BlockDefinition(
            type: 'code',
            title: 'Code',
            description: 'Bloc de code source avec langage optionnel.',
            icon: 'code',
            category: 'formatting',
            validator: static function (array $data, HtmlSanitizer $sanitizer): array {
                $errors = [];
                $code = $data['code'] ?? '';
                if (!is_string($code)) {
                    $errors[] = 'Le contenu du bloc code doit être une chaîne.';
                    $code = '';
                }

                $lang = is_string($data['language'] ?? null) ? strtolower(trim($data['language'])) : '';
                if ($lang !== '' && preg_match('/^[a-z0-9_+#.-]{1,32}$/', $lang) !== 1) {
                    $errors[] = 'Le nom du langage de programmation est invalide.';
                    $lang = '';
                }

                return [
                    'data' => [
                        'code' => $code,
                        'language' => $lang,
                    ],
                    'errors' => $errors,
                ];
            },
            renderer: static function (array $data, HtmlSanitizer $sanitizer, array $context): string {
                $code = htmlspecialchars((string) ($data['code'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $lang = is_string($data['language'] ?? null) ? strtolower(trim($data['language'])) : '';

                if ($lang !== '' && preg_match('/^[a-z0-9_+#.-]{1,32}$/', $lang) === 1) {
                    $escapedLang = htmlspecialchars($lang, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                    return sprintf(
                        '<pre class="pc-block pc-block--code" data-language="%s"><code class="language-%s">%s</code></pre>',
                        $escapedLang,
                        $escapedLang,
                        $code,
                    );
                }

                return '<pre class="pc-block pc-block--code"><code>' . $code . '</code></pre>';
            },
            textExtractor: static fn (array $data): string => trim((string) ($data['code'] ?? '')),
            keywords: ['code', 'pre', 'snippet', 'php', 'js', 'html'],
        ));

        // 7. Separator
        $this->register(new BlockDefinition(
            type: 'separator',
            title: 'Séparateur',
            description: 'Ligne horizontale de séparation visuelle.',
            icon: 'minus',
            category: 'layout',
            validator: static fn (array $data, HtmlSanitizer $sanitizer): array => [
                'data' => [],
                'errors' => [],
            ],
            renderer: static fn (array $data, HtmlSanitizer $sanitizer, array $context): string => '<hr class="pc-block pc-block--separator">',
            textExtractor: static fn (array $data): string => '',
            keywords: ['separateur', 'ligne', 'hr', 'divider'],
        ));

        // 8. Embed (YouTube youtube-nocookie & Vimeo player.vimeo.com ONLY)
        $this->register(new BlockDefinition(
            type: 'embed',
            title: 'Vidéo (YouTube / Vimeo)',
            description: 'Intégration sécurisée d\'une vidéo YouTube ou Vimeo.',
            icon: 'video',
            category: 'media',
            validator: static function (array $data, HtmlSanitizer $sanitizer): array {
                $errors = [];
                $rawUrl = is_string($data['url'] ?? null) ? trim($data['url']) : '';
                $title = is_string($data['title'] ?? null) ? trim($data['title']) : '';

                if ($rawUrl === '') {
                    $errors[] = 'L\'URL de la vidéo YouTube ou Vimeo est obligatoire.';

                    return [
                        'data' => ['url' => '', 'title' => $title, 'provider' => '', 'embedUrl' => ''],
                        'errors' => $errors,
                    ];
                }

                $parsed = self::parseEmbedUrl($rawUrl);
                if ($parsed === null) {
                    $errors[] = 'Seules les vidéos YouTube et Vimeo sont autorisées dans le bloc Embed.';

                    return [
                        'data' => ['url' => $rawUrl, 'title' => $title, 'provider' => '', 'embedUrl' => ''],
                        'errors' => $errors,
                    ];
                }

                return [
                    'data' => [
                        'url' => $rawUrl,
                        'title' => $title !== '' ? $title : $parsed['defaultTitle'],
                        'provider' => $parsed['provider'],
                        'embedUrl' => $parsed['embedUrl'],
                    ],
                    'errors' => [],
                ];
            },
            renderer: static function (array $data, HtmlSanitizer $sanitizer, array $context): string {
                $parsed = self::parseEmbedUrl((string) ($data['url'] ?? ''));
                if ($parsed === null) {
                    return '';
                }

                $title = trim((string) ($data['title'] ?? ''));
                if ($title === '') {
                    $title = $parsed['defaultTitle'];
                }

                return sprintf(
                    '<figure class="pc-block pc-block--embed pc-embed--%s"><div class="pc-embed-ratio">'
                    . '<iframe src="%s" title="%s" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" '
                    . 'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>'
                    . '</div></figure>',
                    $parsed['provider'],
                    htmlspecialchars($parsed['embedUrl'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                );
            },
            textExtractor: static fn (array $data): string => trim((string) ($data['title'] ?? '')),
            keywords: ['video', 'youtube', 'vimeo', 'embed', 'media'],
        ));

        // 9. Table
        $this->register(new BlockDefinition(
            type: 'table',
            title: 'Tableau',
            description: 'Tableau structuré avec ligne d\'en-tête optionnelle.',
            icon: 'table',
            category: 'layout',
            validator: static function (array $data, HtmlSanitizer $sanitizer): array {
                $errors = [];
                $hasHeader = (bool) ($data['hasHeader'] ?? true);
                $rawRows = $data['rows'] ?? [];

                if (!is_array($rawRows) || $rawRows === []) {
                    $errors[] = 'Un tableau doit contenir au moins une ligne.';

                    return [
                        'data' => ['hasHeader' => $hasHeader, 'rows' => [['', '']]],
                        'errors' => $errors,
                    ];
                }

                if (count($rawRows) > 100) {
                    $errors[] = 'Un tableau ne peut pas dépasser 100 lignes.';
                }

                $rows = [];
                $maxCols = 0;
                foreach (array_slice($rawRows, 0, 100) as $row) {
                    if (!is_array($row)) {
                        $errors[] = 'Chaque ligne du tableau doit être une liste de cellules.';
                        continue;
                    }
                    if (count($row) > 20) {
                        $errors[] = 'Un tableau ne peut pas dépasser 20 colonnes.';
                    }
                    $cells = [];
                    foreach (array_slice($row, 0, 20) as $cell) {
                        $cells[] = is_string($cell) ? $sanitizer->sanitizeInline($cell) : '';
                    }
                    if ($cells !== []) {
                        $maxCols = max($maxCols, count($cells));
                        $rows[] = $cells;
                    }
                }

                if ($rows === [] || $maxCols === 0) {
                    $errors[] = 'Le tableau doit contenir au moins une cellule.';
                } else {
                    foreach ($rows as $idx => $cells) {
                        while (count($rows[$idx]) < $maxCols) {
                            $rows[$idx][] = '';
                        }
                    }
                }

                return [
                    'data' => [
                        'hasHeader' => $hasHeader,
                        'rows' => $rows,
                    ],
                    'errors' => $errors,
                ];
            },
            renderer: static function (array $data, HtmlSanitizer $sanitizer, array $context): string {
                $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
                if ($rows === []) {
                    return '';
                }

                $hasHeader = (bool) ($data['hasHeader'] ?? true);
                $html = '<div class="pc-block pc-block--table"><table>';

                $bodyRows = $rows;
                if ($hasHeader) {
                    $headerRow = array_shift($bodyRows);
                    if (is_array($headerRow)) {
                        $html .= '<thead><tr>';
                        foreach ($headerRow as $cell) {
                            $html .= '<th scope="col">' . $sanitizer->sanitizeInline((string) $cell) . '</th>';
                        }
                        $html .= '</tr></thead>';
                    }
                }

                if ($bodyRows !== []) {
                    $html .= '<tbody>';
                    foreach ($bodyRows as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $html .= '<tr>';
                        foreach ($row as $cell) {
                            $html .= '<td>' . $sanitizer->sanitizeInline((string) $cell) . '</td>';
                        }
                        $html .= '</tr>';
                    }
                    $html .= '</tbody>';
                }

                return $html . '</table></div>';
            },
            textExtractor: static function (array $data): string {
                $lines = [];
                $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $cells = [];
                    foreach ($row as $cell) {
                        $plain = Str::plainText((string) $cell);
                        if ($plain !== '') {
                            $cells[] = $plain;
                        }
                    }
                    if ($cells !== []) {
                        $lines[] = implode(' | ', $cells);
                    }
                }

                return implode("\n", $lines);
            },
            keywords: ['tableau', 'table', 'grille', 'données'],
        ));

        // 10. Callout (info, success, warning, danger)
        $this->register(new BlockDefinition(
            type: 'callout',
            title: 'Encadré',
            description: 'Bloc d\'attention (information, succès, avertissement ou alerte).',
            icon: 'info',
            category: 'layout',
            validator: static function (array $data, HtmlSanitizer $sanitizer): array {
                $errors = [];
                $variant = is_string($data['variant'] ?? null) ? $data['variant'] : 'info';
                if (!in_array($variant, ['info', 'success', 'warning', 'danger'], true)) {
                    $errors[] = 'La variante de l\'encadré doit être info, success, warning ou danger.';
                    $variant = 'info';
                }

                $title = is_string($data['title'] ?? null) ? $sanitizer->sanitizeInline($data['title']) : '';
                $text = is_string($data['text'] ?? null) ? $sanitizer->sanitizeInline($data['text']) : '';

                return [
                    'data' => [
                        'variant' => $variant,
                        'title' => $title,
                        'text' => $text,
                    ],
                    'errors' => $errors,
                ];
            },
            renderer: static function (array $data, HtmlSanitizer $sanitizer, array $context): string {
                $variant = in_array((string) ($data['variant'] ?? 'info'), ['info', 'success', 'warning', 'danger'], true)
                    ? (string) ($data['variant'] ?? 'info')
                    : 'info';
                $title = trim($sanitizer->sanitizeInline((string) ($data['title'] ?? '')));
                $text = $sanitizer->sanitizeInline((string) ($data['text'] ?? ''));

                $html = sprintf('<aside class="pc-block pc-block--callout pc-callout--%s" role="note">', $variant);
                if ($title !== '') {
                    $html .= '<p class="pc-callout__title">' . $title . '</p>';
                }
                $html .= '<div class="pc-callout__body">' . $text . '</div></aside>';

                return $html;
            },
            textExtractor: static function (array $data): string {
                $title = Str::plainText((string) ($data['title'] ?? ''));
                $text = Str::plainText((string) ($data['text'] ?? ''));

                return trim($title . ($title !== '' && $text !== '' ? ' : ' : '') . $text);
            },
            keywords: ['encadre', 'callout', 'note', 'info', 'alerte', 'warning'],
        ));

        // 11. HTML (requires capability `content.unfiltered_html`)
        $this->register(new BlockDefinition(
            type: 'html',
            title: 'HTML libre',
            description: 'Bloc HTML personnalisé réservé aux rôles autorisés.',
            icon: 'code',
            category: 'advanced',
            validator: static function (array $data, HtmlSanitizer $sanitizer): array {
                $raw = $data['html'] ?? '';
                if (!is_string($raw)) {
                    return ['data' => ['html' => ''], 'errors' => ['Le contenu du bloc HTML doit être une chaîne.']];
                }

                return [
                    'data' => ['html' => $raw],
                    'errors' => [],
                ];
            },
            renderer: static function (array $data, HtmlSanitizer $sanitizer, array $context): string {
                $raw = (string) ($data['html'] ?? '');
                $allowUnfiltered = (bool) ($context['allowUnfilteredHtml'] ?? false);
                $output = $allowUnfiltered ? $raw : $sanitizer->sanitizeBasic($raw);

                return '<div class="pc-block pc-block--html">' . $output . '</div>';
            },
            textExtractor: static fn (array $data): string => Str::plainText((string) ($data['html'] ?? '')),
            requiresCapability: 'content.unfiltered_html',
            keywords: ['html', 'brut', 'custom', 'raw'],
        ));
    }
}
