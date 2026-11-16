<?php

declare(strict_types=1);

namespace Prime\Site\Seo;

use Prime\Settings\Settings;
use Throwable;

/**
 * Reads and persists global SEO settings (« Réglages › Référencement »).
 */
final class SeoSettings
{
    public const DEFAULT_SEPARATOR = '—';
    public const DEFAULT_TITLE_TEMPLATE = '{title} {sep} {site_name}';

    /** @var array<string, mixed> */
    private array $overrides;

    /**
     * @param array<string, mixed> $overrides
     */
    public function __construct(
        private readonly ?Settings $settings = null,
        array $overrides = [],
    ) {
        $this->overrides = $overrides;
    }

    public function separator(): string
    {
        $val = $this->read('seo.separator', self::DEFAULT_SEPARATOR);

        return is_string($val) && trim($val) !== '' ? mb_substr(trim($val), 0, 10) : self::DEFAULT_SEPARATOR;
    }

    public function titleTemplate(): string
    {
        $val = $this->read('seo.title_template', self::DEFAULT_TITLE_TEMPLATE);

        return is_string($val) && trim($val) !== '' ? trim($val) : self::DEFAULT_TITLE_TEMPLATE;
    }

    public function defaultDescription(): string
    {
        $val = $this->read('seo.default_description', '');

        return is_string($val) ? trim($val) : '';
    }

    public function defaultImageId(): ?int
    {
        $val = $this->read('seo.default_image_id', null);

        return is_numeric($val) && (int) $val > 0 ? (int) $val : null;
    }

    public function globalNoindex(): bool
    {
        return (bool) $this->read('seo.global_noindex', false);
    }

    public function robotsCustom(): string
    {
        $val = $this->read('seo.robots_custom', '');

        return is_string($val) ? trim($val) : '';
    }

    /**
     * Validate and save SEO settings.
     *
     * @param array<string, mixed> $input
     * @return list<string> Validation errors (empty on success)
     */
    public function save(array $input): array
    {
        $errors = [];

        $sep = is_string($input['separator'] ?? null) ? trim(strip_tags($input['separator'])) : self::DEFAULT_SEPARATOR;
        if ($sep === '' || mb_strlen($sep, 'UTF-8') > 10) {
            $errors[] = 'Le séparateur de titre doit contenir entre 1 et 10 caractères.';
        }

        $tpl = is_string($input['title_template'] ?? null) ? trim(strip_tags($input['title_template'])) : self::DEFAULT_TITLE_TEMPLATE;
        if ($tpl === '' || mb_strlen($tpl, 'UTF-8') > 160) {
            $errors[] = 'Le modèle de titre doit contenir entre 1 et 160 caractères.';
        }

        $desc = is_string($input['default_description'] ?? null) ? trim(strip_tags($input['default_description'])) : '';
        if (mb_strlen($desc, 'UTF-8') > 320) {
            $errors[] = 'La méta-description par défaut ne peut pas dépasser 320 caractères.';
        }

        $imageId = is_numeric($input['default_image_id'] ?? null) && (int) $input['default_image_id'] > 0
            ? (int) $input['default_image_id']
            : null;

        $globalNoindex = !empty($input['global_noindex'])
            && !in_array(strtolower((string) $input['global_noindex']), ['0', 'false', 'off', ''], true);

        $robots = is_string($input['robots_custom'] ?? null) ? trim($input['robots_custom']) : '';
        if (mb_strlen($robots, 'UTF-8') > 4000) {
            $errors[] = 'Les règles personnalisées robots.txt ne peuvent pas dépasser 4 000 caractères.';
        }

        if ($errors !== []) {
            return $errors;
        }

        $this->write('seo.separator', $sep);
        $this->write('seo.title_template', $tpl);
        $this->write('seo.default_description', $desc);
        $this->write('seo.default_image_id', $imageId);
        $this->write('seo.global_noindex', $globalNoindex);
        $this->write('seo.robots_custom', $robots);

        return [];
    }

    private function read(string $key, mixed $default): mixed
    {
        if (array_key_exists($key, $this->overrides)) {
            return $this->overrides[$key];
        }
        if ($this->settings === null) {
            return $default;
        }

        try {
            return $this->settings->get($key, $default);
        } catch (Throwable) {
            return $default;
        }
    }

    private function write(string $key, mixed $value): void
    {
        $this->overrides[$key] = $value;
        if ($this->settings !== null) {
            $this->settings->set($key, $value, autoload: true);
        }
    }
}
