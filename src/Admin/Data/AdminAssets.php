<?php

declare(strict_types=1);

namespace Prime\Admin\Data;

/**
 * Immutable normalized value object for admin shell stylesheets and scripts (`admin.assets`).
 */
final readonly class AdminAssets
{
    /**
     * @param list<string> $css
     * @param list<string> $js
     */
    public function __construct(
        public array $css,
        public array $js,
    ) {
    }

    /**
     * Validate and normalize an `admin.assets` filter payload, discarding invalid entries
     * and reporting every rejected item via `$onInvalid`.
     *
     * @param ?callable(string): void $onInvalid
     */
    public static function fromArray(mixed $raw, self $fallback, ?callable $onInvalid = null): self
    {
        if ($raw instanceof self) {
            return $raw;
        }

        if (!is_array($raw)) {
            if ($onInvalid !== null) {
                $onInvalid('La valeur retournée pour admin.assets doit être un tableau associatif {css, js}.');
            }

            return $fallback;
        }

        $css = self::normalizeBucket($raw, 'css', $fallback->css, $onInvalid);
        $js = self::normalizeBucket($raw, 'js', $fallback->js, $onInvalid);

        return new self($css, $js);
    }

    /**
     * @return array{css: list<string>, js: list<string>}
     */
    public function toArray(): array
    {
        return [
            'css' => $this->css,
            'js' => $this->js,
        ];
    }

    /**
     * @param array<mixed, mixed> $raw
     * @param list<string> $fallback
     * @param ?callable(string): void $onInvalid
     * @return list<string>
     */
    private static function normalizeBucket(array $raw, string $key, array $fallback, ?callable $onInvalid): array
    {
        if (!array_key_exists($key, $raw) || !is_array($raw[$key])) {
            if ($onInvalid !== null) {
                $onInvalid(sprintf('La clé "%s" de admin.assets est absente ou n\'est pas un tableau.', $key));
            }

            return $fallback;
        }

        $normalized = [];
        foreach ($raw[$key] as $index => $entry) {
            if (!is_string($entry) || !FilterValueHelper::isSafeUrl($entry)) {
                if ($onInvalid !== null) {
                    $onInvalid(sprintf('Entrée invalide ignorée dans admin.assets[%s][%s].', $key, (string) $index));
                }
                continue;
            }
            $normalized[] = trim($entry);
        }

        return $normalized;
    }
}
