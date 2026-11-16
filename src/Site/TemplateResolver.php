<?php

declare(strict_types=1);

namespace Prime\Site;

use Prime\Hooks\Hooks;

/**
 * Prime CMS native template hierarchy resolver (L3).
 *
 * Builds the exact ordered candidate list for every `PageContext`, applies the
 * `site.template_candidates` and `site.template_paths` filters, searches child theme
 * -> parent theme -> plugin fallback paths, and enforces strict realpath confinement
 * so no slug or template name can ever escape theme or plugin template roots.
 */
final class TemplateResolver
{
    private const SAFE_SEGMENT = '/^[a-z0-9][a-z0-9_-]{0,120}$/';

    public function __construct(
        private readonly ?Hooks $hooks = null,
    ) {
    }

    /**
     * Compute the ordered list of relative template candidate paths for `$context`.
     *
     * @return list<string>
     */
    public function candidates(PageContext $context): array
    {
        $list = match ($context->type) {
            PageContext::TYPE_FRONT => $this->frontCandidates($context),
            PageContext::TYPE_BLOG => ['blog.php', 'archive/post.php', 'archive/default.php'],
            PageContext::TYPE_ENTRY => $this->entryCandidates($context),
            PageContext::TYPE_ARCHIVE => $this->archiveCandidates($context),
            PageContext::TYPE_TERM => $this->termCandidates($context),
            PageContext::TYPE_AUTHOR => ['author.php', 'archive/default.php'],
            PageContext::TYPE_SEARCH => ['search.php', 'archive/default.php'],
            default => ['404.php'],
        };

        if ($this->hooks !== null) {
            $filtered = $this->hooks->apply('site.template_candidates', $list, $context);
            if (is_array($filtered)) {
                $clean = [];
                foreach ($filtered as $cand) {
                    if (is_string($cand) && $this->isSafeRelativeTemplate($cand)) {
                        $clean[] = ltrim(str_replace('\\', '/', $cand), '/');
                    }
                }
                if ($clean !== []) {
                    $list = $clean;
                }
            }
        }

        return array_values(array_unique($list));
    }

