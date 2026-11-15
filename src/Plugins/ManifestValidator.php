<?php

declare(strict_types=1);

namespace Prime\Plugins;

use JsonException;
use Prime\Admin\Menu\MenuItem;
use Prime\Support\Version;

/**
 * Strictly validates `plugin.json` manifests (required fields, SemVer, slug/folder match,
 * PSR-4 namespace/main consistency, requirements, dependencies) while collecting
 * non-fatal warnings for unknown fields.
 */
final class ManifestValidator
{
    public const SLUG_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';
    private const NAMESPACE_PATTERN = '/^[A-Z][A-Za-z0-9_]*(\\\\[A-Z][A-Za-z0-9_]*)*\\\\$/';
    private const CLASS_PATTERN = '/^[A-Z][A-Za-z0-9_]*(\\\\[A-Z][A-Za-z0-9_]*)+$/';

    private const KNOWN_KEYS = [
        'slug',
        'name',
        'description',
        'version',
        'author',
        'license',
        'namespace',
        'main',
        'requires',
        'dependencies',
        'icon',
        'accent',
        'settings_url',
    ];

    private const KNOWN_AUTHOR_KEYS = ['name', 'url'];
    private const KNOWN_REQUIRES_KEYS = ['prime', 'php', 'extensions'];
    private const ALLOWED_ICON_EXTENSIONS = ['svg', 'png', 'jpg', 'jpeg', 'webp', 'ico'];

    /**
     * Validate a `plugin.json` file on disk inside a plugin directory.
     *
     * @return array{manifest: ?PluginManifest, errors: list<string>, warnings: list<string>}
     */
    public function validateFile(string $manifestPath, ?string $expectedDirectorySlug = null): array
    {
        if (!is_file($manifestPath) || !is_readable($manifestPath)) {
            return [
                'manifest' => null,
                'errors' => ['Le fichier plugin.json est introuvable ou illisible.'],
                'warnings' => [],
            ];
        }

        $raw = file_get_contents($manifestPath);
        if ($raw === false) {
            return [
                'manifest' => null,
                'errors' => ['Impossible de lire plugin.json.'],
                'warnings' => [],
            ];
        }

        return $this->validateJson($raw, $expectedDirectorySlug);
    }

