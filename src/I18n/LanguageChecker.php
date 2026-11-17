<?php

declare(strict_types=1);

namespace Prime\I18n;

/**
 * Static analysis of the translation catalogue used by `php bin/prime lang:check`.
 *
 * Reports locale parity problems (missing/extra keys), placeholder drift between locales,
 * keys referenced by templates that do not exist, catalogue keys that are never referenced, and
 * French literals hard-coded in templates that should go through `t()`.
 */
final class LanguageChecker
{
    /**
     * Functions/methods that resolve a translation key.
     */
    private const CALL_PATTERN = '/(?<![A-Za-z0-9_$>])(?:\$this->|self::|static::|app\(\)\->get\(\s*Translator::class\s*\)->|\$translator->)?\s*(?:__|trans_choice|trans|translate|choice|t)\s*\(\s*([\'"])([a-z0-9][a-z0-9_.-]*\.[a-z0-9_.-]+)\1\s*[,)]/i';

    /**
     * Common French words used to spot untranslatable literals in templates.
     */
    private const FRENCH_WORD_PATTERN = '/\b(le|la|les|un|une|des|de|du|et|est|sont|votre|vos|notre|ajouter|enregistrer|supprimer|annuler|rechercher|tableau|contenus|reglages|utilisateur|mot de passe|obligatoire|invalide|aucun)\b/iu';

    private const ACCENT_PATTERN = '/[àâäéèêëîïôöùûüçœÀÂÄÉÈÊËÎÏÔÖÙÛÜÇŒ]/u';

    /**
     * @param list<string> $scanPaths Directories scanned for translation key usages
     */
    /**
     * @param list<string>|null $locales Restrict the analysis to these locales (null = every shipped locale)
     */
    public function __construct(
        private readonly string $langPath,
        private readonly string $reference = Translator::DEFAULT_LOCALE,
        private readonly array $scanPaths = [],
        private readonly ?array $locales = null,
    ) {
    }

    /**
     * Locales that ship at least one language file.
     *
     * @return list<string>
     */
    public function locales(): array
    {
        if ($this->locales !== null) {
            return $this->locales;
        }

        $locales = [];
        $entries = is_dir($this->langPath) ? (@scandir($this->langPath) ?: []) : [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (is_dir($this->langPath . '/' . $entry) && $this->groups($entry) !== []) {
                $locales[] = $entry;
            }
        }

        sort($locales);

        return $locales;
    }

    /**
     * Compare every locale against the reference locale.
     *
     * @param  list<string>|null $locales
     * @return array<string, array{missing: list<string>, extra: list<string>, placeholders: list<string>, empty: list<string>}>
     */
    public function compare(?array $locales = null): array
    {
        $referenceKeys = array_keys($this->flatten($this->reference));
        $targetLocales = $locales ?? $this->locales();
        $report = [];

        foreach ($targetLocales as $locale) {
            if ($locale === $this->reference) {
                continue;
            }

            $messages = $this->flatten($locale);
            $keys = array_keys($messages);

            $missing = array_values(array_diff($referenceKeys, $keys));
            $extra = array_values(array_diff($keys, $referenceKeys));
            sort($missing);
            sort($extra);

            $placeholders = [];
            $empty = [];
            foreach ($referenceKeys as $key) {
                $referenceLine = $this->raw($this->reference, $key);
                $localeLine = $this->raw($locale, $key);

                if ($localeLine === null || $referenceLine === null) {
                    continue;
                }

                if (trim($localeLine) === '') {
                    $empty[] = $key;
                    continue;
                }

                if ($this->placeholders($referenceLine) !== $this->placeholders($localeLine)
                    || $this->pluralCount($referenceLine) !== $this->pluralCount($localeLine)) {
                    $placeholders[] = $key;
                }
            }

            $report[$locale] = [
                'missing' => $missing,
                'extra' => $extra,
                'placeholders' => $placeholders,
                'empty' => $empty,
            ];
        }

        return $report;
    }

