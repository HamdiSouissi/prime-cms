<?php

declare(strict_types=1);

namespace PrimeStarter;

/**
 * A deliberately small Markdown converter for notes and the Markdown editor.
 *
 * Every line is escaped before any markup is added, so the output can only contain the tags
 * produced here: headings, paragraphs, lists, quotes, code, emphasis and links whose target is
 * http(s), mailto or a site-relative path. Anything else stays visible as plain text.
 */
final class Markdown
{
    public static function toHtml(string $markdown): string
    {
        $lines = preg_split('/\R/u', str_replace("\t", '    ', $markdown)) ?: [];
        $html = [];
        $paragraph = [];
        $list = null;
        $items = [];
        $inCode = false;
        $code = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*```/', $line) === 1) {
                if ($inCode) {
                    $html[] = '<pre><code>' . self::escape(implode("\n", $code)) . '</code></pre>';
                    $code = [];
                    $inCode = false;
                } else {
                    self::flushParagraph($paragraph, $html);
                    self::flushList($list, $items, $html);
                    $inCode = true;
                }
                continue;
            }
            if ($inCode) {
                $code[] = $line;
                continue;
            }

            $trimmed = trim($line);
            if ($trimmed === '') {
                self::flushParagraph($paragraph, $html);
                self::flushList($list, $items, $html);
                continue;
            }

            if (preg_match('/^(#{1,3})\s+(.+)$/u', $trimmed, $m) === 1) {
                self::flushParagraph($paragraph, $html);
                self::flushList($list, $items, $html);
                $level = strlen($m[1]) + 1;
                $html[] = sprintf('<h%d>%s</h%d>', $level, self::inline(rtrim($m[2], ' #')), $level);
                continue;
            }

            if (preg_match('/^[-*+]\s+(.+)$/u', $trimmed, $m) === 1 || preg_match('/^\d{1,3}[.)]\s+(.+)$/u', $trimmed, $m) === 1) {
                self::flushParagraph($paragraph, $html);
                $kind = preg_match('/^\d/', $trimmed) === 1 ? 'ol' : 'ul';
                if ($list !== $kind) {
                    self::flushList($list, $items, $html);
                    $list = $kind;
                }
                $items[] = $m[1];
                continue;
            }

            if (str_starts_with($trimmed, '>')) {
                self::flushParagraph($paragraph, $html);
                self::flushList($list, $items, $html);
                $html[] = '<blockquote><p>' . self::inline(ltrim(substr($trimmed, 1))) . '</p></blockquote>';
                continue;
            }

            self::flushList($list, $items, $html);
            $paragraph[] = $trimmed;
        }

        if ($inCode) {
            $html[] = '<pre><code>' . self::escape(implode("\n", $code)) . '</code></pre>';
        }
        self::flushParagraph($paragraph, $html);
        self::flushList($list, $items, $html);

        return implode("\n", $html);
    }

    /**
     * Plain text for search indexes and excerpts.
     */
    public static function toText(string $markdown): string
    {
        $text = (string) preg_replace(['/```.*?```/s', '/\[([^\]]*)\]\([^)]*\)/u', '/[#>*`_]+/u'], ['', '$1', ''], $markdown);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @param list<string> $paragraph
     * @param list<string> $html
     */
    private static function flushParagraph(array &$paragraph, array &$html): void
    {
        if ($paragraph !== []) {
            $html[] = '<p>' . implode('<br>', array_map(static fn (string $line): string => self::inline($line), $paragraph)) . '</p>';
            $paragraph = [];
        }
    }

    /**
     * @param list<string> $items
     * @param list<string> $html
     * @param-out null $list
     */
    private static function flushList(?string &$list, array &$items, array &$html): void
    {
        if ($list !== null) {
            $html[] = '<' . $list . '>' . implode('', array_map(static fn (string $item): string => '<li>' . self::inline($item) . '</li>', $items)) . '</' . $list . '>';
            $list = null;
            $items = [];
        }
    }

    private static function inline(string $text): string
    {
        $codes = [];
        $text = (string) preg_replace_callback('/`([^`]+)`/u', static function (array $m) use (&$codes): string {
            $codes[] = '<code>' . self::escape($m[1]) . '</code>';

            return "\u{1A}" . (count($codes) - 1) . "\u{1A}";
        }, $text);

        $text = self::escape($text);
        $text = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/u', static function (array $m): string {
            $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('#^(https?://|mailto:|/(?!/))#i', $url) !== 1) {
                return $m[0];
            }
            $external = preg_match('#^https?://#i', $url) === 1 ? ' rel="noopener noreferrer"' : '';

            return '<a href="' . self::escape($url) . '"' . $external . '>' . $m[1] . '</a>';
        }, $text);
        $text = (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/u', '<em>$1</em>', $text);

        return (string) preg_replace_callback("/\u{1A}(\\d+)\u{1A}/u", static fn (array $m): string => $codes[(int) $m[1]] ?? '', $text);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