    /**
     * Validate raw JSON content of a `plugin.json` manifest.
     *
     * @return array{manifest: ?PluginManifest, errors: list<string>, warnings: list<string>}
     */
    public function validateJson(string $rawJson, ?string $expectedDirectorySlug = null): array
    {
        try {
            $decoded = json_decode($rawJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return [
                'manifest' => null,
                'errors' => [sprintf('JSON invalide dans plugin.json : %s', $exception->getMessage())],
                'warnings' => [],
            ];
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            return [
                'manifest' => null,
                'errors' => ['Le manifeste plugin.json doit contenir un objet JSON.'],
                'warnings' => [],
            ];
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        return $this->validateArray($data, hash('sha256', $rawJson), $expectedDirectorySlug);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{manifest: ?PluginManifest, errors: list<string>, warnings: list<string>}
     */
    public function validateArray(array $data, string $manifestHash, ?string $expectedDirectorySlug = null): array
    {
        $errors = [];
        $warnings = [];

        // Warn on any unknown top-level key
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::KNOWN_KEYS, true)) {
                $warnings[] = sprintf('Champ inconnu dans plugin.json : "%s".', $key);
            }
        }

        // 1. slug
        $slug = $this->requireNonEmptyString($data, 'slug', $errors);
        if ($slug !== null) {
            $len = strlen($slug);
            if ($len < 3 || $len > 64 || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
                $errors[] = sprintf(
                    'Le slug "%s" est invalide (3 à 64 caractères minuscules, chiffres et tirets uniquement).',
                    $slug,
                );
            } elseif ($expectedDirectorySlug !== null && $slug !== $expectedDirectorySlug) {
                $errors[] = sprintf(
                    'Le slug "%s" ne correspond pas au nom du dossier "%s".',
                    $slug,
                    $expectedDirectorySlug,
                );
            }
        }

        // 2. name & description
        $name = $this->requireNonEmptyString($data, 'name', $errors);
        if ($name !== null && mb_strlen($name) > 100) {
            $errors[] = 'Le nom du plugin ne doit pas dépasser 100 caractères.';
        }

        $description = $this->requireNonEmptyString($data, 'description', $errors);
        if ($description !== null && mb_strlen($description) > 500) {
            $errors[] = 'La description du plugin ne doit pas dépasser 500 caractères.';
        }

        // 3. version (SemVer 2.0)
        $version = $this->requireNonEmptyString($data, 'version', $errors);
        if ($version !== null && !Version::isValid($version)) {
            $errors[] = sprintf('La version "%s" n\'est pas un numéro SemVer 2.0 valide.', $version);
        }

        // 4. author ({ name, url? })
        $authorName = null;
        $authorUrl = null;
        if (!array_key_exists('author', $data)) {
            $errors[] = 'Le champ requis "author" est manquant.';
        } elseif (!is_array($data['author']) || array_is_list($data['author'])) {
            $errors[] = 'Le champ "author" doit être un objet JSON contenant au minimum "name".';
        } else {
            /** @var array<string, mixed> $author */
            $author = $data['author'];
            foreach (array_keys($author) as $aKey) {
                if (!in_array($aKey, self::KNOWN_AUTHOR_KEYS, true)) {
                    $warnings[] = sprintf('Champ inconnu dans "author" : "%s".', $aKey);
                }
            }
            if (!isset($author['name']) || !is_string($author['name']) || trim($author['name']) === '') {
                $errors[] = 'Le champ requis "author.name" est manquant ou vide.';
            } else {
                $authorName = trim($author['name']);
            }
            if (array_key_exists('url', $author) && $author['url'] !== null && $author['url'] !== '') {
                if (!is_string($author['url']) || !self::isSafeHttpUrl($author['url'])) {
                    $errors[] = 'Le champ "author.url" doit être une URL http:// ou https:// valide.';
                } else {
                    $authorUrl = trim($author['url']);
                }
            }
        }

        // 5. license (optional)
        $license = 'proprietary';
        if (array_key_exists('license', $data) && $data['license'] !== null) {
            if (!is_string($data['license']) || trim($data['license']) === '') {
                $errors[] = 'Le champ "license" doit être une chaîne non vide.';
            } else {
                $license = trim($data['license']);
            }
        }

        // 6. namespace & main
        $namespace = $this->requireNonEmptyString($data, 'namespace', $errors);
        if ($namespace !== null) {
            if (preg_match(self::NAMESPACE_PATTERN, $namespace) !== 1) {
                $errors[] = sprintf(
                    'Le namespace "%s" est invalide (ex. "HelloPrime\\").',
                    $namespace,
                );
            } elseif (str_starts_with($namespace, 'Prime\\')) {
                $errors[] = 'Un plugin ne peut pas utiliser le namespace réservé "Prime\\".';
            }
        }

        $main = $this->requireNonEmptyString($data, 'main', $errors);
        if ($main !== null) {
            $cleanMain = ltrim($main, '\\');
            if (preg_match(self::CLASS_PATTERN, $cleanMain) !== 1) {
                $errors[] = sprintf('La classe principale "%s" n\'est pas un nom de classe PHP valide.', $main);
            } elseif ($namespace !== null && !str_starts_with($cleanMain, $namespace)) {
                $errors[] = sprintf(
                    'La classe principale "%s" doit appartenir au namespace "%s".',
                    $cleanMain,
                    $namespace,
                );
            }
            $main = $cleanMain;
        }

        // 7. requires (optional: prime, php, extensions)
        $requiresPrime = null;
        $requiresPhp = null;
        $requiredExtensions = [];
        if (array_key_exists('requires', $data) && $data['requires'] !== null) {
            if (!is_array($data['requires']) || array_is_list($data['requires'])) {
                $errors[] = 'Le champ "requires" doit être un objet JSON.';
            } else {
                /** @var array<string, mixed> $requires */
                $requires = $data['requires'];
                foreach (array_keys($requires) as $rKey) {
                    if (!in_array($rKey, self::KNOWN_REQUIRES_KEYS, true)) {
                        $warnings[] = sprintf('Champ inconnu dans "requires" : "%s".', $rKey);
                    }
                }
                if (isset($requires['prime'])) {
                    if (!is_string($requires['prime']) || !Version::isValidConstraint($requires['prime'])) {
                        $errors[] = 'La contrainte "requires.prime" est invalide.';
                    } else {
                        $requiresPrime = trim($requires['prime']);
                    }
                }
                if (isset($requires['php'])) {
                    if (!is_string($requires['php']) || !Version::isValidConstraint($requires['php'])) {
                        $errors[] = 'La contrainte "requires.php" est invalide.';
                    } else {
                        $requiresPhp = trim($requires['php']);
                    }
                }
                if (array_key_exists('extensions', $requires) && $requires['extensions'] !== null) {
                    if (!is_array($requires['extensions']) || !array_is_list($requires['extensions'])) {
                        $errors[] = 'Le champ "requires.extensions" doit être une liste de noms d\'extensions PHP.';
                    } else {
                        foreach ($requires['extensions'] as $ext) {
                            if (!is_string($ext) || preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,40}$/', $ext) !== 1) {
                                $errors[] = sprintf('Nom d\'extension PHP invalide dans "requires.extensions" : "%s".', is_scalar($ext) ? (string) $ext : gettype($ext));
                            } else {
                                $requiredExtensions[] = strtolower($ext);
                            }
                        }
                    }
                }
            }
        }

