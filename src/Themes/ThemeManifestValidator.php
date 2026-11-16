<?php

declare(strict_types=1);

namespace Prime\Themes;

use Prime\Support\Exception\InvalidVersionException;
use Prime\Support\Version;

/**
 * Validates and normalizes `content/themes/{slug}/theme.json` manifests with
 * clear French error messages and strict schema enforcement.
 */
final class ThemeManifestValidator
{
    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    public const KEY_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';
    public const ALLOWED_OPTION_TYPES = [
        'color',
        'text',
        'textarea',
        'toggle',
        'select',
        'range',
        'media',
        'font',
    ];

    /**
     * Parse and validate a `theme.json` file on disk.
     *
     * @return array{valid: bool, manifest: ?ThemeManifest, errors: list<string>}
     */
    public function validateFile(string $manifestPath, ?string $expectedSlug = null): array
    {
        if (!is_file($manifestPath) || !is_readable($manifestPath)) {
            return [
                'valid' => false,
                'manifest' => null,
                'errors' => ['Le fichier theme.json est introuvable ou illisible.'],
            ];
        }

        $raw = file_get_contents($manifestPath);
        if (!is_string($raw) || trim($raw) === '') {
            return [
                'valid' => false,
                'manifest' => null,
                'errors' => ['Le fichier theme.json est vide.'],
            ];
        }

        return $this->validateJson($raw, $expectedSlug);
    }

