<?php

declare(strict_types=1);

namespace Prime\Admin\Assets;

use RuntimeException;

/**
 * Pure-PHP CSS bundler and minifier driven by a PHP manifest file.
 */
final class CssBuilder
{
    /**
     * Concatenate and minify all CSS source files declared in `$manifestPath` into `$outputPath`.
     *
     * @return array{files: list<string>, bytes: int, output: string}
     */
    public function build(string $manifestPath, string $sourceDir, string $outputPath): array
    {
        if (!is_file($manifestPath) || !is_readable($manifestPath)) {
            throw new RuntimeException(sprintf('CSS manifest file [%s] does not exist.', $manifestPath));
        }

        $manifest = require $manifestPath;
        if (!is_array($manifest) || $manifest === []) {
            throw new RuntimeException(sprintf('CSS manifest [%s] must return a non-empty list of files.', $manifestPath));
        }

        $baseDir = rtrim($sourceDir, DIRECTORY_SEPARATOR . '/');
        $chunks = [];
        $files = [];

        foreach ($manifest as $relativePath) {
            if (!is_string($relativePath) || $relativePath === '' || str_contains($relativePath, '..')) {
                throw new RuntimeException('CSS manifest contains an invalid file path.');
            }

            $fullPath = $baseDir . DIRECTORY_SEPARATOR . ltrim($relativePath, DIRECTORY_SEPARATOR . '/');
            if (!is_file($fullPath) || !is_readable($fullPath)) {
                throw new RuntimeException(sprintf('CSS source file [%s] not found.', $fullPath));
            }

            $contents = file_get_contents($fullPath);
            if ($contents === false) {
                throw new RuntimeException(sprintf('Unable to read CSS source file [%s].', $fullPath));
            }

            $files[] = $relativePath;
            $chunks[] = $contents;
        }

        $minified = $this->minify(implode("\n", $chunks));

        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            throw new RuntimeException(sprintf('Unable to create CSS output directory [%s].', $outputDir));
        }

        $written = file_put_contents($outputPath, $minified . "\n");
        if ($written === false) {
            throw new RuntimeException(sprintf('Unable to write compiled CSS to [%s].', $outputPath));
        }

        return [
            'files' => $files,
            'bytes' => $written,
            'output' => $outputPath,
        ];
    }

    /**
     * Strip comments and redundant whitespace while preserving quoted strings,
     * `calc()` expressions, and descendant pseudo-class selectors.
     */
    public function minify(string $css): string
    {
        // Protect quoted strings from comment stripping and whitespace collapsing.
        $strings = [];
        $protected = preg_replace_callback(
            '/"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'/',
            static function (array $match) use (&$strings): string {
                $key = '__PRIME_STR_' . count($strings) . '__';
                $strings[$key] = $match[0];

                return $key;
            },
            $css,
        );
        $css = $protected ?? $css;

        // Strip standard CSS comments (preserve `/*! ... */` license comments).
        $css = preg_replace('#/\*(?!\!).*?\*/#s', '', $css) ?? $css;

        // Collapse whitespace runs to a single space.
        $css = preg_replace('/\s+/', ' ', $css) ?? $css;

        // Remove redundant spaces around structural punctuation while keeping descendant ` :pseudo` safe.
        $css = preg_replace('/(?<=[:;,{}])\s+/', '', $css) ?? $css;
        $css = preg_replace('/\s+(?=[{};,])/', '', $css) ?? $css;
        $css = str_replace(';}', '}', $css);
        $css = trim($css);

        // Restore quoted strings verbatim.
        if ($strings !== []) {
            $css = strtr($css, $strings);
        }

        return $css;
    }
}
