<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Prime\Editor\BlockDefinition;
use Prime\Editor\BlockRegistry;
use Prime\Editor\Renderer;
use Prime\Hooks\Hooks;
use Prime\Security\HtmlSanitizer;

final class BlockRegistryAndRendererTest extends TestCase
{
    public function testRegistersAllElevenCoreBlocks(): void
    {
        $registry = new BlockRegistry();
        $expected = [
            'paragraph',
            'heading',
            'list',
            'quote',
            'image',
            'code',
            'separator',
            'embed',
            'table',
            'callout',
            'html',
        ];

        $this->assertCount(11, $registry->all());
        foreach ($expected as $type) {
            $this->assertTrue($registry->has($type), "Missing core block [{$type}].");
        }
    }

    public function testValidatesAndRendersAllCoreBlocksWithSanitization(): void
    {
        $hooks = new Hooks();
        $registry = new BlockRegistry($hooks);
        $renderer = new Renderer($registry, new HtmlSanitizer(), $hooks);

        $doc = [
            'version' => 1,
            'blocks' => [
                ['id' => 'b1', 'type' => 'heading', 'data' => ['level' => 2, 'text' => 'Introduction générale']],
                ['id' => 'b2', 'type' => 'heading', 'data' => ['level' => 3, 'text' => 'Introduction générale']],
                ['id' => 'b3', 'type' => 'paragraph', 'data' => ['text' => 'Bonjour <strong>Prime</strong><script>alert(1)</script>']],
                ['id' => 'b4', 'type' => 'list', 'data' => [
                    'style' => 'ordered',
                    'items' => [
                        ['text' => 'Premier <em>point</em>', 'children' => ['Sous-point 1']],
                    ],
                ]],
                ['id' => 'b5', 'type' => 'quote', 'data' => ['text' => 'Simplicité et élégance.', 'citation' => 'Prime Team']],
                ['id' => 'b6', 'type' => 'image', 'data' => [
                    'url' => 'https://cdn.example.com/hero.webp',
                    'alt' => 'Capture du tableau de bord',
                    'caption' => 'Interface <strong>v0.6</strong>',
                    'alignment' => 'wide',
                ]],
                ['id' => 'b7', 'type' => 'code', 'data' => ['language' => 'php', 'code' => 'echo "<script>";']],
                ['id' => 'b8', 'type' => 'separator', 'data' => []],
                ['id' => 'b9', 'type' => 'embed', 'data' => ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'title' => 'Démo Prime']],
                ['id' => 'b10', 'type' => 'embed', 'data' => ['url' => 'https://vimeo.com/76979871']],
                ['id' => 'b11', 'type' => 'table', 'data' => [
                    'hasHeader' => true,
                    'rows' => [['Clé', 'Valeur'], ['Version', '<code>0.6.0</code>']],
                ]],
                ['id' => 'b12', 'type' => 'callout', 'data' => ['variant' => 'warning', 'title' => 'Attention', 'text' => 'Sauvegardez vos données.']],
                ['id' => 'b13', 'type' => 'html', 'data' => ['html' => '<div class="custom-widget">Widget</div>']],
            ],
        ];

        $result = $renderer->validateAndNormalize($doc, allowUnfilteredHtml: true);
        $this->assertTrue($result['valid'], implode('; ', $result['errors']));
        $this->assertSame([], $result['errors']);

        $html = $renderer->renderHtml($result['document'], null, allowUnfilteredHtml: true);
        $text = $renderer->extractText($result['document']);

        // Headings get unique auto-anchors
        $this->assertStringContainsString('<h2 id="introduction-generale" class="pc-block pc-block--heading">Introduction générale</h2>', $html);
        $this->assertStringContainsString('<h3 id="introduction-generale-2" class="pc-block pc-block--heading">Introduction générale</h3>', $html);

        // Paragraph sanitized
        $this->assertStringContainsString('<p class="pc-block pc-block--paragraph">Bonjour <strong>Prime</strong></p>', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);

        // Nested list
        $this->assertStringContainsString('<ol class="pc-block pc-block--list"><li>Premier <em>point</em><ol><li>Sous-point 1</li></ol></li></ol>', $html);

        // Quote
        $this->assertStringContainsString('<blockquote class="pc-block pc-block--quote"><p>Simplicité et élégance.</p><cite>Prime Team</cite></blockquote>', $html);

        // Image with alt + alignment + figcaption
        $this->assertStringContainsString('<figure class="pc-block pc-block--image pc-block--align-wide">', $html);
        $this->assertStringContainsString('alt="Capture du tableau de bord"', $html);
        $this->assertStringContainsString('<figcaption>Interface <strong>v0.6</strong></figcaption>', $html);

        // Code properly HTML-escaped
        $this->assertStringContainsString('<pre class="pc-block pc-block--code" data-language="php"><code class="language-php">echo &quot;&lt;script&gt;&quot;;</code></pre>', $html);

        // Embed strictly rewritten to youtube-nocookie & player.vimeo.com
        $this->assertStringContainsString('src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"', $html);
        $this->assertStringContainsString('title="Démo Prime"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('src="https://player.vimeo.com/video/76979871"', $html);
        $this->assertStringContainsString('title="Vidéo Vimeo"', $html);

        // Table, Callout, HTML
        $this->assertStringContainsString('<th scope="col">Clé</th>', $html);
        $this->assertStringContainsString('<aside class="pc-block pc-block--callout pc-callout--warning" role="note">', $html);
        $this->assertStringContainsString('<div class="pc-block pc-block--html"><div class="custom-widget">Widget</div></div>', $html);

        // Plain text extraction for FULLTEXT indexing
        $this->assertStringContainsString('Introduction générale', $text);
        $this->assertStringContainsString('Bonjour Prime', $text);
        $this->assertStringContainsString('Sauvegardez vos données.', $text);
    }

