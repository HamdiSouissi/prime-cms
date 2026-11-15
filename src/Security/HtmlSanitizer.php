<?php

declare(strict_types=1);

namespace Prime\Security;

use DOMDocument;
use DOMElement;
use DOMNode;
use InvalidArgumentException;

/**
 * Custom DOMDocument-based HTML whitelist sanitizer (zero external dependencies).
 *
 * Designed specifically to sanitize rich inline text and basic HTML paste/fallback
 * output for Prime Editor while neutralizing XSS, DOM clobbering, SVG/MathML payloads,
 * IE conditional comments, event handlers, inline styles, and dangerous URI schemes.
 */
final class HtmlSanitizer
{
    public const PROFILE_INLINE = 'inline';
    public const PROFILE_BASIC = 'basic';

    /**
     * Allowed tags per sanitization profile.
     *
     * @var array<string, list<string>>
     */
    private const PROFILES = [
        self::PROFILE_INLINE => [
            'strong',
            'b',
            'em',
            'i',
            'a',
            'code',
            'br',
        ],
        self::PROFILE_BASIC => [
            'strong',
            'b',
            'em',
            'i',
            'a',
            'code',
            'br',
            'p',
            'h2',
            'h3',
            'h4',
            'ul',
            'ol',
            'li',
            'blockquote',
            'pre',
            'hr',
            'table',
            'thead',
            'tbody',
            'tr',
            'th',
            'td',
        ],
    ];

    /**
     * Void HTML elements that take no closing tag or children.
     *
     * @var list<string>
     */
    private const VOID_TAGS = ['br', 'hr'];

    /**
     * Dangerous container elements whose entire subtree must be dropped (never unwrapped).
     *
     * @var list<string>
     */
    private const DROP_SUBTREE_TAGS = [
        'script',
        'style',
        'svg',
        'math',
        'iframe',
        'frame',
        'frameset',
        'object',
        'embed',
        'applet',
        'form',
        'input',
        'button',
        'select',
        'option',
        'optgroup',
        'textarea',
        'noscript',
        'template',
        'title',
        'head',
        'meta',
        'link',
        'base',
        'audio',
        'video',
        'source',
        'track',
        'canvas',
        'map',
        'area',
        'xml',
        'xmp',
        'plaintext',
        'marquee',
        'bgsound',
        'portal',
        'dialog',
        'annotation-xml',
        'foreignobject',
        'desc',
        'animate',
        'set',
    ];

    /**
     * Allowed URI schemes for `<a href="...">` and standalone URL validation.
     *
     * @var list<string>
     */
    private const ALLOWED_LINK_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * Sanitize an HTML fragment against the chosen strict whitelist profile.
     */
    public function sanitize(string $html, string $profile = self::PROFILE_INLINE): string
    {
        if (!isset(self::PROFILES[$profile])) {
            throw new InvalidArgumentException(sprintf('Unknown HtmlSanitizer profile [%s].', $profile));
        }

        if ($html === '') {
            return '';
        }

        // 1. Strip NUL bytes and unsafe ASCII control characters (keep \t, \n, \r).
        $clean = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $html);

        // 2. Strip HTML comments, IE conditional comments, and XML processing instructions
        //    before DOM parsing so nested/downlevel-revealed comments cannot slip through.
        $clean = (string) preg_replace('/<!--.*?-->/s', '', $clean);
        $clean = (string) preg_replace('/<!\[(?:endif|if\b[^\]]*)\]>/i', '', $clean);
        $clean = (string) preg_replace('/<\?.*?\?>/s', '', $clean);

        if (trim($clean) === '') {
            return '';
        }

        $allowedTags = array_fill_keys(self::PROFILES[$profile], true);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previousErrors = libxml_use_internal_errors(true);

