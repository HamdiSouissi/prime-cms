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
    /**
     * Admin JavaScript translates through `tr('key', 'fallback')` and `Prime.t('key')`.
     */
    private const JS_CALL_PATTERN = '/(?<![A-Za-z0-9_$.])(?:tr|Prime\.t|Prime\.tr)\s*\(\s*(?:[^()?]*\?\s*)?([\'"\`])(admin\.js\.[a-z0-9_.:\-]+)\1/';

    /**
     * The second branch of a ternary key (`tr($ok ? 'a.x' : 'a.y', …)`).
     */
    private const JS_CALL_PATTERN_B = '/(?<![A-Za-z0-9_$.])(?:tr|Prime\.t|Prime\.tr)\s*\([^()]*\?[^:]*:\s*([\'"\`])(admin\.js\.[a-z0-9_.:\-]+)\1/';

    /**
     * Dynamic lookups: `t('core.date.formats.' . $preset)` or `'validation.' . $rule`.
     */
    private const DYNAMIC_PREFIX_PATTERN = '/([\'"])((?:[a-z0-9][a-z0-9_-]*::)?[a-z0-9][a-z0-9_.:-]*\.)\1\s*\.\s*[\$\'"]/i';

    private const CALL_PATTERN = '/(?<![A-Za-z0-9_])(?:\$[A-Za-z_][A-Za-z0-9_]*(?:->|::)|self::|static::|app\(\)->get\(\s*Translator::class\s*\)->)?\s*(?:__|trans_choice|trans|translate|choice|t)\s*\(\s*([\'"])((?:[a-z0-9][a-z0-9_-]*::)?[a-z0-9][a-z0-9_.:-]*\.[a-z0-9_.:-]+)\1\s*[,)]/i';

    /**
     * Common French words used to spot untranslatable literals in templates.
     */
    private const FRENCH_WORD_PATTERN = '/\b(le|la|les|un|une|des|de|du|et|est|sont|votre|vos|notre|ajouter|enregistrer|supprimer|annuler|rechercher|tableau|contenus|reglages|utilisateur|mot de passe|obligatoire|invalide|aucun)\b/iu';

    private const ACCENT_PATTERN = '/[àâäéèêëîïôöùûüçœÀÂÄÉÈÊËÎÏÔÖÙÛÜÇŒ]/u';

    /**
     * HTML attributes whose literal value is user-visible copy.
     */
    private const UI_ATTRIBUTES = ['placeholder', 'title', 'aria-label', 'alt'];

    /**
     * Literal text nodes that are legitimate and must not be reported.
     *
     * Brand names, unit labels and typographic symbols only: anything with real prose belongs
     * in the catalogue.
     */
    private const ALLOWED_LITERALS = [
        'Prime CMS',
        'CSS',
        'HTML',
        'JSON',
        'PHP',
        'SQL',
        'ZIP',
    ];

    /**
     * Files excluded from the hard-coded copy scan (suffixes relative to the scanned root).
     */
    private const HARDCODED_EXCEPTIONS = [
        // Component documentation, written in French on purpose.
        'admin/styleguide/index.php',
        'admin/styleguide.php',
        // Machine-readable payloads and technical views.
        'errors/debug.php',
        'mail/layout.php',
        // Third-party / generated bundles.
        'assets/admin/js/i18n.js',
    ];

    /**
     * @param list<string>            $scanPaths      Directories scanned for translation key usages
     * @param list<string>|null       $locales        Restrict the analysis to these locales
     * @param list<string>            $usageOnlyPaths Paths whose key references count as usages only
     * @param list<string>            $unusedBaseline Keys exempted from the unused report
     */
    public function __construct(
        private readonly string $langPath,
        private readonly string $reference = Translator::DEFAULT_LOCALE,
        private readonly array $scanPaths = [],
        private readonly ?array $locales = null,
        private readonly array $usageOnlyPaths = [],
        private readonly array $unusedBaseline = [],
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

                // A key missing from the reference catalogue is reported by the missing-key pass.
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
        $dynamicPrefixes = [];
        $hardcoded = [];

        foreach ($this->templateFiles() as $file) {
            $contents = @file_get_contents($file);
            if (!is_string($contents)) {
                continue;
            }

            // `i18n.js` documents the helper API with example keys in its docblock: those are
            // documentation, not usages. Every other file is scanned, including the ones
            // excepted from the hard-coded-copy check.
            if (preg_match_all(self::DYNAMIC_PREFIX_PATTERN, $contents, $dynamic) > 0) {
                foreach ($dynamic[2] as $prefix) {
                    $dynamicPrefixes[$prefix] = true;
                }
            }

            $patterns = str_ends_with($file, '/js/i18n.js') ? [] : [self::CALL_PATTERN];
            if ($this->isJavaScript($file)) {
                $patterns[] = self::JS_CALL_PATTERN;
                $patterns[] = self::JS_CALL_PATTERN_B;
            }

            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
                    foreach ($matches as $match) {
                        $key = (string) $match[2][0];
                        $line = substr_count(substr($contents, 0, (int) $match[2][1]), "\n") + 1;
                        $usedKeys[$key][] = $this->relative($file) . ':' . $line;
                    }
                }
            }

            if (!$this->isUiSource($file)) {
                continue;
            }

            $hits = $this->isJavaScript($file)
                ? $this->hardcodedJavaScript($contents)
                : $this->hardcodedLines($contents);

            foreach ($hits as $line => $text) {
                $hardcoded[] = [
                    'file' => $this->relative($file),
                    'line' => $line,
                    'text' => $text,
                ];
            }
        }

        // Keys resolved through a variable prefix cannot be attributed individually, so every
        // catalogue entry under such a prefix counts as used.
        if ($dynamicPrefixes !== []) {
            foreach (array_keys($defined) as $definedKey) {
                foreach (array_keys($dynamicPrefixes) as $prefix) {
                    if (str_starts_with($definedKey, $prefix)) {
                        $usedKeys[$definedKey][] = 'dynamic:' . $prefix;
                        break;
                    }
                }
            }
        }

        foreach ($this->usageOnlyFiles() as $file) {
            $contents = @file_get_contents($file);
            if (!is_string($contents)) {
                continue;
            }

            if (preg_match_all(self::CALL_PATTERN, $contents, $matches, PREG_SET_ORDER) > 0) {
                foreach ($matches as $match) {
                    $usedKeys[(string) $match[2]][0] ??= 'test';
                }
            }
        }

        $unknown = [];
        foreach ($usedKeys as $key => $places) {
            // A reference coming only from the suite is a fixture, not a missing catalogue entry.
            if ($places === ['test']) {
                continue;
            }
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

        // An unused key is dead weight but the interface still works; copy left hard-coded in a
        // template or in the admin bundle is untranslatable by definition, so it is an error.
        $baseline = array_flip($this->unusedBaseline());

        foreach ($usage['unused'] as $key) {
            if (isset($baseline[$key])) {
                continue;
            }
            $warnings[] = sprintf('clé jamais utilisée : %s', $key);
        }

        foreach ($usage['hardcoded'] as $hit) {
            $errors[] = sprintf('%s:%d — texte en dur : %s', $hit['file'], $hit['line'], $hit['text']);
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

        // Plugins and themes ship their own `lang/{locale}/messages.php`, addressed as
        // `plugin::slug.key` / `theme::slug.key` by the Translator.
        foreach ($this->namespaceCatalogues($locale) as $prefix => $tree) {
            $this->collect($tree, $prefix, $flat);
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
     * Catalogues contributed by installed plugins and themes.
     *
     * @return array<string, array<string, mixed>> `plugin::slug` / `theme::slug` => messages tree
     */
    private function namespaceCatalogues(string $locale): array
    {
        $catalogues = [];

        foreach (['plugins' => 'plugin', 'themes' => 'theme'] as $kind => $namespace) {
            foreach ($this->scanPaths as $path) {
                $base = is_dir($path) ? rtrim($path, '/\\') : dirname($path);
                if (basename($base) !== $kind) {
                    continue;
                }

                foreach (@glob($base . '/*/lang/' . $locale . '/messages.php') ?: [] as $file) {
                    $slug = basename(dirname((string) $file, 3));
                    $loaded = require $file;
                    if ($slug !== '' && is_array($loaded)) {
                        /** @var array<string, mixed> $loaded */
                        $catalogues[$namespace . '::' . $slug] = $loaded;
                    }
                }
            }
        }

        ksort($catalogues);

        return $catalogues;
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
                if (str_contains($file->getPathname(), '/lang/')) {
                    continue;
                }

                // Admin JavaScript carries its copy through `tr('key', 'fallback')`, so it has to
                // be scanned for usages and for hard-coded text like any template.
                if (str_ends_with($name, '.php') || str_ends_with($name, '.js')) {
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
     * Files whose user-visible copy must come from the catalogue.
     *
     * `resources/views`, `content/themes/<theme>/templates`, `content/themes/<theme>/partials`
     * and the admin JavaScript bundle. The design-system styleguide is deliberately excluded:
     * it documents every component in French on purpose.
     */
    private function isUiSource(string $file): bool
    {
        if ($this->isExcepted($file)) {
            return false;
        }

        $normalized = str_replace('\\', '/', $file);

        if (str_contains($normalized, '/resources/views/')) {
            return true;
        }

        if (preg_match('#/content/themes/[^/]+/(?:templates|partials)/#', $normalized) === 1) {
            return true;
        }

        if (str_contains($normalized, '/public/assets/admin/js/') && !str_contains($normalized, '/js/vendor/')) {
            return true;
        }

        // Any scanned `views/`, `templates/` or `partials/` tree (the unit fixtures, themes and
        // plugins all follow the same layout).
        return $this->isTemplate($normalized);
    }

    /**
     * Explicit, reviewable exceptions: files that legitimately contain literal copy.
     *
     * Matched as suffixes of the path relative to the scanned root.
     */
    private function isExcepted(string $file): bool
    {
        $normalized = str_replace('\\', '/', $file);
        $relative = ltrim($this->relative($file), '/');

        foreach (self::HARDCODED_EXCEPTIONS as $exception) {
            if ($relative === $exception || str_ends_with($normalized, '/' . $exception)) {
                return true;
            }
        }

        return false;
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
        $inStyle = 0;

        foreach (explode("\n", $contents) as $index => $rawLine) {
            $text = '';
            $length = strlen($rawLine);

            // Stylesheets and inline scripts carry no translatable copy.
            if (preg_match('/<style\b/i', $rawLine) === 1) {
                $inStyle++;
            }
            if (preg_match('#</style>#i', $rawLine) === 1) {
                $inStyle = max(0, $inStyle - 1);
                continue;
            }
            if ($inStyle > 0) {
                continue;
            }

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

            // Tags are removed and attribute values blanked out: a hard-coded `class="…"` or
            // `aria-expanded="false"` is markup, only the visible text node is copy.
            $text = trim(strip_tags($text));
            $text = trim((string) preg_replace('/\b[\w:-]+\s*=\s*(?:"[^"]*"|\'[^\']*\')/', '', $text));
            // HTML entities carry no translatable prose (`&mdash;`, `&rarr;`).
            $text = trim((string) preg_replace('/&[\w#]+;/', '', $text));
            $text = trim((string) preg_replace('/\s+/u', ' ', $text));
            if ($text === '' || strlen($text) < 4) {
                continue;
            }

            if (preg_match('/^[{}\[\]|;]|^\s*\*|\/\//', $text) === 1) {
                continue;
            }

            // Any literal prose is untranslatable, whatever its language: two consecutive
            // letters that are not part of a PHP/echo expression is enough to report it.
            // Only prose counts: a bare lowercase token is an attribute or a data-* hook, and a
            // CSS declaration or an HTML entity is markup.
            if (preg_match('/[A-ZÀ-Ý]/u', $text) !== 1 && preg_match(self::ACCENT_PATTERN, $text) !== 1) {
                continue;
            }
            if (preg_match('/^&[\w#]+;$/', $text) === 1) {
                continue;
            }
            if (preg_match('/^[\w-]+\s*:\s*[^\s].*;$/u', $text) === 1) {
                continue;
            }
            if (preg_match('/^[\w-]+$/u', $text) === 1) {
                continue;
            }

            if (in_array($text, self::ALLOWED_LITERALS, true)) {
                continue;
            }

            $hits[$index + 1] = strlen($text) > 80 ? substr($text, 0, 77) . '…' : $text;

            if (count($hits) >= 200) {
                break;
            }
        }

        // Literal copy in user-visible attributes (`placeholder`, `title`, `aria-label`, `alt`)
        // is as untranslatable as a text node, so it is reported the same way.
        foreach (explode("\n", $contents) as $index => $rawLine) {
            if (isset($hits[$index + 1])) {
                continue;
            }

            foreach (self::UI_ATTRIBUTES as $attribute) {
                $pattern = '/\b' . preg_quote($attribute, '/') . '\s*=\s*"([^"<>{}]*)"|\b'
                    . preg_quote($attribute, '/') . "\s*=\s*'([^'<>{}]*)'/u";
                if (preg_match_all($pattern, $rawLine, $found, PREG_SET_ORDER) === 0) {
                    continue;
                }

                foreach ($found as $match) {
                    $double = trim((string) ($match[1] ?? ''));
                    $single = trim((string) ($match[2] ?? ''));
                    $value = $double !== '' ? $double : $single;
                    if ($value === '' || preg_match('/[A-Za-zÀ-ÿ]/u', $value) !== 1) {
                        continue;
                    }
                    if (preg_match(self::FRENCH_WORD_PATTERN, $value) !== 1
                        && preg_match(self::ACCENT_PATTERN, $value) !== 1) {
                        continue;
                    }

                    $hits[$index + 1] = $attribute . '="' . (strlen($value) > 70 ? substr($value, 0, 67) . '…' : $value) . '"';
                    break 2;
                }
            }
        }

        ksort($hits);

        return $hits;
    }

    /**
     * Catalogue keys exempted from the unused report.
     *
     * @return list<string>
     */
    public function unusedBaseline(): array
    {
        $baseline = $this->unusedBaseline;

        if ($this->langPath !== '' && is_file($this->langPath . '/unused-baseline.php')) {
            $file = require $this->langPath . '/unused-baseline.php';
            if (is_array($file)) {
                foreach ($file as $key) {
                    $baseline[] = (string) $key;
                }
            }
        }

        return array_values(array_unique($baseline));
    }

    /**
     * Files scanned for key references only (the test suite).
     *
     * @return list<string>
     */
    private function usageOnlyFiles(): array
    {
        $files = [];

        foreach ($this->usageOnlyPaths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    private function isJavaScript(string $file): bool
    {
        return str_ends_with(str_replace('\\', '/', $file), '.js');
    }

    /**
     * Detect literal French copy inside admin JavaScript.
     *
     * Any string literal that contains letters and is not routed through `tr()` / `t()` is
     * reported: the admin bundle must never ship untranslatable copy.
     *
     * @return array<int, string>
     */
    private function hardcodedJavaScript(string $contents): array
    {
        $hits = [];
        $inBlockComment = false;

        foreach (explode("\n", $contents) as $index => $rawLine) {
            $line = $rawLine;
            $trimmed = trim($line);

            if ($inBlockComment) {
                if (str_contains($line, '*/')) {
                    $inBlockComment = false;
                }
                continue;
            }
            if (str_starts_with($trimmed, '/*')) {
                if (!str_contains($line, '*/')) {
                    $inBlockComment = true;
                }
                continue;
            }
            if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')
                || str_starts_with($trimmed, 'import ') || str_starts_with($trimmed, '} from ')) {
                continue;
            }

            // Drop comments before scanning so a French sentence in a comment is not reported.
            $line = (string) preg_replace('#//.*$#', '', $line);
            $line = (string) preg_replace('#/\*.*?\*/#', '', $line);

            // Blank out the arguments of `tr(...)` / `t(...)` / `choice(...)`: the key and the
            // French fallback are both translated strings, whatever escaping they use.
            $line = $this->blankTranslationCalls($line);

            if (preg_match_all('/([\'"`])((?:[^\'"`\\\n]|\\.){3,200})\1/u', $line, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($matches as $match) {
                $value = (string) $match[2][0];
                $offset = (int) $match[0][1];
                $before = substr($line, 0, $offset);

                // `tr('key', 'texte')`, `t('key')` and `choice('key', …)` are translated already:
                // the literal is either the key or the explicit French fallback.
                if (preg_match('/(?:\btr|\bt|\bchoice)\(\s*$/', $before) === 1) {
                    continue;
                }
                if (preg_match('/(?:\btr|\bt|\bchoice)\(\s*(?:\'[^\']*\'|"[^"]*")\s*,\s*$/', $before) === 1) {
                    continue;
                }

                if (!$this->looksLikeCopy($value)) {
                    continue;
                }

                $hits[$index + 1] = strlen($value) > 80 ? substr($value, 0, 77) . '…' : $value;
                break;
            }

            if (count($hits) >= 200) {
                break;
            }
        }

        return $hits;
    }

    /**
     * Replace every `tr(...)`, `t(...)` and `choice(...)` call with `tr()`.
     *
     * A character scanner is used instead of a regular expression because the arguments are
     * arbitrary JavaScript string literals: they may contain escaped quotes, parentheses and
     * interpolations, which a pattern cannot reliably delimit.
     */
    private function blankTranslationCalls(string $line): string
    {
        $out = '';
        $length = strlen($line);
        $quote = null;
        $i = 0;

        while ($i < $length) {
            $char = $line[$i];

            if ($quote !== null) {
                $out .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $out .= $line[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                $i++;
                continue;
            }

            if ($char === "\'" || $char === '"' || $char === '`') {
                $quote = $char;
                $out .= $char;
                $i++;
                continue;
            }

            if (preg_match('/\G(?:tr|t|choice)\s*\(/', $line, $m, 0, $i) === 1
                && ($i === 0 || preg_match('/[A-Za-z0-9_$.]/', $line[$i - 1]) !== 1)) {
                $i += strlen($m[0]);
                $depth = 1;
                $inner = null;
                while ($i < $length && $depth > 0) {
                    $c = $line[$i];
                    if ($inner !== null) {
                        if ($c === '\\') {
                            $i += 2;
                            continue;
                        }
                        if ($c === $inner) {
                            $inner = null;
                        }
                        $i++;
                        continue;
                    }
                    if ($c === "\'" || $c === '"' || $c === '`') {
                        $inner = $c;
                        $i++;
                        continue;
                    }
                    if ($c === '(') {
                        $depth++;
                    }
                    if ($c === ')') {
                        $depth--;
                    }
                    $i++;
                }
                $out .= 'tr()';
                continue;
            }

            $out .= $char;
            $i++;
        }

        return $out;
    }

    /**
     * Whether a literal is prose rather than an identifier, selector, URL or class name.
     */
    private function looksLikeCopy(string $value): bool
    {
        if (preg_match('/[A-Za-zÀ-ÿ]/u', $value) !== 1) {
            return false;
        }
        // Identifiers, slugs, selectors, URLs, class lists and code fragments.
        if (preg_match('/^[a-z0-9_.\-\/:]+$/i', $value) === 1) {
            return false;
        }
        if (preg_match('#<[a-zA-Z/!]|\[data-|\[type=|\[contenteditable|^https?://|\.(?:js|css|php|webp?|png|jpe?g|svg|json)\b#', $value) === 1) {
            return false;
        }
        if (preg_match('/^[\w-]+(?:[\s.,>+~][\w-]+)*$/', $value) === 1
            && preg_match(self::ACCENT_PATTERN, $value) !== 1) {
            return false;
        }

        return preg_match(self::FRENCH_WORD_PATTERN, $value) === 1
            || preg_match(self::ACCENT_PATTERN, $value) === 1;
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