    public function testImageRequiresAltUnlessMarkedDecorative(): void
    {
        $renderer = new Renderer(new BlockRegistry(), new HtmlSanitizer());

        $missingAlt = $renderer->validateAndNormalize([
            'version' => 1,
            'blocks' => [
                ['id' => 'img1', 'type' => 'image', 'data' => ['url' => 'https://example.com/a.jpg', 'alt' => '']],
            ],
        ]);
        $this->assertFalse($missingAlt['valid']);
        $this->assertStringContainsString('texte alternatif', $missingAlt['errors'][0]);

        $decorative = $renderer->validateAndNormalize([
            'version' => 1,
            'blocks' => [
                ['id' => 'img2', 'type' => 'image', 'data' => ['url' => 'https://example.com/a.jpg', 'alt' => '', 'decorative' => true]],
            ],
        ]);
        $this->assertTrue($decorative['valid']);
        $html = $renderer->renderHtml($decorative['document']);
        $this->assertStringContainsString('alt=""', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    public function testEmbedRejectsNonYoutubeOrVimeoDomains(): void
    {
        $renderer = new Renderer(new BlockRegistry(), new HtmlSanitizer());

        foreach ([
            'https://evil.test/embed/123',
            'https://dailymotion.com/video/x8abc',
            'javascript:alert(1)',
            'https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ',
        ] as $url) {
            $res = $renderer->validateAndNormalize([
                'version' => 1,
                'blocks' => [
                    ['id' => 'e1', 'type' => 'embed', 'data' => ['url' => $url]],
                ],
            ]);
            $this->assertFalse($res['valid'], "Expected embed URL [{$url}] to be rejected.");
        }
    }

    public function testHtmlBlockRequiresUnfilteredCapabilityOrPreservedReadOnly(): void
    {
        $renderer = new Renderer(new BlockRegistry(), new HtmlSanitizer());

        // Creating or modifying an HTML block without permission is rejected
        $rejected = $renderer->validateAndNormalize([
            'version' => 1,
            'blocks' => [
                ['id' => 'h1', 'type' => 'html', 'data' => ['html' => '<script>alert(1)</script>']],
            ],
        ], allowUnfilteredHtml: false);
        $this->assertFalse($rejected['valid']);
        $this->assertStringContainsString('content.unfiltered_html', $rejected['errors'][0]);

        // An existing untouched HTML block is preserved when editing other blocks
        $preserved = $renderer->validateAndNormalize([
            'version' => 1,
            'blocks' => [
                ['id' => 'p1', 'type' => 'paragraph', 'data' => ['text' => 'Édité par un auteur']],
                ['id' => 'h1', 'type' => 'html', 'data' => ['html' => '<div class="banner">Admin HTML</div>']],
            ],
        ], allowUnfilteredHtml: false, preservedUnfilteredBlocks: ['h1' => '<div class="banner">Admin HTML</div>']);
        $this->assertTrue($preserved['valid']);

        // Rendering with allowUnfilteredHtml=false sanitizes any dangerous payload inside html blocks
        $safeHtml = $renderer->renderHtml([
            'version' => 1,
            'blocks' => [
                ['id' => 'h2', 'type' => 'html', 'data' => ['html' => '<p>Ok</p><script>alert(1)</script>']],
            ],
        ], allowUnfilteredHtml: false);
        $this->assertStringContainsString('<p>Ok</p>', $safeHtml);
        $this->assertStringNotContainsString('<script', $safeHtml);
    }

    public function testCustomPluginBlockRegistrationAndContentRenderFilter(): void
    {
        $hooks = new Hooks();
        $registry = new BlockRegistry($hooks);
        $hooks->filter('editor.blocks', static function (array $blocks): array {
            $blocks['demo/badge'] = new BlockDefinition(
                type: 'demo/badge',
                title: 'Badge',
                description: 'Badge personnalisé.',
                icon: 'sparkles',
                category: 'plugins',
                validator: static fn (array $data, HtmlSanitizer $s): array => [
                    'data' => ['label' => $s->sanitizeInline((string) ($data['label'] ?? ''))],
                    'errors' => [],
                ],
                renderer: static fn (array $data, HtmlSanitizer $s, array $ctx): string => '<div class="pc-block pc-block--demo-badge">' . $s->sanitizeInline((string) ($data['label'] ?? '')) . '</div>',
                textExtractor: static fn (array $data): string => strip_tags((string) ($data['label'] ?? '')),
                source: 'plugin:demo',
            );

            return $blocks;
        });

        $hooks->filter('content.render', static fn (string $html): string => $html . "\n<!-- filtered -->");

        $renderer = new Renderer($registry, new HtmlSanitizer(), $hooks);
        $res = $renderer->validateAndNormalize([
            'version' => 1,
            'blocks' => [
                ['id' => 'c1', 'type' => 'demo/badge', 'data' => ['label' => 'Nouveau !']],
            ],
        ]);

        $this->assertTrue($res['valid']);
        $html = $renderer->renderHtml($res['document']);
        $this->assertStringContainsString('<div class="pc-block pc-block--demo-badge">Nouveau !</div>', $html);
        $this->assertStringContainsString('<!-- filtered -->', $html);
    }
}