    /**
     * Cross-check catalogue keys against the keys actually used in templates and sources.
     *
     * @return array{used: int, unknown: array<string, list<string>>, unused: list<string>, hardcoded: list<array{file: string, line: int, text: string}>}
     */
    public function usage(): array
    {
        $defined = array_flip(array_keys($this->flatten($this->reference)));
        $usedKeys = [];
        $hardcoded = [];

        foreach ($this->templateFiles() as $file) {
            $contents = @file_get_contents($file);
            if (!is_string($contents)) {
                continue;
            }

            if (preg_match_all(self::CALL_PATTERN, $contents, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
                foreach ($matches as $match) {
                    $key = (string) $match[2][0];
                    $line = substr_count(substr($contents, 0, (int) $match[2][1]), "\n") + 1;
                    $usedKeys[$key][] = $this->relative($file) . ':' . $line;
                }
            }

            if (!$this->isTemplate($file)) {
                continue;
            }

            foreach ($this->hardcodedLines($contents) as $line => $text) {
                $hardcoded[] = [
                    'file' => $this->relative($file),
                    'line' => $line,
                    'text' => $text,
                ];
            }
        }

        $unknown = [];
        foreach ($usedKeys as $key => $places) {
            if (!isset($defined[$key])) {
                $unknown[$key] = $places;
            }
        }

        ksort($unknown);

        $unused = [];
        if ($usedKeys !== []) {
            foreach (array_keys($defined) as $key) {
                if (!isset($usedKeys[$key])) {
                    $unused[] = $key;
                }
            }
        }
        sort($unused);

        return [
            'used' => count($usedKeys),
            'unknown' => $unknown,
            'unused' => $unused,
            'hardcoded' => $hardcoded,
        ];
    }

    /**
     * Full report combining catalogue and usage analysis.
     *
     * @return array{
     *   reference: string,
     *   locales: list<string>,
     *   catalogue: array<string, array{missing: list<string>, extra: list<string>, placeholders: list<string>, empty: list<string>}>,
     *   used: int,
     *   unknown: array<string, list<string>>,
     *   unused: list<string>,
     *   hardcoded: list<array{file: string, line: int, text: string}>,
     *   errors: list<string>,
     *   warnings: list<string>,
     *   ok: bool
     * }
     */
    public function report(): array
    {
        $catalogue = $this->compare();
        $usage = $this->usage();

        $errors = [];
        $warnings = [];

        foreach ($catalogue as $locale => $data) {
            foreach ($data['missing'] as $key) {
                $errors[] = sprintf('[%s] clé manquante : %s', $locale, $key);
            }
            foreach ($data['empty'] as $key) {
                $errors[] = sprintf('[%s] traduction vide : %s', $locale, $key);
            }
            foreach ($data['placeholders'] as $key) {
                $warnings[] = sprintf('[%s] substituts différents de la référence : %s', $locale, $key);
            }
            foreach ($data['extra'] as $key) {
                $warnings[] = sprintf('[%s] clé absente de la référence (%s) : %s', $locale, $this->reference, $key);
            }
        }

        foreach ($usage['unknown'] as $key => $places) {
            $errors[] = sprintf('clé inconnue « %s » utilisée dans %s', $key, implode(', ', array_slice($places, 0, 3)));
        }

        foreach ($usage['unused'] as $key) {
            $warnings[] = sprintf('clé jamais utilisée : %s', $key);
        }

        foreach ($usage['hardcoded'] as $hit) {
            $warnings[] = sprintf('%s:%d — texte en dur : %s', $hit['file'], $hit['line'], $hit['text']);
        }

        return [
            'reference' => $this->reference,
            'locales' => $this->locales(),
            'catalogue' => $catalogue,
            'used' => $usage['used'],
            'unknown' => $usage['unknown'],
            'unused' => $usage['unused'],
            'hardcoded' => $usage['hardcoded'],
            'errors' => $errors,
            'warnings' => $warnings,
            'ok' => $errors === [],
        ];
    }

    /**
     * Flattened `group.key => line` catalogue of a locale.
     *
     * @return array<string, string>
     */
    public function flatten(string $locale): array
    {
        $flat = [];

        foreach ($this->groups($locale) as $group) {
            $tree = $this->load($locale, $group);
            $this->collect($tree, $group, $flat);
        }

        ksort($flat);

        return $flat;
    }

    private function raw(string $locale, string $key): ?string
    {
        $flat = $this->flatten($locale);

        return $flat[$key] ?? null;
    }

    /**
     * @param array<array-key, mixed> $tree
     * @param array<string, string>   $out
     */
    private function collect(array $tree, string $prefix, array &$out): void
    {
        foreach ($tree as $key => $value) {
            $path = $prefix . '.' . (string) $key;

            if (is_array($value)) {
                $this->collect($value, $path, $out);
                continue;
            }

            $out[$path] = is_string($value) ? $value : (string) (is_scalar($value) ? (string) $value : '');
        }
    }

    /**
     * @return list<string>
     */
    private function groups(string $locale): array
    {
        $dir = $this->langPath . '/' . $locale;
        if (!is_dir($dir)) {
            return [];
        }

        $files = @glob($dir . '/*.php') ?: [];
        $groups = [];
        foreach ($files as $file) {
            $name = basename((string) $file, '.php');
            if ($name !== '' && $name !== 'index') {
                $groups[] = $name;
            }
        }

        sort($groups);

        return array_map(static fn (int|string $g): string => (string) $g, $groups);
    }

    /**
     * @return array<string, mixed>
     */
    private function load(string $locale, string $group): array
    {
        $file = $this->langPath . '/' . $locale . '/' . $group . '.php';
        if (!is_file($file)) {
            return [];
        }

        $loaded = require $file;

        return is_array($loaded) ? $loaded : [];
    }

    /**
     * @return list<string>
     */
    private function templateFiles(): array
    {
        $files = [];

        foreach ($this->scanPaths as $path) {
            if (is_file($path)) {
                $files[] = $path;
                continue;
            }

            if (!is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $name = $file->getFilename();
                if (str_ends_with($name, '.php') && !str_contains($file->getPathname(), '/lang/')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return array_map(static fn (string $f): string => $f, $files);
    }

    private function isTemplate(string $file): bool
    {
        return str_contains($file, '/views/') || str_contains($file, '/templates/') || str_contains($file, '/partials/');
    }

    /**
     * Detect raw template text (outside `<?php … ?>`) that bypasses the translation layer.
     *
     * @return array<int, string>
     */
    private function hardcodedLines(string $contents): array
    {
        $hits = [];
        $inPhp = false;

        foreach (explode("\n", $contents) as $index => $rawLine) {
            $text = '';
            $length = strlen($rawLine);

            for ($i = 0; $i < $length; $i++) {
                if (!$inPhp && str_starts_with(substr($rawLine, $i), '<?php')) {
                    $inPhp = true;
                    $i += 4;
                    continue;
                }

                if (!$inPhp && str_starts_with(substr($rawLine, $i), '<?=')) {
                    $inPhp = true;
                    $i += 2;
                    continue;
                }

                if ($inPhp && str_starts_with(substr($rawLine, $i), '?>')) {
                    $inPhp = false;
                    $i++;
                    continue;
                }

                if (!$inPhp) {
                    $text .= $rawLine[$i];
                }
            }

            $text = trim(strip_tags($text));
            if ($text === '' || strlen($text) < 4) {
                continue;
            }

            if (preg_match('/^[{}\[\]|;]|^\s*\*|\/\//', $text) === 1) {
                continue;
            }

            if (preg_match(self::FRENCH_WORD_PATTERN, $text) !== 1 && preg_match(self::ACCENT_PATTERN, $text) !== 1) {
                continue;
            }

            $hits[$index + 1] = strlen($text) > 80 ? substr($text, 0, 77) . '…' : $text;

            if (count($hits) >= 200) {
                break;
            }
        }

        return $hits;
    }

    /**
     * @return array<int, string>
     */
    private function placeholders(string $line): array
    {
        $named = [];
        if (preg_match_all('/(?<![A-Za-z0-9\\\\]):[a-z_][a-z0-9_]*/i', $line, $matches) !== false) {
            foreach ($matches[0] as $match) {
                $named[] = (string) $match;
            }
        }

        if (preg_match_all('/%(\d+\$)?[sd-f]/', $line, $numeric) !== false) {
            foreach ($numeric[0] as $match) {
                $named[] = (string) $match;
            }
        }

        sort($named);

        return $named;
    }

    private function pluralCount(string $line): int
    {
        return max(1, count(explode('|', $line)));
    }

    private function relative(string $file): string
    {
        foreach ($this->scanPaths as $path) {
            $base = is_dir($path) ? rtrim($path, '/\\') : dirname($path);
            if (str_starts_with($file, $base . '/')) {
                return substr($file, strlen($base) + 1);
            }
        }

        return $file;
    }
}
