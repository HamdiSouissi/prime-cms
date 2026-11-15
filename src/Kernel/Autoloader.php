<?php

declare(strict_types=1);

namespace Prime\Kernel;

/**
 * PSR-4 autoloader with support for multiple base directories per namespace.
 */
final class Autoloader
{
    /**
     * Map of namespace prefix to ordered list of base directories.
     *
     * @var array<string, list<string>>
     */
    private array $prefixes = [];

    private bool $registered = false;

    /**
     * Register this autoloader on the SPL autoload stack.
     */
    public function register(bool $prepend = false): void
    {
        if ($this->registered) {
            return;
        }

        spl_autoload_register([$this, 'loadClass'], true, $prepend);
        $this->registered = true;
    }

    /**
     * Unregister this autoloader from the SPL autoload stack.
     */
    public function unregister(): void
    {
        if (!$this->registered) {
            return;
        }

        spl_autoload_unregister([$this, 'loadClass']);
        $this->registered = false;
    }

    /**
     * Map a namespace prefix to a base directory.
     */
    public function addNamespace(string $prefix, string $baseDir, bool $prepend = false): self
    {
        $prefix = trim($prefix, '\\') . '\\';
        $baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR;

        if (!isset($this->prefixes[$prefix])) {
            $this->prefixes[$prefix] = [];
        }

        $existingIndex = array_search($baseDir, $this->prefixes[$prefix], true);
        if ($existingIndex !== false) {
            array_splice($this->prefixes[$prefix], $existingIndex, 1);
        }

        if ($prepend) {
            array_unshift($this->prefixes[$prefix], $baseDir);
        } else {
            $this->prefixes[$prefix][] = $baseDir;
        }

        return $this;
    }

    /**
     * Attempt to load the file corresponding to a fully-qualified class name.
     */
    public function loadClass(string $class): bool
    {
        $prefix = ltrim($class, '\\');

        while (($pos = strrpos($prefix, '\\')) !== false) {
            $currentPrefix = substr($prefix, 0, $pos + 1);
            $relativeClass = substr($class, $pos + 1);

            if ($this->loadMappedFile($currentPrefix, $relativeClass)) {
                return true;
            }

            $prefix = rtrim($currentPrefix, '\\');
        }

        return false;
    }

    /**
     * Return all registered namespace prefixes and their base directories.
     *
     * @return array<string, list<string>>
     */
    public function prefixes(): array
    {
        return $this->prefixes;
    }

    /**
     * Load the first existing file matching a prefix and relative class name.
     */
    private function loadMappedFile(string $prefix, string $relativeClass): bool
    {
        if (!isset($this->prefixes[$prefix])) {
            return false;
        }

        $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

        foreach ($this->prefixes[$prefix] as $baseDir) {
            $file = $baseDir . $relativePath;
            if (is_file($file)) {
                require $file;

                return true;
            }
        }

        return false;
    }
}
