<?php

declare(strict_types=1);

namespace Prime\View;

use Prime\Hooks\Hooks;
use Prime\View\Exception\ViewException;
use Prime\View\Exception\ViewNotFoundException;
use Throwable;

/**
 * Pure-PHP template engine with layouts, sections, partials, escaping, and view namespaces.
 */
final class View
{
    private const MAX_LAYOUT_DEPTH = 10;

    /**
     * Ordered fallback directories for the default (non-namespaced) views.
     *
     * @var list<string>
     */
    private array $paths = [];

    /**
     * Map of view namespace to ordered directories (e.g. `admin::dashboard`).
     *
     * @var array<string, list<string>>
     */
    private array $namespaces = [];

    public function __construct(
        string $baseDir,
        private readonly ?Hooks $hooks = null,
    ) {
        $this->addPath($baseDir);
    }

    /**
     * Add a base directory to the default view search path.
     */
    public function addPath(string $directory, bool $prepend = false): self
    {
        $normalized = rtrim($directory, DIRECTORY_SEPARATOR . '/');

        if ($prepend) {
            array_unshift($this->paths, $normalized);
        } else {
            $this->paths[] = $normalized;
        }

        return $this;
    }

    /**
     * Register a view namespace directory (e.g. `admin::dashboard`).
     */
    public function addNamespace(string $namespace, string $directory, bool $prepend = false): self
    {
        $namespace = trim($namespace);
        $normalized = rtrim($directory, DIRECTORY_SEPARATOR . '/');

        if ($namespace === '') {
            throw new ViewException('View namespace cannot be empty.');
        }

        if (!isset($this->namespaces[$namespace])) {
            $this->namespaces[$namespace] = [];
        }

        if ($prepend) {
            array_unshift($this->namespaces[$namespace], $normalized);
        } else {
            $this->namespaces[$namespace][] = $normalized;
        }

        return $this;
    }

    /**
     * Check whether a template exists.
     */
    public function exists(string $template): bool
    {
        try {
            $this->resolve($template);

            return true;
        } catch (ViewNotFoundException) {
            return false;
        }
    }

    /**
     * Resolve a template identifier (dot notation and optional `namespace::` prefix) to a file path.
     */
    public function resolve(string $template): string
    {
        if (str_contains($template, "\0") || str_contains($template, '..')) {
            throw new ViewException(sprintf('Invalid template name [%s].', $template));
        }

        $directories = $this->paths;
        $name = $template;

        if (str_contains($template, '::')) {
            [$namespace, $name] = explode('::', $template, 2);
            if (!isset($this->namespaces[$namespace])) {
                throw new ViewNotFoundException(sprintf('View namespace [%s] is not registered.', $namespace));
            }
            $directories = $this->namespaces[$namespace];
        }

        $relativePath = str_replace('.', DIRECTORY_SEPARATOR, trim($name, '.')) . '.php';

        foreach ($directories as $directory) {
            $candidate = $directory . DIRECTORY_SEPARATOR . $relativePath;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new ViewNotFoundException(sprintf('View template [%s] not found.', $template));
    }

    /**
     * Render a template with the given data array.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        return $this->renderInternal($template, $data, [], 0);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $sections
     */
    private function renderInternal(string $template, array $data, array $sections, int $depth): string
    {
        if ($depth > self::MAX_LAYOUT_DEPTH) {
            throw new ViewException(sprintf('Maximum layout nesting depth (%d) exceeded while rendering [%s].', self::MAX_LAYOUT_DEPTH, $template));
        }

        if ($this->hooks !== null) {
            $filtered = $this->hooks->apply('view.data', $data, $template);
            if (is_array($filtered)) {
                /** @var array<string, mixed> $filtered */
                $data = $filtered;
            }
        }

        $file = $this->resolve($template);
        $context = new TemplateContext($this, $sections);

        $output = $this->evaluateTemplate($file, $data, $context);

        if ($context->hasOpenSections()) {
            $context->cleanupOpenSections();

            throw new ViewException(sprintf('Template [%s] left one or more sections unclosed.', $template));
        }

        $layout = $context->layoutTemplate();
        if ($layout !== null) {
            $nextSections = $context->sections();
            if (!array_key_exists('content', $nextSections) && trim($output) !== '') {
                $nextSections['content'] = $output;
            }

            $layoutData = array_merge($data, $context->layoutData());

            return $this->renderInternal($layout, $layoutData, $nextSections, $depth + 1);
        }

        return $output;
    }

    /**
     * Execute a PHP template file in an isolated scope without `extract()`.
     *
     * @param array<string, mixed> $data
     */
    private function evaluateTemplate(string $file, array $data, TemplateContext $context): string
    {
        if (array_key_exists('this', $data)) {
            throw new ViewException('View data key "this" is reserved and cannot be used.');
        }

        $renderer = function (string $__primeTemplateFile, array $__primeTemplateData): string {
            foreach ($__primeTemplateData as $__primeKey => $__primeValue) {
                if (
                    is_string($__primeKey)
                    && $__primeKey !== 'this'
                    && !str_starts_with($__primeKey, '__')
                    && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $__primeKey) === 1
                ) {
                    ${$__primeKey} = $__primeValue;
                }
            }

            ob_start();

            try {
                require $__primeTemplateFile;
                $buffer = ob_get_clean();

                return $buffer === false ? '' : $buffer;
            } catch (Throwable $e) {
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }

                throw $e;
            }
        };

        $boundRenderer = $renderer->bindTo($context, TemplateContext::class);

        try {
            return $boundRenderer($file, $data);
        } catch (Throwable $e) {
            $context->cleanupOpenSections();

            throw $e;
        }
    }
}