    /**
     * Resolve the first existing template file across ordered template root directories.
     *
     * `$themeTemplateDirs` should list `[childTemplatesDir, parentTemplatesDir]` (only existing ones).
     * Additional plugin fallback directories can be appended via the `site.template_paths` filter.
     *
     * @param list<string> $themeTemplateDirs
     * @return ?array{file: string, root: string, candidate: string}
     */
    public function resolve(PageContext $context, array $themeTemplateDirs): ?array
    {
        $candidates = $this->candidates($context);
        $roots = $this->orderedRoots($themeTemplateDirs, $context);

        foreach ($candidates as $candidate) {
            if (!$this->isSafeRelativeTemplate($candidate)) {
                continue;
            }
            foreach ($roots as $root) {
                $resolved = $this->confinedFile($root, $candidate);
                if ($resolved !== null) {
                    return [
                        'file' => $resolved,
                        'root' => $root,
                        'candidate' => $candidate,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Resolve a partial or layout file safely within `[childBaseDir, parentBaseDir]` (strictly confined).
     *
     * @param list<string> $themeBaseDirs
     */
    public function resolveRelative(string $relativeFile, array $themeBaseDirs): ?string
    {
        if (!$this->isSafeRelativeTemplate($relativeFile)) {
            return null;
        }

        foreach ($themeBaseDirs as $dir) {
            $realRoot = realpath($dir);
            if ($realRoot === false || !is_dir($realRoot)) {
                continue;
            }
            $file = $this->confinedFile($realRoot, $relativeFile);
            if ($file !== null) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Verify a relative template path contains only safe characters and no traversal.
     */
    public function isSafeRelativeTemplate(string $relativePath): bool
    {
        if (
            $relativePath === ''
            || str_starts_with($relativePath, '/')
            || str_contains($relativePath, '\\')
            || str_contains($relativePath, "\0")
            || str_contains($relativePath, '..')
        ) {
            return false;
        }
        $normalized = $relativePath;
        if (!str_ends_with($normalized, '.php')) {
            return false;
        }

        $withoutExt = substr($normalized, 0, -4);
        foreach (explode('/', $withoutExt) as $segment) {
            if (preg_match(self::SAFE_SEGMENT, $segment) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $themeTemplateDirs
     * @return list<string>
     */
    private function orderedRoots(array $themeTemplateDirs, PageContext $context): array
    {
        $dirs = [];
        foreach ($themeTemplateDirs as $dir) {
            if ($dir !== '' && is_dir($dir)) {
                $real = realpath($dir);
                if ($real !== false && !in_array($real, $dirs, true)) {
                    $dirs[] = $real;
                }
            }
        }

        $pluginPaths = [];
        if ($this->hooks !== null) {
            $filtered = $this->hooks->apply('site.template_paths', $pluginPaths, $context);
            if (is_array($filtered)) {
                foreach ($filtered as $p) {
                    if (is_string($p) && $p !== '' && !str_contains($p, "\0") && is_dir($p)) {
                        $real = realpath($p);
                        if ($real !== false && !in_array($real, $dirs, true)) {
                            $dirs[] = $real;
                        }
                    }
                }
            }
        }

        return $dirs;
    }

    private function confinedFile(string $canonicalRoot, string $relativeCandidate): ?string
    {
        $candidatePath = $canonicalRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relativeCandidate, '/'));
        if (!is_file($candidatePath) || !is_readable($candidatePath)) {
            return null;
        }

        $realFile = realpath($candidatePath);
        if ($realFile === false) {
            return null;
        }

        $prefix = rtrim($canonicalRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($realFile, $prefix)) {
            return null;
        }

        return $realFile;
    }

    /**
     * @return list<string>
     */
    private function frontCandidates(PageContext $context): array
    {
        $candidates = ['front.php'];
        if ($context->entry !== null) {
            foreach ($this->entryCandidates($context) as $c) {
                $candidates[] = $c;
            }
        } else {
            $candidates[] = 'blog.php';
            $candidates[] = 'archive/post.php';
            $candidates[] = 'archive/default.php';
        }

        return $candidates;
    }

    /**
     * @return list<string>
     */
    private function entryCandidates(PageContext $context): array
    {
        $entry = $context->entry;
        if ($entry === null) {
            return ['entry/default.php'];
        }

        $candidates = [];
        $type = $this->safeSegment($entry->type);
        $slug = $this->safeSegment($entry->slug);
        $template = $entry->template !== null ? $this->safeSegment($entry->template) : null;

        if ($type !== null && $template !== null) {
            $candidates[] = sprintf('entry/%s/%s.php', $type, $template);
        }
        if ($type !== null && $slug !== null) {
            $candidates[] = sprintf('entry/%s/%s.php', $type, $slug);
        }
        if ($type !== null) {
            $candidates[] = sprintf('entry/%s.php', $type);
        }

        $candidates[] = 'entry/default.php';

        return $candidates;
    }

    /**
     * @return list<string>
     */
    private function archiveCandidates(PageContext $context): array
    {
        $candidates = [];
        $type = $context->contentType !== null ? $this->safeSegment($context->contentType->name) : null;
        if ($type !== null) {
            $candidates[] = sprintf('archive/%s.php', $type);
        }
        $candidates[] = 'archive/default.php';

        return $candidates;
    }

    /**
     * @return list<string>
     */
    private function termCandidates(PageContext $context): array
    {
        $candidates = [];
        $tax = $context->taxonomy !== null ? $this->safeSegment($context->taxonomy->name) : null;
        $slug = $context->term !== null ? $this->safeSegment($context->term->slug) : null;

        if ($tax !== null && $slug !== null) {
            $candidates[] = sprintf('term/%s/%s.php', $tax, $slug);
        }
        if ($tax !== null) {
            $candidates[] = sprintf('term/%s.php', $tax);
        }
        $candidates[] = 'term/default.php';
        $candidates[] = 'archive/default.php';

        return $candidates;
    }

    private function safeSegment(string $raw): ?string
    {
        $clean = strtolower(trim($raw));
        if ($clean === '' || str_contains($clean, '..') || str_contains($clean, '/') || str_contains($clean, '\\')) {
            return null;
        }

        return preg_match(self::SAFE_SEGMENT, $clean) === 1 ? $clean : null;
    }
}
