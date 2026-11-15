<?php

declare(strict_types=1);

namespace Prime\Support;

use Transliterator;

/**
 * Pure-PHP string utilities for slugs, plain-text extraction, word counts, and excerpts.
 */
final class Str
{
    /**
     * Fallback transliteration map when `ext-intl` is not installed.
     *
     * @var array<string, string>
     */
    private const TRANSLITERATION_MAP = [
        // French / Romance / Western European
        'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Æ' => 'AE',
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae',
        'Ç' => 'C', 'ç' => 'c', 'Ć' => 'C', 'ć' => 'c', 'Č' => 'C', 'č' => 'c',
        'Ď' => 'D', 'ď' => 'd', 'Đ' => 'D', 'đ' => 'd', 'Ð' => 'D', 'ð' => 'd',
        'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ě' => 'E', 'Ę' => 'E',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ě' => 'e', 'ę' => 'e',
        'Ğ' => 'G', 'ğ' => 'g',
        'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'İ' => 'I', 'ı' => 'i',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'Ł' => 'L', 'ł' => 'l',
        'Ñ' => 'N', 'ñ' => 'n', 'Ń' => 'N', 'ń' => 'n', 'Ň' => 'N', 'ň' => 'n',
        'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O', 'Œ' => 'OE',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'œ' => 'oe',
        'Ŕ' => 'R', 'ŕ' => 'r', 'Ř' => 'R', 'ř' => 'r',
        'Ś' => 'S', 'ś' => 's', 'Ş' => 'S', 'ş' => 's', 'Š' => 'S', 'š' => 's', 'ß' => 'ss',
        'Ť' => 'T', 'ť' => 't', 'Þ' => 'TH', 'þ' => 'th',
        'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ů' => 'U',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ů' => 'u',
        'Ý' => 'Y', 'ý' => 'y', 'Ÿ' => 'Y', 'ÿ' => 'y',
        'Ź' => 'Z', 'ź' => 'z', 'Ż' => 'Z', 'ż' => 'z', 'Ž' => 'Z', 'ž' => 'z',
        // Cyrillic common letters
        'А' => 'A', 'а' => 'a', 'Б' => 'B', 'б' => 'b', 'В' => 'V', 'в' => 'v',
        'Г' => 'G', 'г' => 'g', 'Д' => 'D', 'д' => 'd', 'Е' => 'E', 'е' => 'e',
        'Ё' => 'E', 'ё' => 'e', 'Ж' => 'Zh', 'ж' => 'zh', 'З' => 'Z', 'з' => 'z',
        'И' => 'I', 'и' => 'i', 'Й' => 'Y', 'й' => 'y', 'К' => 'K', 'к' => 'k',
        'Л' => 'L', 'л' => 'l', 'М' => 'M', 'м' => 'm', 'Н' => 'N', 'н' => 'n',
        'О' => 'O', 'о' => 'o', 'П' => 'P', 'п' => 'p', 'Р' => 'R', 'р' => 'r',
        'С' => 'S', 'с' => 's', 'Т' => 'T', 'т' => 't', 'У' => 'U', 'у' => 'u',
        'Ф' => 'F', 'ф' => 'f', 'Х' => 'Kh', 'х' => 'kh', 'Ц' => 'Ts', 'ц' => 'ts',
        'Ч' => 'Ch', 'ч' => 'ch', 'Ш' => 'Sh', 'ш' => 'sh', 'Щ' => 'Shch', 'щ' => 'shch',
        'Ы' => 'Y', 'ы' => 'y', 'Э' => 'E', 'э' => 'e', 'Ю' => 'Yu', 'ю' => 'yu',
        'Я' => 'Ya', 'я' => 'ya',
        // Greek common letters
        'Α' => 'A', 'α' => 'a', 'Β' => 'B', 'β' => 'b', 'Γ' => 'G', 'γ' => 'g',
        'Δ' => 'D', 'δ' => 'd', 'Ε' => 'E', 'ε' => 'e', 'Ζ' => 'Z', 'ζ' => 'z',
        'Η' => 'E', 'η' => 'e', 'Θ' => 'Th', 'θ' => 'th', 'Ι' => 'I', 'ι' => 'i',
        'Κ' => 'K', 'κ' => 'k', 'Λ' => 'L', 'λ' => 'l', 'Μ' => 'M', 'μ' => 'm',
        'Ν' => 'N', 'ν' => 'n', 'Ο' => 'O', 'ο' => 'o', 'Π' => 'P', 'π' => 'p',
        'Ρ' => 'R', 'ρ' => 'r', 'Σ' => 'S', 'σ' => 's', 'ς' => 's', 'Τ' => 'T', 'τ' => 't',
        'Υ' => 'Y', 'υ' => 'y', 'Φ' => 'Ph', 'φ' => 'ph', 'Χ' => 'Ch', 'χ' => 'ch',
        'Ψ' => 'Ps', 'ψ' => 'ps', 'Ω' => 'O', 'ω' => 'o',
        // Punctuation / ligatures
        '’' => '-', '\'' => '-', '‘' => '-', '`' => '-',
        '–' => '-', '—' => '-', '‑' => '-', '−' => '-',
        '«' => ' ', '»' => ' ', '“' => ' ', '”' => ' ',
        '€' => ' euro ', '£' => ' ', '$' => ' ', '&' => ' ',
    ];