        try {
            $wrapped = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div id="__prime_root__">'
                . $clean
                . '</div></body></html>';

            $dom->loadHTML($wrapped, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        $root = $dom->getElementById('__prime_root__');
        if (!$root instanceof DOMElement) {
            return '';
        }

        return $this->serializeChildren($root, $allowedTags);
    }

    /**
     * Shortcut for the strict inline profile (`strong, b, em, i, a, code, br`).
     */
    public function sanitizeInline(string $html): string
    {
        return $this->sanitize($html, self::PROFILE_INLINE);
    }

    /**
     * Shortcut for the basic block/inline HTML profile.
     */
    public function sanitizeBasic(string $html): string
    {
        return $this->sanitize($html, self::PROFILE_BASIC);
    }

    /**
     * Validate and normalize an `<a href="...">` URL.
     *
     * Allows relative URLs (`/path`, `#anchor`, `?query`) and explicit `http`, `https`,
     * `mailto`, and `tel` URLs. Rejects `javascript:`, `vbscript:`, `data:`, protocol-relative
     * `//`, encoded/obfuscated schemes, and control characters.
     */
    public function sanitizeHref(string $rawHref): ?string
    {
        $decoded = $this->normalizeUrlForInspection($rawHref);
        if ($decoded === '') {
            return null;
        }

        // Reject protocol-relative URLs (//evil.test) and backslash bypasses (\/evil.test, \\evil.test)
        if (
            str_starts_with($decoded, '//')
            || str_starts_with($decoded, '\\')
            || str_starts_with($decoded, '/\\')
        ) {
            return null;
        }

        // Relative URL starting with /, #, or ?
        if (str_starts_with($decoded, '/') || str_starts_with($decoded, '#') || str_starts_with($decoded, '?')) {
            return $this->stripControlChars(trim($rawHref));
        }

        // Inspect scheme with all whitespace/control characters removed to defeat `java\tscript:`
        $compact = (string) preg_replace('/[\s\x00-\x1F\x7F]+/', '', $decoded);

        // If a colon appears before the first '/', '?', or '#', treat prefix as a scheme
        $colonPos = strpos($compact, ':');
        if ($colonPos !== false) {
            $firstDelim = strlen($compact);
            foreach (['/', '?', '#'] as $delim) {
                $pos = strpos($compact, $delim);
                if ($pos !== false && $pos < $firstDelim) {
                    $firstDelim = $pos;
                }
            }

            if ($colonPos < $firstDelim) {
                $scheme = strtolower(substr($compact, 0, $colonPos));
                if (!in_array($scheme, self::ALLOWED_LINK_SCHEMES, true)) {
                    return null;
                }

                // For http(s), require `scheme://host` with non-empty host
                if ($scheme === 'http' || $scheme === 'https') {
                    $rest = substr($compact, $colonPos + 1);
                    if (!str_starts_with($rest, '//') || strlen($rest) <= 2) {
                        return null;
                    }
                }

                // For mailto/tel, ensure no nested javascript: or whitespace injection
                if ($scheme === 'mailto' || $scheme === 'tel') {
                    $rest = substr($compact, $colonPos + 1);
                    if ($rest === '' || preg_match('/[\r\n]/', $rawHref) === 1) {
                        return null;
                    }
                }

                return $this->stripControlChars(trim($rawHref));
            }
        }

        // Plain relative path (e.g. `about`, `docs/guide.html`), reject if it contains suspicious scheme-like patterns
        if (preg_match('/^(?:javascript|vbscript|data|file|blob)\b/i', $compact) === 1) {
            return null;
        }

        return $this->stripControlChars(trim($rawHref));
    }

    /**
     * Validate an external HTTP/HTTPS or relative image/resource URL (no mailto/tel).
     */
    public function sanitizeResourceUrl(string $rawUrl): ?string
    {
        $href = $this->sanitizeHref($rawUrl);
        if ($href === null) {
            return null;
        }

        $lower = strtolower(ltrim($href));
        if (str_starts_with($lower, 'mailto:') || str_starts_with($lower, 'tel:')) {
            return null;
        }

        return $href;
    }

    /**
     * @param array<string, true> $allowedTags
     */
    private function serializeChildren(DOMNode $parent, array $allowedTags): string
    {
        $output = '';

        foreach ($parent->childNodes as $child) {
            $output .= $this->serializeNode($child, $allowedTags);
        }

        return $output;
    }

    /**
     * @param array<string, true> $allowedTags
     */
    private function serializeNode(DOMNode $node, array $allowedTags): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return $this->escapeText((string) $node->nodeValue);
        }

        if ($node->nodeType !== XML_ELEMENT_NODE || !$node instanceof DOMElement) {
            // Drop comments, processing instructions, CDATA, entity refs, etc.
            return '';
        }

        $tag = strtolower($node->tagName);

        // Completely drop dangerous elements and their entire subtrees.
        if (in_array($tag, self::DROP_SUBTREE_TAGS, true)) {
            return '';
        }

        // Non-whitelisted benign tags: strip the tag wrapper, keep safe sanitized inner text/children.
        if (!isset($allowedTags[$tag])) {
            return $this->serializeChildren($node, $allowedTags);
        }

        if (in_array($tag, self::VOID_TAGS, true)) {
            return '<' . $tag . '>';
        }

        $inner = $this->serializeChildren($node, $allowedTags);

        if ($tag === 'a') {
            $rawHref = $node->hasAttribute('href') ? $node->getAttribute('href') : '';
            $safeHref = $this->sanitizeHref($rawHref);

            // If href is missing or unsafe, unwrap the anchor and retain only its safe inner content.
            if ($safeHref === null) {
                return $inner;
            }

            $attrs = sprintf(' href="%s"', $this->escapeAttr($safeHref));
            $target = strtolower(trim($node->getAttribute('target')));
            if ($target === '_blank') {
                $attrs .= ' target="_blank" rel="noopener noreferrer"';
            }

            return '<a' . $attrs . '>' . $inner . '</a>';
        }

        return '<' . $tag . '>' . $inner . '</' . $tag . '>';
    }

    private function normalizeUrlForInspection(string $raw): string
    {
        $value = $this->stripControlChars($raw);

        // Decode HTML entities up to 3 times to catch double/triple-encoded schemes like `&#x6a;avascript:`
        for ($i = 0; $i < 3; $i++) {
            $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Also handle numeric HTML entities without trailing semicolon (`&#106avascript:`)
            $decoded = (string) preg_replace_callback(
                '/&#(?:x([0-9a-fA-F]{1,6})|([0-9]{1,7}));?/',
                static function (array $m): string {
                    $code = $m[1] !== '' ? (int) hexdec($m[1]) : (int) $m[2];
                    if ($code <= 0 || $code > 0x10FFFF) {
                        return '';
                    }

                    return mb_chr($code, 'UTF-8');
                },
                $decoded,
            );

            if ($decoded === $value) {
                break;
            }
            $value = $decoded;
        }

        return trim($this->stripControlChars($value));
    }

    private function stripControlChars(string $value): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]+/', '', $value);
    }

    private function escapeText(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