        // 8. dependencies (optional: map<slug, constraint>)
        $dependencies = [];
        if (array_key_exists('dependencies', $data) && $data['dependencies'] !== null) {
            if (!is_array($data['dependencies']) || (count($data['dependencies']) > 0 && array_is_list($data['dependencies']))) {
                $errors[] = 'Le champ "dependencies" doit être un objet JSON associant slug => contrainte SemVer.';
            } else {
                foreach ($data['dependencies'] as $depSlug => $depConstraint) {
                    if (!is_string($depSlug) || preg_match(self::SLUG_PATTERN, $depSlug) !== 1 || strlen($depSlug) < 3 || strlen($depSlug) > 64) {
                        $errors[] = sprintf('Slug de dépendance invalide : "%s".', (string) $depSlug);
                        continue;
                    }
                    if ($slug !== null && $depSlug === $slug) {
                        $errors[] = sprintf('Le plugin "%s" ne peut pas dépendre de lui-même.', $slug);
                        continue;
                    }
                    if (!is_string($depConstraint) || !Version::isValidConstraint($depConstraint)) {
                        $errors[] = sprintf('Contrainte SemVer invalide pour la dépendance "%s".', $depSlug);
                        continue;
                    }
                    $dependencies[$depSlug] = trim($depConstraint);
                }
            }
        }

        // 9. icon (optional relative path inside plugin)
        $icon = null;
        if (array_key_exists('icon', $data) && $data['icon'] !== null && $data['icon'] !== '') {
            if (!is_string($data['icon'])) {
                $errors[] = 'Le champ "icon" doit être un chemin relatif vers un fichier image/SVG.';
            } else {
                $cleanIcon = ltrim(str_replace('\\', '/', trim($data['icon'])), './');
                $ext = strtolower(pathinfo($cleanIcon, PATHINFO_EXTENSION));
                if (
                    $cleanIcon === ''
                    || str_starts_with($data['icon'], '/')
                    || str_contains($cleanIcon, '..')
                    || str_contains($cleanIcon, "\0")
                    || !in_array($ext, self::ALLOWED_ICON_EXTENSIONS, true)
                ) {
                    $errors[] = sprintf('Le chemin d\'icône "%s" est invalide ou non autorisé.', $data['icon']);
                } else {
                    $icon = $cleanIcon;
                }
            }
        }

        // 10. accent (optional, validated against design system tokens)
        $accent = 'violet';
        if (array_key_exists('accent', $data) && $data['accent'] !== null && $data['accent'] !== '') {
            if (!is_string($data['accent']) || !in_array($data['accent'], MenuItem::ALLOWED_ACCENTS, true)) {
                $errors[] = sprintf(
                    'La couleur d\'accent "%s" est invalide (autorisées : %s).',
                    is_scalar($data['accent']) ? (string) $data['accent'] : gettype($data['accent']),
                    implode(', ', MenuItem::ALLOWED_ACCENTS),
                );
            } else {
                $accent = $data['accent'];
            }
        }

        // 11. settings_url (optional relative `/admin/...` route)
        $settingsUrl = null;
        if (array_key_exists('settings_url', $data) && $data['settings_url'] !== null && $data['settings_url'] !== '') {
            if (!is_string($data['settings_url']) || !str_starts_with($data['settings_url'], '/admin/') || str_contains($data['settings_url'], '..')) {
                $errors[] = 'Le champ "settings_url" doit être un chemin relatif commençant par "/admin/".';
            } else {
                $settingsUrl = trim($data['settings_url']);
            }
        }

        if ($errors !== [] || $slug === null || $name === null || $description === null || $version === null || $authorName === null || $namespace === null || $main === null) {
            return [
                'manifest' => null,
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }

        return [
            'manifest' => new PluginManifest(
                slug: $slug,
                name: $name,
                description: $description,
                version: $version,
                authorName: $authorName,
                authorUrl: $authorUrl,
                license: $license,
                namespace: $namespace,
                main: $main,
                requiresPrime: $requiresPrime,
                requiresPhp: $requiresPhp,
                requiredExtensions: array_values(array_unique($requiredExtensions)),
                dependencies: $dependencies,
                icon: $icon,
                accent: $accent,
                settingsUrl: $settingsUrl,
                hash: $manifestHash,
            ),
            'errors' => [],
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $errors
     */
    private function requireNonEmptyString(array $data, string $key, array &$errors): ?string
    {
        if (!array_key_exists($key, $data)) {
            $errors[] = sprintf('Le champ requis "%s" est manquant.', $key);

            return null;
        }

        if (!is_string($data[$key]) || trim($data[$key]) === '') {
            $errors[] = sprintf('Le champ requis "%s" doit être une chaîne non vide.', $key);

            return null;
        }

        return trim($data[$key]);
    }

    private static function isSafeHttpUrl(string $url): bool
    {
        $trimmed = trim($url);
        if (filter_var($trimmed, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($trimmed, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }
}
