<?php

declare(strict_types=1);

namespace Prime\Site;

use Prime\Settings\Settings;
use Throwable;

/**
 * Reads and persists public URL permalink structures and reading settings
 * (`front_mode`, `front_page_id`, `posts_page_id`, `posts_per_page`).
 */
final class PermalinkSettings
{
    public const POST_BLOG_SLUG = '/blog/{slug}';
    public const POST_YEAR_MONTH_SLUG = '/{annee}/{mois}/{slug}';
    public const POST_SLUG_ONLY = '/{slug}';

    /**
     * @var list<string>
     */
    public const ALLOWED_POST_STRUCTURES = [
        self::POST_BLOG_SLUG,
        self::POST_YEAR_MONTH_SLUG,
        self::POST_SLUG_ONLY,
    ];

    public const FRONT_MODE_POSTS = 'posts';
    public const FRONT_MODE_PAGE = 'page';

    public const DEFAULT_CATEGORY_BASE = 'categorie';
    public const DEFAULT_TAG_BASE = 'etiquette';
    public const DEFAULT_AUTHOR_BASE = 'auteur';
    public const DEFAULT_SEARCH_BASE = 'recherche';
    public const DEFAULT_POSTS_PER_PAGE = 10;

    /**
     * Optional in-memory override map for unit testing without database persistence.
     *
     * @var array<string, mixed>
     */
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

    public function postStructure(): string
    {
        $val = $this->read('permalinks.post_structure', self::POST_BLOG_SLUG);
        if (is_string($val) && in_array($val, self::ALLOWED_POST_STRUCTURES, true)) {
            return $val;
        }

        return self::POST_BLOG_SLUG;
    }

    public function categoryBase(): string
    {
        return $this->sanitizeBase($this->read('permalinks.category_base', self::DEFAULT_CATEGORY_BASE), self::DEFAULT_CATEGORY_BASE);
    }

    public function tagBase(): string
    {
        return $this->sanitizeBase($this->read('permalinks.tag_base', self::DEFAULT_TAG_BASE), self::DEFAULT_TAG_BASE);
    }

    public function authorBase(): string
    {
        return $this->sanitizeBase($this->read('permalinks.author_base', self::DEFAULT_AUTHOR_BASE), self::DEFAULT_AUTHOR_BASE);
    }

    public function searchBase(): string
    {
        return $this->sanitizeBase($this->read('permalinks.search_base', self::DEFAULT_SEARCH_BASE), self::DEFAULT_SEARCH_BASE);
    }

    public function taxonomyBase(string $taxonomy): string
    {
        return match ($taxonomy) {
            'category' => $this->categoryBase(),
            'tag' => $this->tagBase(),
            default => $this->sanitizeBase($taxonomy, $taxonomy),
        };
    }

    public function frontMode(): string
    {
        $val = $this->read('reading.front_mode', self::FRONT_MODE_POSTS);

        return $val === self::FRONT_MODE_PAGE ? self::FRONT_MODE_PAGE : self::FRONT_MODE_POSTS;
    }

    public function frontPageId(): ?int
    {
        $val = $this->read('reading.front_page_id', null);

        return is_numeric($val) && (int) $val > 0 ? (int) $val : null;
    }

    public function postsPageId(): ?int
    {
        $val = $this->read('reading.posts_page_id', null);

        return is_numeric($val) && (int) $val > 0 ? (int) $val : null;
    }

    public function postsPerPage(): int
    {
        $val = $this->read('reading.posts_per_page', self::DEFAULT_POSTS_PER_PAGE);
        $num = is_numeric($val) ? (int) $val : self::DEFAULT_POSTS_PER_PAGE;

        return max(1, min(50, $num));
    }