    /**
     * Generate a clean ASCII URL slug from arbitrary UTF-8 text.
     *
     * Uses `ext-intl` (`Transliterator`) when available and falls back to the
     * built-in transliteration table otherwise.
     */
    public static function slug(string $value, string $separator = '-', int $maxLength = 191): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        // Handle ligatures and apostrophes consistently before intl transliteration
        $normalized = strtr($trimmed, [
            'Œ' => 'OE',
            'œ' => 'oe',
            'Æ' => 'AE',
            'æ' => 'ae',
            'ß' => 'ss',
            '’' => '-',
            '\'' => '-',
            '‘' => '-',
        ]);

        $transliterated = self::transliterate($normalized);
        $lower = strtolower($transliterated);

        $sepQuoted = preg_quote($separator, '/');
        // Replace any non-alphanumeric sequence with the separator
        $slug = (string) preg_replace('/[^a-z0-9]+/', $separator, $lower);
        $slug = (string) preg_replace('/' . $sepQuoted . '+/', $separator, $slug);
        $slug = trim($slug, $separator);

        if ($maxLength > 0 && strlen($slug) > $maxLength) {
            $slug = substr($slug, 0, $maxLength);
            $slug = rtrim($slug, $separator);
        }

        return $slug;
    }

    /**
     * Transliterate Unicode text to ASCII using `ext-intl` with a pure-PHP fallback.
     */
    public static function transliterate(string $value): string
    {
        if (class_exists(Transliterator::class)) {
            $transliterator = Transliterator::create('Any-Latin; Latin-ASCII');
            if ($transliterator !== null) {
                $result = $transliterator->transliterate($value);
                if (is_string($result)) {
                    return $result;
                }
            }
        }

        $mapped = strtr($value, self::TRANSLITERATION_MAP);

        // Strip remaining combining diacritics if any (e.g. NFD decomposed UTF-8)
        $mapped = (string) preg_replace('/\p{Mn}+/u', '', $mapped);

        return $mapped;
    }

    /**
     * Strip HTML tags, decode entities, and collapse whitespace into clean plain text.
     */
    public static function plainText(string $html): string
    {
        if ($html === '') {
            return '';
        }

        // Insert spaces around block boundaries before stripping tags
        $spaced = (string) preg_replace('/<(?:br\s*\/?|\/(?:p|div|h[1-6]|li|blockquote|tr|td|th|pre|aside|figcaption))>/i', ' ', $html);
        $stripped = strip_tags($spaced);
        $decoded = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $decoded));
    }

    /**
     * Count Unicode words in plain text.
     */
    public static function wordCount(string $text): int
    {
        $clean = trim($text);
        if ($clean === '') {
            return 0;
        }

        $matches = [];
        $count = preg_match_all('/[\p{L}\p{N}]+(?:[\'’-][\p{L}\p{N}]+)*/u', $clean, $matches);

        return is_int($count) ? $count : 0;
    }

    /**
     * Estimate reading time in minutes (minimum 1 when text is non-empty, 0 when empty).
     */
    public static function readingTimeMinutes(string $text, int $wordsPerMinute = 200): int
    {
        $words = self::wordCount($text);
        if ($words === 0) {
            return 0;
        }

        return max(1, (int) ceil($words / max(1, $wordsPerMinute)));
    }

    /**
     * Produce a clean excerpt truncated on a word boundary.
     */
    public static function excerpt(string $text, int $maxLength = 160): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($clean === '' || mb_strlen($clean) <= $maxLength) {
            return $clean;
        }

        $slice = mb_substr($clean, 0, max(1, $maxLength - 1));
        $lastSpace = mb_strrpos($slice, ' ');
        if ($lastSpace !== false && $lastSpace > (int) ($maxLength * 0.5)) {
            $slice = mb_substr($slice, 0, $lastSpace);
        }

        return rtrim($slice, " \t\n\r\0\x0B.,;:!?") . '…';
    }
}
