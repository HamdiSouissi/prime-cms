<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Security;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prime\Security\HtmlSanitizer;

final class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new HtmlSanitizer();
    }

    public function testInlineProfilePreservesAllowedFormattingAndSafeLinks(): void
    {
        $input = 'Texte <strong>gras</strong>, <b>b</b>, <em>italique</em>, <i>i</i>, <code>x &lt; 1</code><br>'
            . '<a href="https://primecms.org/docs?a=1&amp;b=2" target="_blank">Doc</a> '
            . '<a href="/admin/content/page">Interne</a> '
            . '<a href="#section-2">Ancre</a> '
            . '<a href="mailto:hello@example.com">Mail</a> '
            . '<a href="tel:+33102030405">Tel</a>';

        $clean = $this->sanitizer->sanitizeInline($input);

        $this->assertStringContainsString('<strong>gras</strong>', $clean);
        $this->assertStringContainsString('<b>b</b>', $clean);
        $this->assertStringContainsString('<em>italique</em>', $clean);
        $this->assertStringContainsString('<i>i</i>', $clean);
        $this->assertStringContainsString('<code>x &lt; 1</code>', $clean);
        $this->assertStringContainsString('<br>', $clean);
        $this->assertStringContainsString(
            '<a href="https://primecms.org/docs?a=1&amp;b=2" target="_blank" rel="noopener noreferrer">Doc</a>',
            $clean,
        );
        $this->assertStringContainsString('<a href="/admin/content/page">Interne</a>', $clean);
        $this->assertStringContainsString('<a href="#section-2">Ancre</a>', $clean);
        $this->assertStringContainsString('<a href="mailto:hello@example.com">Mail</a>', $clean);
        $this->assertStringContainsString('<a href="tel:+33102030405">Tel</a>', $clean);
    }

    public function testTargetBlankAlwaysForcesNoopenerNoreferrerAndStripsExtraAttrs(): void
    {
        $input = '<a href="https://example.com" target="_blank" rel="opener" class="evil" id="x" onclick="alert(1)">Lien</a>';
        $clean = $this->sanitizer->sanitizeInline($input);

        $this->assertSame('<a href="https://example.com" target="_blank" rel="noopener noreferrer">Lien</a>', $clean);
    }

    public function testBasicProfilePreservesSafeStructuralMarkupWithoutAttributes(): void
    {
        $input = '<h2 id="x" style="color:red">Titre</h2><p class="lead">Paragraphe <strong>gras</strong></p>'
            . '<ul><li onclick="x()">Item</li></ul><blockquote><p>Citation</p></blockquote><hr>';

        $clean = $this->sanitizer->sanitizeBasic($input);

        $this->assertSame(
            '<h2>Titre</h2><p>Paragraphe <strong>gras</strong></p><ul><li>Item</li></ul><blockquote><p>Citation</p></blockquote><hr>',
            $clean,
        );
    }

    public function testUnknownProfileThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->sanitizer->sanitize('<b>x</b>', 'unrestricted');
    }

    /**
     * 46 distinct XSS, DOM-clobbering, SVG/MathML, comment, and URI-obfuscation attack vectors.
     *
     * @return array<string, array{0: string}>
     */
    public static function xssVectorProvider(): array
    {
        return [
            '01 basic script tag' => ['<script>alert(1)</script>'],
            '02 uppercase mixed script tag' => ['<ScRiPt>alert(1)</ScRiPt>'],
            '03 script with src' => ['<script src="https://evil.test/xss.js"></script>'],
            '04 script with newline and attrs' => ["<script\ntype=\"text/javascript\">alert(1)</script>"],
            '05 img onerror' => ['<img src="x" onerror="alert(1)">'],
            '06 img onerror without quotes' => ['<img src=x onerror=alert(1)>'],
            '07 anchor onclick' => ['<a href="https://example.com" onclick="alert(1)">click</a>'],
            '08 bold onmouseover' => ['<strong onmouseover="alert(1)">hover</strong>'],
            '09 body onload' => ['<body onload="alert(1)">ok</body>'],
            '10 details open ontoggle' => ['<details open ontoggle="alert(1)">info</details>'],
            '11 input autofocus onfocus' => ['<input autofocus onfocus="alert(1)">'],
            '12 inline style expression' => ['<strong style="width:expression(alert(1))">x</strong>'],
            '13 inline style background url js' => ['<em style="background:url(javascript:alert(1))">x</em>'],
            '14 style tag with import' => ['<style>@import url("javascript:alert(1)");</style>'],
            '15 javascript href' => ['<a href="javascript:alert(1)">x</a>'],
            '16 mixed-case javascript href' => ['<a href="JaVaScRiPt:alert(1)">x</a>'],
            '17 leading whitespace javascript href' => ['<a href="   javascript:alert(1)">x</a>'],
            '18 tab-obfuscated javascript href' => ["<a href=\"java\tscript:alert(1)\">x</a>"],
            '19 newline-obfuscated javascript href' => ["<a href=\"java\nscript:alert(1)\">x</a>"],
            '20 cr-obfuscated javascript href' => ["<a href=\"java\rscript:alert(1)\">x</a>"],
            '21 null-byte obfuscated javascript href' => ["<a href=\"java\x00script:alert(1)\">x</a>"],
            '22 decimal entity javascript href' => ['<a href="&#106;&#97;&#118;&#97;&#115;&#99;&#114;&#105;&#112;&#116;&#58;alert(1)">x</a>'],
            '23 hex entity javascript href' => ['<a href="&#x6A;&#x61;vascript:alert(1)">x</a>'],
            '24 zero-padded entity without semicolon' => ['<a href="&#0000106avascript:alert(1)">x</a>'],
            '25 named colon entity javascript' => ['<a href="javascript&colon;alert(1)">x</a>'],
            '26 double-encoded entity javascript' => ['<a href="&amp;#x6a;avascript:alert(1)">x</a>'],
            '27 vbscript href' => ['<a href="vbscript:msgbox(1)">x</a>'],
            '28 data text/html href' => ['<a href="data:text/html,<script>alert(1)</script>">x</a>'],
            '29 data base64 href' => ['<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>'],
            '30 protocol-relative href' => ['<a href="//evil.test/phish">x</a>'],
            '31 backslash bypass href' => ['<a href="\/evil.test/phish">x</a>'],
            '32 svg onload' => ['<svg onload="alert(1)"></svg>'],
            '33 svg nested script' => ['<svg><script>alert(1)</script></svg>'],
            '34 svg animate onbegin' => ['<svg><animate onbegin="alert(1)" attributeName="x"></svg>'],
            '35 svg foreignObject' => ['<svg><foreignObject><iframe src="javascript:alert(1)"></iframe></foreignObject></svg>'],
            '36 mathml mtext mutation' => ['<math><mtext><img src=x onerror=alert(1)></mtext></math>'],
            '37 mathml annotation-xml script' => ['<math><annotation-xml encoding="text/html"><script>alert(1)</script></annotation-xml></math>'],
            '38 ie conditional comment downlevel hidden' => ['<!--[if gte IE 4]><script>alert(1)</script><![endif]-->'],
            '39 ie conditional comment downlevel revealed' => ['<![if !IE]><script>alert(1)</script><![endif]>'],
            '40 iframe srcdoc' => ['<iframe srcdoc="<script>alert(1)</script>"></iframe>'],
            '41 object data javascript' => ['<object data="javascript:alert(1)"></object>'],
            '42 embed src javascript' => ['<embed src="javascript:alert(1)">'],
            '43 meta refresh redirect' => ['<meta http-equiv="refresh" content="0;url=javascript:alert(1)">'],
            '44 form with formaction' => ['<form action="javascript:alert(1)"><button formaction="javascript:alert(1)">go</button></form>'],
            '45 template with script' => ['<template><script>alert(1)</script></template>'],
            '46 noscript img onerror' => ['<noscript><img src=x onerror=alert(1)></noscript>'],
            '47 video source onerror' => ['<video><source onerror="alert(1)"></video>'],
            '48 base tag hijacking' => ['<base href="https://evil.test/">'],
        ];
    }

    #[DataProvider('xssVectorProvider')]
    public function testNeutralizesXssVectorInBothProfiles(string $payload): void
    {
        foreach ([HtmlSanitizer::PROFILE_INLINE, HtmlSanitizer::PROFILE_BASIC] as $profile) {
            $clean = $this->sanitizer->sanitize($payload, $profile);
            $lower = strtolower($clean);

            $this->assertStringNotContainsString('<script', $lower, "Script leaked in profile {$profile}");
            $this->assertStringNotContainsString('<svg', $lower, "SVG leaked in profile {$profile}");
            $this->assertStringNotContainsString('<math', $lower, "MathML leaked in profile {$profile}");
            $this->assertStringNotContainsString('<iframe', $lower, "Iframe leaked in profile {$profile}");
            $this->assertStringNotContainsString('<object', $lower, "Object leaked in profile {$profile}");
            $this->assertStringNotContainsString('<embed', $lower, "Embed leaked in profile {$profile}");
            $this->assertStringNotContainsString('<style', $lower, "Style tag leaked in profile {$profile}");
            $this->assertStringNotContainsString('<meta', $lower, "Meta tag leaked in profile {$profile}");
            $this->assertStringNotContainsString('<base', $lower, "Base tag leaked in profile {$profile}");
            $this->assertStringNotContainsString('<form', $lower, "Form leaked in profile {$profile}");
            $this->assertStringNotContainsString('<img', $lower, "Img leaked in profile {$profile}");
            $this->assertStringNotContainsString('javascript:', $lower, "javascript: leaked in profile {$profile}");
            $this->assertStringNotContainsString('vbscript:', $lower, "vbscript: leaked in profile {$profile}");
            $this->assertStringNotContainsString('data:text/html', $lower, "data: leaked in profile {$profile}");
            $this->assertDoesNotMatchRegularExpression('/\bon[a-z]+\s*=/i', $clean, "Event handler leaked in profile {$profile}");
            $this->assertDoesNotMatchRegularExpression('/\bstyle\s*=/i', $clean, "Inline style leaked in profile {$profile}");
            $this->assertStringNotContainsString('<!--[if', $lower, "Conditional comment leaked in profile {$profile}");
            $this->assertStringNotContainsString('evil.test', $lower, "Untrusted host leaked in profile {$profile}");
        }
    }

    public function testSanitizeResourceUrlRejectsMailtoAndTel(): void
    {
        $this->assertSame('https://cdn.example.com/photo.jpg', $this->sanitizer->sanitizeResourceUrl('https://cdn.example.com/photo.jpg'));
        $this->assertSame('/uploads/photo.jpg', $this->sanitizer->sanitizeResourceUrl('/uploads/photo.jpg'));
        $this->assertNull($this->sanitizer->sanitizeResourceUrl('mailto:user@example.com'));
        $this->assertNull($this->sanitizer->sanitizeResourceUrl('tel:+33123456789'));
        $this->assertNull($this->sanitizer->sanitizeResourceUrl('javascript:alert(1)'));
        $this->assertNull($this->sanitizer->sanitizeResourceUrl('data:image/svg+xml,<svg onload=alert(1)>'));
    }
}