    /**
     * Save permalink settings.
     *
     * @param array<string, mixed> $input
     * @return list<string> Validation errors (empty on success)
     */
    public function savePermalinks(array $input): array
    {
        $errors = [];

        $structure = is_string($input['post_structure'] ?? null) ? trim($input['post_structure']) : self::POST_BLOG_SLUG;
        if (!in_array($structure, self::ALLOWED_POST_STRUCTURES, true)) {
            $errors[] = 'Structure de permaliens d\'articles non prise en charge.';
        }

        $catBase = $this->validateBase($input['category_base'] ?? self::DEFAULT_CATEGORY_BASE, 'préfixe des catégories', $errors);
        $tagBase = $this->validateBase($input['tag_base'] ?? self::DEFAULT_TAG_BASE, 'préfixe des étiquettes', $errors);

        if ($catBase !== '' && $tagBase !== '' && $catBase === $tagBase) {
            $errors[] = 'Les préfixes des catégories et des étiquettes doivent être distincts.';
        }

        if ($errors !== []) {
            return $errors;
        }

        $this->write('permalinks.post_structure', $structure);
        $this->write('permalinks.category_base', $catBase);
        $this->write('permalinks.tag_base', $tagBase);

        return [];
    }

    /**
     * Save reading settings (`front_mode`, `front_page_id`, `posts_page_id`, `posts_per_page`).
     *
     * @param array<string, mixed> $input
     * @return list<string> Validation errors (empty on success)
     */
    public function saveReading(array $input): array
    {
        $errors = [];

        $mode = ($input['front_mode'] ?? self::FRONT_MODE_POSTS) === self::FRONT_MODE_PAGE
            ? self::FRONT_MODE_PAGE
            : self::FRONT_MODE_POSTS;

        $frontPageId = is_numeric($input['front_page_id'] ?? null) && (int) $input['front_page_id'] > 0
            ? (int) $input['front_page_id']
            : null;
        $postsPageId = is_numeric($input['posts_page_id'] ?? null) && (int) $input['posts_page_id'] > 0
            ? (int) $input['posts_page_id']
            : null;

        if ($mode === self::FRONT_MODE_PAGE && $frontPageId === null) {
            $errors[] = 'Veuillez sélectionner une page d\'accueil statique.';
        }
        if ($mode === self::FRONT_MODE_PAGE && $frontPageId !== null && $postsPageId !== null && $frontPageId === $postsPageId) {
            $errors[] = 'La page d\'accueil et la page des articles doivent être différentes.';
        }

        $perPage = is_numeric($input['posts_per_page'] ?? null) ? (int) $input['posts_per_page'] : self::DEFAULT_POSTS_PER_PAGE;
        if ($perPage < 1 || $perPage > 50) {
            $errors[] = 'Le nombre d\'articles par page doit être compris entre 1 et 50.';
        }

        if ($errors !== []) {
            return $errors;
        }

        $this->write('reading.front_mode', $mode);
        $this->write('reading.front_page_id', $mode === self::FRONT_MODE_PAGE ? $frontPageId : null);
        $this->write('reading.posts_page_id', $mode === self::FRONT_MODE_PAGE ? $postsPageId : null);
        $this->write('reading.posts_per_page', $perPage);

        return [];
    }

    public function setOverride(string $key, mixed $value): void
    {
        $this->overrides[$key] = $value;
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

    private function sanitizeBase(mixed $raw, string $fallback): string
    {
        if (!is_string($raw)) {
            return $fallback;
        }
        $clean = strtolower(trim($raw, " /\t\n\r"));
        if ($clean === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $clean) !== 1) {
            return $fallback;
        }

        return $clean;
    }

    /**
     * @param list<string> $errors
     */
    private function validateBase(mixed $raw, string $label, array &$errors): string
    {
        if (!is_string($raw)) {
            $errors[] = sprintf('Le %s est invalide.', $label);

            return '';
        }

        $clean = strtolower(trim($raw, " /\t\n\r"));
        if ($clean === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $clean) !== 1 || strlen($clean) > 64) {
            $errors[] = sprintf('Le %s doit contenir uniquement des lettres minuscules, chiffres et tirets.', $label);

            return '';
        }

        $forbidden = ['admin', 'uploads', 'assets', 'install', 'api', 'health', 'login', 'page', 'sitemap', 'robots'];
        if (in_array($clean, $forbidden, true)) {
            $errors[] = sprintf('Le %s « %s » est réservé par Prime CMS.', $label, $clean);

            return '';
        }

        return $clean;
    }
}