    /**
     * Parse and validate a raw JSON manifest string.
     *
     * @return array{valid: bool, manifest: ?ThemeManifest, errors: list<string>}
     */
    public function validateJson(string $json, ?string $expectedSlug = null): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [
                'valid' => false,
                'manifest' => null,
                'errors' => ['Le fichier theme.json contient un JSON invalide : ' . json_last_error_msg() . '.'],
            ];
        }

        /** @var array<string, mixed> $decoded */
        return $this->validateArray($decoded, $expectedSlug);
    }

    /**
     * Validate a decoded `theme.json` array.
     *
     * @param array<string, mixed> $data
     * @return array{valid: bool, manifest: ?ThemeManifest, errors: list<string>}
     */
    public function validateArray(array $data, ?string $expectedSlug = null): array
    {
        $errors = [];

        // 1. Required fields: slug, name, version
        $slug = is_string($data['slug'] ?? null) ? trim($data['slug']) : '';
        if ($slug === '') {
            $errors[] = 'Le champ "slug" est obligatoire.';
        } elseif (strlen($slug) > 64 || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            $errors[] = sprintf(
                'Le slug "%s" est invalide (minuscules, chiffres et tirets uniquement, 64 caractères max).',
                $slug,
            );
        } elseif ($expectedSlug !== null && $slug !== $expectedSlug) {
            $errors[] = sprintf(
                'Le slug "%s" du manifeste ne correspond pas au dossier du thème "%s".',
                $slug,
                $expectedSlug,
            );
        }

        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
        if ($name === '') {
            $errors[] = 'Le champ "name" est obligatoire.';
        } elseif (mb_strlen($name, 'UTF-8') > 120) {
            $errors[] = 'Le nom du thème ne peut pas dépasser 120 caractères.';
        }

        $version = is_string($data['version'] ?? null) ? trim($data['version']) : '';
        if ($version === '') {
            $errors[] = 'Le champ "version" est obligatoire.';
        } elseif (!Version::isValid($version)) {
            $errors[] = sprintf('La version "%s" doit respecter le format SemVer (ex. 1.0.0).', $version);
        }

        // 2. Description & Author
        $description = is_string($data['description'] ?? null) ? trim($data['description']) : '';
        $author = '';
        $authorUrl = null;
        if (is_string($data['author'] ?? null)) {
            $author = trim($data['author']);
        } elseif (is_array($data['author'] ?? null)) {
            $author = is_string($data['author']['name'] ?? null) ? trim($data['author']['name']) : '';
            $rawUrl = is_string($data['author']['url'] ?? null) ? trim($data['author']['url']) : '';
            if ($rawUrl !== '' && preg_match('#^https?://#i', $rawUrl) === 1) {
                $authorUrl = $rawUrl;
            }
        }

        // 3. Requires (string SemVer constraint on Prime CMS OR object { prime, php })
        $requiresPrime = null;
        $requiresPhp = null;
        if (isset($data['requires'])) {
            if (is_string($data['requires'])) {
                $constraint = trim($data['requires']);
                if ($constraint !== '') {
                    $requiresPrime = $constraint;
                    $this->validateConstraint($constraint, 'requires', $errors);
                }
            } elseif (is_array($data['requires'])) {
                if (isset($data['requires']['prime'])) {
                    if (!is_string($data['requires']['prime'])) {
                        $errors[] = 'Le champ "requires.prime" doit être une contrainte SemVer textuelle.';
                    } else {
                        $requiresPrime = trim($data['requires']['prime']);
                        if ($requiresPrime !== '') {
                            $this->validateConstraint($requiresPrime, 'requires.prime', $errors);
                        } else {
                            $requiresPrime = null;
                        }
                    }
                }
                if (isset($data['requires']['php'])) {
                    if (!is_string($data['requires']['php'])) {
                        $errors[] = 'Le champ "requires.php" doit être une contrainte SemVer textuelle.';
                    } else {
                        $requiresPhp = trim($data['requires']['php']);
                        if ($requiresPhp !== '') {
                            $this->validateConstraint($requiresPhp, 'requires.php', $errors);
                        } else {
                            $requiresPhp = null;
                        }
                    }
                }
            } else {
                $errors[] = 'Le champ "requires" doit être une chaîne SemVer ou un objet { prime, php }.';
            }
        }

        // 4. Parent theme (optional single-level inheritance)
        $parent = null;
        if (isset($data['parent']) && $data['parent'] !== null && $data['parent'] !== '') {
            if (!is_string($data['parent'])) {
                $errors[] = 'Le champ "parent" doit être le slug d\'un thème parent.';
            } else {
                $parentSlug = trim($data['parent']);
                if (preg_match(self::SLUG_PATTERN, $parentSlug) !== 1) {
                    $errors[] = sprintf('Le slug du thème parent "%s" est invalide.', $parentSlug);
                } elseif ($slug !== '' && $parentSlug === $slug) {
                    $errors[] = 'Un thème ne peut pas être son propre thème parent.';
                } else {
                    $parent = $parentSlug;
                }
            }
        }

        // 5. Namespace & main class
        $defaultNs = $slug !== '' ? self::slugToNamespace($slug) : 'Theme';
        $namespace = is_string($data['namespace'] ?? null) && trim($data['namespace']) !== ''
            ? trim($data['namespace'], "\\ \t\n\r")
            : $defaultNs;

        if ($namespace === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $namespace) !== 1) {
            $errors[] = sprintf('Le namespace PHP "%s" est invalide.', $namespace);
        } elseif (str_starts_with(strtolower($namespace), 'prime\\') || strtolower($namespace) === 'prime') {
            $errors[] = 'Le namespace d\'un thème ne peut pas utiliser le préfixe réservé "Prime".';
        }

        $main = is_string($data['main'] ?? null) && trim($data['main']) !== ''
            ? ltrim(trim($data['main']), '\\')
            : $namespace . '\\Theme';

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $main) !== 1) {
            $errors[] = sprintf('La classe principale "%s" est invalide.', $main);
        }

        // 6. Menus: slug => label
        $menus = [];
        if (isset($data['menus'])) {
            if (!is_array($data['menus'])) {
                $errors[] = 'Le champ "menus" doit être un objet associatif (slug => libellé).';
            } else {
                foreach ($data['menus'] as $locSlug => $label) {
                    if (!is_string($locSlug) || preg_match(self::KEY_PATTERN, $locSlug) !== 1) {
                        $errors[] = sprintf('Emplacement de menu invalide : "%s".', (string) $locSlug);
                        continue;
                    }
                    if (!is_string($label) || trim($label) === '') {
                        $errors[] = sprintf('Le libellé de l\'emplacement de menu "%s" est requis.', $locSlug);
                        continue;
                    }
                    $menus[$locSlug] = trim($label);
                }
            }
        }

        // 7. Zones: slug => { label, description } | label
        $zones = [];
        if (isset($data['zones'])) {
            if (!is_array($data['zones'])) {
                $errors[] = 'Le champ "zones" doit être un objet associatif.';
            } else {
                foreach ($data['zones'] as $zoneSlug => $zoneDef) {
                    if (!is_string($zoneSlug) || preg_match(self::KEY_PATTERN, $zoneSlug) !== 1) {
                        $errors[] = sprintf('Identifiant de zone invalide : "%s".', (string) $zoneSlug);
                        continue;
                    }
                    if (is_string($zoneDef) && trim($zoneDef) !== '') {
                        $zones[$zoneSlug] = [
                            'label' => trim($zoneDef),
                            'description' => '',
                        ];
                    } elseif (is_array($zoneDef) && is_string($zoneDef['label'] ?? null) && trim($zoneDef['label']) !== '') {
                        $zones[$zoneSlug] = [
                            'label' => trim($zoneDef['label']),
                            'description' => is_string($zoneDef['description'] ?? null) ? trim($zoneDef['description']) : '',
                        ];
                    } else {
                        $errors[] = sprintf('La zone "%s" doit définir un libellé non vide.', $zoneSlug);
                    }
                }
            }
        }

        // 8. Templates: content_type => [ template_slug => label ]
        $templates = [];
        if (isset($data['templates'])) {
            if (!is_array($data['templates'])) {
                $errors[] = 'Le champ "templates" doit être un objet organisé par type de contenu.';
            } else {
                foreach ($data['templates'] as $contentType => $typeTemplates) {
                    if (!is_string($contentType) || preg_match(self::KEY_PATTERN, $contentType) !== 1 || !is_array($typeTemplates)) {
                        $errors[] = sprintf('Déclaration de modèles invalide pour le type "%s".', (string) $contentType);
                        continue;
                    }
                    $normalized = [];
                    foreach ($typeTemplates as $tplSlug => $tplLabel) {
                        if (!is_string($tplSlug) || preg_match(self::SLUG_PATTERN, $tplSlug) !== 1) {
                            $errors[] = sprintf('Slug de modèle invalide "%s" pour le type "%s".', (string) $tplSlug, $contentType);
                            continue;
                        }
                        if (!is_string($tplLabel) || trim($tplLabel) === '') {
                            $errors[] = sprintf('Libellé manquant pour le modèle "%s".', $tplSlug);
                            continue;
                        }
                        $normalized[$tplSlug] = trim($tplLabel);
                    }
                    if ($normalized !== []) {
                        $templates[$contentType] = $normalized;
                    }
                }
            }
        }

        // 9. Image sizes: name => { width, height, crop }
        $imageSizes = [];
        if (isset($data['image_sizes'])) {
            if (!is_array($data['image_sizes'])) {
                $errors[] = 'Le champ "image_sizes" doit être un objet.';
            } else {
                foreach ($data['image_sizes'] as $sizeName => $sizeDef) {
                    if (!is_string($sizeName) || preg_match(self::KEY_PATTERN, $sizeName) !== 1 || !is_array($sizeDef)) {
                        $errors[] = sprintf('Taille d\'image invalide : "%s".', (string) $sizeName);
                        continue;
                    }
                    $w = isset($sizeDef['width']) && is_numeric($sizeDef['width']) ? (int) $sizeDef['width'] : 0;
                    $h = isset($sizeDef['height']) && is_numeric($sizeDef['height']) ? (int) $sizeDef['height'] : 0;
                    if ($w < 16 || $w > 4000 || $h < 0 || $h > 4000) {
                        $errors[] = sprintf('Dimensions invalides pour la taille d\'image "%s".', $sizeName);
                        continue;
                    }
                    $imageSizes[$sizeName] = [
                        'width' => $w,
                        'height' => $h,
                        'crop' => (bool) ($sizeDef['crop'] ?? false),
                    ];
                }
            }
        }

        // 10. Options schema: sections + fields
        $options = $this->validateOptions($data['options'] ?? null, $errors);

        if ($errors !== []) {
            return [
                'valid' => false,
                'manifest' => null,
                'errors' => $errors,
            ];
        }

        return [
            'valid' => true,
            'manifest' => new ThemeManifest(
                slug: $slug,
                name: $name,
                version: $version,
                description: $description,
                author: $author,
                authorUrl: $authorUrl,
                requires: [
                    'prime' => $requiresPrime,
                    'php' => $requiresPhp,
                ],
                parent: $parent,
                namespace: $namespace,
                main: $main,
                menus: $menus,
                zones: $zones,
                templates: $templates,
                imageSizes: $imageSizes,
                options: $options,
            ),
            'errors' => [],
        ];
    }

    /**
     * Check whether a validated manifest is compatible with the running Prime CMS & PHP versions.
     *
     * @return list<string> Empty list when compatible
     */
    public function checkCompatibility(ThemeManifest $manifest, string $primeVersion, string $phpVersion): array
    {
        $issues = [];

        $primeConstraint = $manifest->requires['prime'];
        if ($primeConstraint !== null && $primeConstraint !== '') {
            try {
                if (!Version::satisfies($primeVersion, $primeConstraint)) {
                    $issues[] = sprintf(
                        'Requiert Prime CMS %s (version actuelle : %s).',
                        $primeConstraint,
                        $primeVersion,
                    );
                }
            } catch (InvalidVersionException $e) {
                $issues[] = $e->getMessage();
            }
        }

        $phpConstraint = $manifest->requires['php'];
        if ($phpConstraint !== null && $phpConstraint !== '') {
            try {
                $normalizedPhp = Version::normalizeRuntimeVersion($phpVersion);
                if (!Version::satisfies($normalizedPhp, $phpConstraint)) {
                    $issues[] = sprintf(
                        'Requiert PHP %s (version actuelle : %s).',
                        $phpConstraint,
                        $normalizedPhp,
                    );
                }
            } catch (InvalidVersionException $e) {
                $issues[] = $e->getMessage();
            }
        }

        return $issues;
    }

    public static function slugToNamespace(string $slug): string
    {
        $parts = array_filter(explode('-', $slug), static fn (string $p): bool => $p !== '');
        $pascal = implode('', array_map(static fn (string $p): string => ucfirst(strtolower($p)), $parts));

        if ($pascal === '' || ctype_digit($pascal[0])) {
            $pascal = 'Theme' . $pascal;
        }

        return $pascal;
    }

    /**
     * @param list<string> $errors
     */
    private function validateConstraint(string $constraint, string $field, array &$errors): void
    {
        try {
            Version::satisfies('1.0.0', $constraint);
        } catch (InvalidVersionException) {
            $errors[] = sprintf('Contrainte SemVer invalide dans "%s" : "%s".', $field, $constraint);
        }
    }

    /**
     * @param list<string> $errors
     * @return array{
     *     sections: array<string, array{label: string, description: string}>,
     *     fields: array<string, array<string, mixed>>
     * }
     */
    private function validateOptions(mixed $rawOptions, array &$errors): array
    {
        $sections = [];
        $fields = [];

        if ($rawOptions === null) {
            return ['sections' => $sections, 'fields' => $fields];
        }

        if (!is_array($rawOptions)) {
            $errors[] = 'Le champ "options" doit être un objet contenant "sections" et "fields".';

            return ['sections' => $sections, 'fields' => $fields];
        }

        if (isset($rawOptions['sections'])) {
            if (!is_array($rawOptions['sections'])) {
                $errors[] = 'Le champ "options.sections" doit être un objet.';
            } else {
                foreach ($rawOptions['sections'] as $secKey => $secDef) {
                    if (!is_string($secKey) || preg_match(self::KEY_PATTERN, $secKey) !== 1) {
                        $errors[] = sprintf('Identifiant de section d\'option invalide : "%s".', (string) $secKey);
                        continue;
                    }
                    if (is_string($secDef) && trim($secDef) !== '') {
                        $sections[$secKey] = ['label' => trim($secDef), 'description' => ''];
                    } elseif (is_array($secDef) && is_string($secDef['label'] ?? null) && trim($secDef['label']) !== '') {
                        $sections[$secKey] = [
                            'label' => trim($secDef['label']),
                            'description' => is_string($secDef['description'] ?? null) ? trim($secDef['description']) : '',
                        ];
                    } else {
                        $errors[] = sprintf('La section d\'option "%s" doit définir un libellé.', $secKey);
                    }
                }
            }
        }

        if (isset($rawOptions['fields'])) {
            if (!is_array($rawOptions['fields'])) {
                $errors[] = 'Le champ "options.fields" doit être un objet.';
            } else {
                foreach ($rawOptions['fields'] as $fieldKey => $fieldDef) {
                    if (!is_string($fieldKey) || preg_match(self::KEY_PATTERN, $fieldKey) !== 1 || !is_array($fieldDef)) {
                        $errors[] = sprintf('Définition de champ d\'option invalide : "%s".', (string) $fieldKey);
                        continue;
                    }

                    $type = is_string($fieldDef['type'] ?? null) ? trim($fieldDef['type']) : '';
                    if (!in_array($type, self::ALLOWED_OPTION_TYPES, true)) {
                        $errors[] = sprintf(
                            'Type d\'option non pris en charge "%s" pour le champ "%s".',
                            $type,
                            $fieldKey,
                        );
                        continue;
                    }

                    $label = is_string($fieldDef['label'] ?? null) ? trim($fieldDef['label']) : '';
                    if ($label === '') {
                        $errors[] = sprintf('Le champ d\'option "%s" doit définir un libellé.', $fieldKey);
                        continue;
                    }

                    $section = is_string($fieldDef['section'] ?? null) ? trim($fieldDef['section']) : 'general';
                    if ($sections !== [] && !isset($sections[$section])) {
                        $sections[$section] = ['label' => ucfirst($section), 'description' => ''];
                    }

                    if (in_array($type, ['select', 'font'], true)) {
                        $choices = $fieldDef['choices'] ?? null;
                        if (!is_array($choices) || $choices === []) {
                            $errors[] = sprintf('Le champ d\'option "%s" (%s) doit déclarer des choix ("choices").', $fieldKey, $type);
                            continue;
                        }
                    }

                    if ($type === 'range') {
                        if (!isset($fieldDef['min'], $fieldDef['max']) || !is_numeric($fieldDef['min']) || !is_numeric($fieldDef['max'])) {
                            $errors[] = sprintf('Le champ range "%s" doit définir "min" et "max".', $fieldKey);
                            continue;
                        }
                        if ((float) $fieldDef['min'] >= (float) $fieldDef['max']) {
                            $errors[] = sprintf('Le champ range "%s" doit avoir min < max.', $fieldKey);
                            continue;
                        }
                    }

                    $cssVar = is_string($fieldDef['css_var'] ?? null) ? trim($fieldDef['css_var']) : null;
                    if ($cssVar !== null && $cssVar !== '' && preg_match('/^--[a-zA-Z0-9_-]{1,64}$/', $cssVar) !== 1) {
                        $errors[] = sprintf('Variable CSS invalide "%s" pour l\'option "%s".', $cssVar, $fieldKey);
                        continue;
                    }

                    /** @var array<string, mixed> $normalizedField */
                    $normalizedField = [
                        'section' => $section,
                        'type' => $type,
                        'label' => $label,
                        'description' => is_string($fieldDef['description'] ?? null) ? trim($fieldDef['description']) : '',
                        'default' => $fieldDef['default'] ?? null,
                        'css_var' => $cssVar !== '' ? $cssVar : null,
                        'css_unit' => is_string($fieldDef['css_unit'] ?? null) ? trim($fieldDef['css_unit']) : '',
                        'transport' => ($fieldDef['transport'] ?? null) === 'postMessage' ? 'postMessage' : 'refresh',
                    ];

                    if (isset($fieldDef['choices']) && is_array($fieldDef['choices'])) {
                        $normalizedField['choices'] = $fieldDef['choices'];
                    }
                    if (isset($fieldDef['css_map']) && is_array($fieldDef['css_map'])) {
                        $normalizedField['css_map'] = $fieldDef['css_map'];
                    }
                    if (isset($fieldDef['min']) && is_numeric($fieldDef['min'])) {
                        $normalizedField['min'] = (float) $fieldDef['min'];
                    }
                    if (isset($fieldDef['max']) && is_numeric($fieldDef['max'])) {
                        $normalizedField['max'] = (float) $fieldDef['max'];
                    }
                    if (isset($fieldDef['step']) && is_numeric($fieldDef['step']) && (float) $fieldDef['step'] > 0) {
                        $normalizedField['step'] = (float) $fieldDef['step'];
                    }
                    if (isset($fieldDef['placeholder']) && is_string($fieldDef['placeholder'])) {
                        $normalizedField['placeholder'] = $fieldDef['placeholder'];
                    }

                    $fields[$fieldKey] = $normalizedField;
                }
            }
        }

        return ['sections' => $sections, 'fields' => $fields];
    }
}
