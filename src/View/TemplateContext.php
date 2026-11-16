<?php

declare(strict_types=1);

namespace Prime\View;

use DateTimeInterface;
use Prime\I18n\LocaleFormatter;
use Prime\I18n\Translator;
use Prime\Kernel\Application;
use Prime\Media\Media;
use Prime\Media\MediaHelper;
use Prime\Security\Csrf;
use Prime\View\Exception\ViewException;
use Stringable;

/**
 * Execution context bound to `$this` inside PHP templates.
 */
final class TemplateContext
{
    private ?string $layoutTemplate = null;

    /**
     * @var array<string, mixed>
     */
    private array $layoutData = [];

    /**
     * @var array<string, string>
     */
    private array $sections;

    /**
     * Stack of open section names currently buffering output.
     *
     * @var list<string>
     */
    private array $sectionStack = [];

    /**
     * @param array<string, string> $sections
     */
    public function __construct(
        private readonly View $view,
        array $sections = [],
    ) {
        $this->sections = $sections;
    }

    /**
     * Declare a parent layout template to wrap the current view.
     *
     * @param array<string, mixed> $data
     */
    public function layout(string $template, array $data = []): void
    {
        $this->layoutTemplate = $template;
        $this->layoutData = $data;
    }

    /**
     * Start capturing output into a named section.
     */
    public function start(string $section): void
    {
        $this->sectionStack[] = $section;
        ob_start();
    }

    /**
     * Finish capturing output for the currently open section.
     */
    public function stop(): void
    {
        if ($this->sectionStack === []) {
            throw new ViewException('Cannot call stop() without a matching start() call.');
        }

        $section = array_pop($this->sectionStack);
        $content = ob_get_clean();

        $this->sections[$section] = $content === false ? '' : $content;
    }

    /**
     * Translate a `group.key` string using the locale currently rendering this template.
     *
     * @param array<string|int, mixed> $replace
     */
    public function t(string $key, array $replace = [], ?string $locale = null): string
    {
        return $this->translator()->translate($key, $replace, $locale);
    }

    /**
     * Translate a pluralized line (`singular|plural`) for a count.
     *
     * @param array<string|int, mixed> $replace
     */
    public function choice(string $key, int|float $number, array $replace = [], ?string $locale = null): string
    {
        return $this->translator()->choice($key, $number, $replace, $locale);
    }

    /**
     * Determine whether a translation key exists (useful for optional theme strings).
     */
    public function hasTranslation(string $key, ?string $locale = null): bool
    {
        return $this->translator()->has($key, $locale);
    }

    public function locale(): string
    {
        return $this->translator()->locale();
    }

    /**
     * Text direction of the active locale (`ltr` or `rtl`).
     */
    public function direction(): string
    {
        return $this->formatter()->direction();
    }

    /**
     * Display name of a locale in the active language.
     */
    public function localeName(string $locale): string
    {
        return $this->formatter()->localeName($locale);
    }

    public function formatDate(DateTimeInterface|int|string $date, string $preset = 'medium'): string
    {
        return $this->formatter()->date($date, $preset);
    }

    public function formatTime(DateTimeInterface|int|string $date, string $preset = 'time'): string
    {
        return $this->formatter()->time($date, $preset);
    }

    public function formatDateTime(DateTimeInterface|int|string $date, string $preset = 'dateTime'): string
    {
        return $this->formatter()->dateTime($date, $preset);
    }

    /**
     * Human readable distance to now ("il y a 3 heures").
     */
    public function formatRelative(DateTimeInterface|int|string $date): string
    {
        return $this->formatter()->relative($date);
    }

    public function formatNumber(int|float $value, ?int $decimals = null): string
    {
        return $this->formatter()->number($value, $decimals);
    }

    public function formatCurrency(int|float $value, ?string $currency = null): string
    {
        return $this->formatter()->currency($value, $currency);
    }

    /**
     * Resolve the translator bound to the application (or a detached one in tests).
     */
    private function translator(): Translator
    {
        if (Application::hasInstance() && Application::getInstance()->container()->has(Translator::class)) {
            /** @var Translator $translator */
            $translator = Application::getInstance()->get(Translator::class);

            return $translator;
        }

        return Translator::fallback();
    }

    private function formatter(): LocaleFormatter
    {
        if (Application::hasInstance() && Application::getInstance()->container()->has(LocaleFormatter::class)) {
            /** @var LocaleFormatter $formatter */
            $formatter = Application::getInstance()->get(LocaleFormatter::class);

            return $formatter;
        }

        return new LocaleFormatter($this->translator());
    }

    /**
     * Retrieve the rendered contents of a named section.
     */
    public function section(string $section, string $default = ''): string
    {
        return $this->sections[$section] ?? $default;
    }

    /**
     * Determine whether a named section has been defined.
     */
    public function hasSection(string $section): bool
    {
        return array_key_exists($section, $this->sections);
    }

    /**
     * Render and return a partial template.
     *
     * @param array<string, mixed> $data
     */
    public function insert(string $template, array $data = []): string
    {
        return $this->view->render($template, $data);
    }

    /**
     * Escape a value for safe HTML output.
     */
    public function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        if (!is_scalar($value) && !$value instanceof Stringable) {
            return '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Explicitly pass through trusted raw HTML without escaping.
     */
    public function raw(string $html): string
    {
        return $html;
    }

    /**
     * Render an escaped Lucide SVG icon from the local sprite.
     */
    public function icon(string $name, int $size = 20, string $class = ''): string
    {
        return Icon::render($name, $size, $class);
    }

    /**
     * Return the active session CSRF token.
     */
    public function csrfToken(): string
    {
        /** @var Csrf $csrf */
        $csrf = Application::getInstance()->get(Csrf::class);

        return $csrf->token();
    }

    /**
     * Render a hidden HTML input containing the active CSRF token.
     */
    public function csrfField(): string
    {
        /** @var Csrf $csrf */
        $csrf = Application::getInstance()->get(Csrf::class);

        return $csrf->field();
    }

    /**
     * Resolve a media URL (or variant URL) from a Media entity or ID.
     */
    public function mediaUrl(int|Media|null $media, ?string $variant = null, ?string $format = null): ?string
    {
        return MediaHelper::url($media, $variant, $format);
    }

    /**
     * Build a responsive `srcset` attribute string for an image media.
     *
     * @param 'original'|'webp'|null $format
     */
    public function mediaSrcset(int|Media|null $media, ?string $format = null): string
    {
        return MediaHelper::srcset($media, $format);
    }

    /**
     * Render a responsive `<picture>` / `<img>` HTML tag for an image media.
     *
     * @param array<string, mixed> $attrs
     */
    public function mediaImage(int|Media|null $media, array $attrs = []): string
    {
        return MediaHelper::image($media, $attrs);
    }

    /**
     * @internal
     */
    public function layoutTemplate(): ?string
    {
        return $this->layoutTemplate;
    }

    /**
     * @internal
     * @return array<string, mixed>
     */
    public function layoutData(): array
    {
        return $this->layoutData;
    }

    /**
     * @internal
     * @return array<string, string>
     */
    public function sections(): array
    {
        return $this->sections;
    }

    /**
     * @internal
     */
    public function hasOpenSections(): bool
    {
        return $this->sectionStack !== [];
    }

    /**
     * Clean up any unclosed section buffers on failure.
     *
     * @internal
     */
    public function cleanupOpenSections(): void
    {
        while ($this->sectionStack !== []) {
            array_pop($this->sectionStack);
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
        }
    }
}
