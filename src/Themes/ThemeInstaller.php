<?php

declare(strict_types=1);

namespace Prime\Themes;

use Prime\Hooks\Hooks;
use Prime\Support\Version;
use Prime\Themes\Exception\ThemeInstallationException;
use ZipArchive;

/**
 * Secure ZIP theme archive installer (L1).
 *
 * Applies the exact same strict pre-extraction hardening as `PluginInstaller`
 * WITHOUT ever executing theme PHP code: file size limit, PK magic + MIME check,
 * Zip Slip / traversal / NUL / backslash / symlink guards, file count + uncompressed
 * size + compression ratio (ZIP bomb) limits, forbidden files (`.phar`, `.phtml`,
 * `.htaccess`, `.user.ini`), single root directory matching `theme.json` `slug`,
 * full `ThemeManifestValidator` verification, temporary staging in `storage/tmp/`,
 * and atomic swap with rollback backup.
 */
final class ThemeInstaller
{
    private const FORBIDDEN_EXTENSIONS = ['phar', 'phtml'];
    private const FORBIDDEN_BASENAMES = ['.htaccess', '.user.ini'];
    private const ALLOWED_MIMES = [
        'application/zip',
        'application/x-zip-compressed',
        'multipart/x-zip',
        'application/octet-stream',
    ];

    public function __construct(
        private readonly string $themesPath,
        private readonly string $tmpPath,
        private readonly ThemeManifestValidator $validator,
        private readonly ThemeManager $manager,
        private readonly Hooks $hooks,
        private readonly int $maxUploadMb = 20,
        private readonly int $maxZipFiles = 2000,
        private readonly int $maxUncompressedBytes = 104857600,
        private readonly float $maxCompressionRatio = 100.0,
    ) {
    }

    /**
     * Inspect a ZIP archive without extracting it and return its validated manifest.
     *
     * @return array{
     *     manifest: ThemeManifest,
     *     file_count: int,
     *     uncompressed_bytes: int,
     *     existing_version: ?string,
     *     is_upgrade: bool,
     *     is_downgrade_or_same: bool
     * }
     */
    public function inspectZip(string $zipPath): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ThemeInstallationException('L\'extension PHP "zip" (ZipArchive) est requise pour installer un thème.');
        }

        if (!is_file($zipPath) || !is_readable($zipPath)) {
            throw new ThemeInstallationException('L\'archive ZIP est introuvable ou illisible.');
        }

        $fileSize = filesize($zipPath);
        if ($fileSize === false || $fileSize === 0) {
            throw new ThemeInstallationException('L\'archive ZIP est vide.');
        }

        $maxBytes = $this->maxUploadMb * 1024 * 1024;
        if ($fileSize > $maxBytes) {
            throw new ThemeInstallationException(sprintf(
                'L\'archive dépasse la taille maximale autorisée (%d Mo).',
                $this->maxUploadMb,
            ));
        }

        $handle = fopen($zipPath, 'rb');
        $magic = $handle !== false ? fread($handle, 4) : false;
        if ($handle !== false) {
            fclose($handle);
        }
        if ($magic !== "PK\x03\x04") {
            throw new ThemeInstallationException('Le fichier fourni n\'est pas une archive ZIP valide (signature invalide).');
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $zipPath);
                finfo_close($finfo);
                if (is_string($mime) && !in_array($mime, self::ALLOWED_MIMES, true)) {
                    throw new ThemeInstallationException(sprintf(
                        'Type MIME non autorisé : "%s" (application/zip requis).',
                        $mime,
                    ));
                }
            }
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CHECKCONS) !== true) {
            throw new ThemeInstallationException('Impossible d\'ouvrir l\'archive ZIP (archive corrompue ou format non supporté).');
        }

        try {
            $inspection = $this->validateZipContents($zip);
        } finally {
            $zip->close();
        }

        $manifest = $inspection['manifest'];
        $existing = $this->manager->get($manifest->slug, fresh: true);
        $existingVersion = $existing?->manifest?->version;
        $isUpgrade = false;
        $isDowngradeOrSame = false;

        if ($existingVersion !== null && Version::isValid($existingVersion)) {
            $cmp = Version::compare($manifest->version, $existingVersion);
            $isUpgrade = $cmp > 0;
            $isDowngradeOrSame = $cmp <= 0;
        }

        return [
            'manifest' => $manifest,
            'file_count' => $inspection['file_count'],
            'uncompressed_bytes' => $inspection['uncompressed_bytes'],
            'existing_version' => $existingVersion,
            'is_upgrade' => $isUpgrade,
            'is_downgrade_or_same' => $isDowngradeOrSame,
        ];
    }

    /**
     * Validate, safely extract, and atomically install (or replace) a theme from a ZIP archive.
     *
     * @return array{manifest: ThemeManifest, replaced: bool, previous_version: ?string}
     */
    public function installFromZip(string $zipPath, bool $replaceExisting = false, bool $force = false): array
    {
        $info = $this->inspectZip($zipPath);
        $manifest = $info['manifest'];
        $slug = $manifest->slug;
        $targetDir = rtrim($this->themesPath, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . $slug;
        $existsOnDisk = is_dir($targetDir);
        $previousVersion = $info['existing_version'];

        if ($existsOnDisk) {
            if ($slug === ThemeManager::DEFAULT_THEME && !$force) {
                throw new ThemeInstallationException('Le thème par défaut « Aurora » ne peut pas être écrasé sans confirmation explicite.');
            }
            if (!$replaceExisting && !$force) {
                throw new ThemeInstallationException(
                    sprintf(
                        'Le thème « %s » (version %s) est déjà installé. Confirmez le remplacement pour installer la version %s.',
                        $manifest->name,
                        $previousVersion ?? 'inconnue',
                        $manifest->version,
                    ),
                    conflictSlug: $slug,
                    existingVersion: $previousVersion ?? '0.0.0',
                    incomingVersion: $manifest->version,
                    isDowngradeOrSame: $info['is_downgrade_or_same'],
                );
            }
        }

        if (!is_dir($this->tmpPath) && !@mkdir($this->tmpPath, 0755, true) && !is_dir($this->tmpPath)) {
            throw new ThemeInstallationException('Impossible de créer le dossier temporaire d\'extraction.');
        }

        $stagingRoot = rtrim($this->tmpPath, DIRECTORY_SEPARATOR . '/') . '/theme_install_' . bin2hex(random_bytes(8));
        $backupDir = null;

        try {
            $this->extractSafely($zipPath, $stagingRoot, $slug);
            $stagedThemeDir = $stagingRoot . DIRECTORY_SEPARATOR . $slug;

            if (!is_dir($this->themesPath) && !@mkdir($this->themesPath, 0755, true) && !is_dir($this->themesPath)) {
                throw new ThemeInstallationException('Impossible de créer le dossier content/themes.');
            }

            if ($existsOnDisk) {
                $backupDir = rtrim($this->tmpPath, DIRECTORY_SEPARATOR . '/') . '/theme_backup_' . $slug . '_' . bin2hex(random_bytes(6));
                if (!@rename($targetDir, $backupDir)) {
                    throw new ThemeInstallationException('Impossible de sauvegarder la version existante du thème.');
                }
            }

            if (!@rename($stagedThemeDir, $targetDir)) {
                if ($backupDir !== null && is_dir($backupDir)) {
                    @rename($backupDir, $targetDir);
                }
                throw new ThemeInstallationException('Impossible d\'installer le thème dans content/themes.');
            }

            if ($backupDir !== null && is_dir($backupDir)) {
                ThemeAssetPublisher::removeDirectory($backupDir);
            }
        } finally {
            ThemeAssetPublisher::removeDirectory($stagingRoot);
        }

        $this->manager->resetCache();
        $this->manager->publishAssets($slug);
        $this->hooks->emit('theme.installed', $slug, $manifest);

        return [
            'manifest' => $manifest,
            'replaced' => $existsOnDisk,
            'previous_version' => $previousVersion,
        ];
    }

    /**
     * @return array{manifest: ThemeManifest, file_count: int, uncompressed_bytes: int}
     */
    private function validateZipContents(ZipArchive $zip): array
    {
        $numFiles = $zip->numFiles;
        if ($numFiles === 0) {
            throw new ThemeInstallationException('L\'archive ZIP ne contient aucun fichier.');
        }
        if ($numFiles > $this->maxZipFiles) {
            throw new ThemeInstallationException(sprintf(
                'L\'archive contient trop de fichiers (%d, maximum %d).',
                $numFiles,
                $this->maxZipFiles,
            ));
        }

        $rootDirs = [];
        $totalUncompressed = 0;
        $totalCompressed = 0;
        $manifestIndex = null;
        $hasThemePhp = false;

        for ($i = 0; $i < $numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new ThemeInstallationException('Entrée ZIP illisible.');
            }

            $name = $stat['name'];

            if (str_contains($name, "\0") || str_contains($name, '\\')) {
                throw new ThemeInstallationException('Chemin dangereux détecté dans l\'archive ZIP (NUL ou antislash).');
            }
            if (str_starts_with($name, '/') || preg_match('/^[a-zA-Z]:/', $name) === 1) {
                throw new ThemeInstallationException('Chemin absolu interdit dans l\'archive ZIP.');
            }

            $segments = array_values(array_filter(explode('/', $name), static fn (string $s): bool => $s !== ''));
            if ($segments === []) {
                continue;
            }

            // Ignore macOS metadata folder
            if ($segments[0] === '__MACOSX') {
                continue;
            }

            foreach ($segments as $segment) {
                if ($segment === '.' || $segment === '..') {
                    throw new ThemeInstallationException('Tentative de traversée de répertoire (Zip Slip) bloquée.');
                }
            }

            // Detect POSIX symlink via external attributes
            $opsys = 0;
            $attr = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr)) {
                $unixMode = ($attr >> 16) & 0170000;
                if ($unixMode === 0120000) {
                    throw new ThemeInstallationException('Les liens symboliques sont interdits dans une archive de thème.');
                }
            }

            $rootDirs[$segments[0]] = true;

            $isDir = str_ends_with($name, '/');
            if (!$isDir) {
                $basename = strtolower($segments[count($segments) - 1]);
                if (in_array($basename, self::FORBIDDEN_BASENAMES, true)) {
                    throw new ThemeInstallationException(sprintf('Fichier interdit dans l\'archive : "%s".', $basename));
                }

                $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
                if (in_array($ext, self::FORBIDDEN_EXTENSIONS, true)) {
                    throw new ThemeInstallationException(sprintf('Extension interdite dans l\'archive : ".%s".', $ext));
                }

                $uncomp = (int) $stat['size'];
                $comp = (int) $stat['comp_size'];
                if ($uncomp < 0) {
                    throw new ThemeInstallationException('Taille décompressée invalide dans l\'archive ZIP.');
                }

                $totalUncompressed += $uncomp;
                $totalCompressed += max(1, $comp);

                if ($totalUncompressed > $this->maxUncompressedBytes) {
                    throw new ThemeInstallationException('L\'archive dépasse la taille décompressée maximale autorisée (protection bombe ZIP).');
                }
                if ($uncomp > 1048576 && ($uncomp / max(1, $comp)) > $this->maxCompressionRatio) {
                    throw new ThemeInstallationException('Taux de compression suspect détecté (protection bombe ZIP).');
                }

                if (count($segments) === 2 && $segments[1] === 'theme.json') {
                    $manifestIndex = $i;
                }
                if (count($segments) === 3 && $segments[1] === 'src' && $segments[2] === 'Theme.php') {
                    $hasThemePhp = true;
                }
            }
        }

        if ($totalUncompressed > 1048576 && ($totalUncompressed / max(1, $totalCompressed)) > $this->maxCompressionRatio) {
            throw new ThemeInstallationException('Taux de compression global suspect détecté (protection bombe ZIP).');
        }

        if (count($rootDirs) !== 1) {
            throw new ThemeInstallationException('L\'archive doit contenir un unique dossier racine portant le slug du thème.');
        }

        $rootSlug = (string) array_key_first($rootDirs);
        if (preg_match(ThemeManifestValidator::SLUG_PATTERN, $rootSlug) !== 1) {
            throw new ThemeInstallationException(sprintf('Le dossier racine "%s" n\'est pas un slug de thème valide.', $rootSlug));
        }

        if ($manifestIndex === null) {
            throw new ThemeInstallationException('Le fichier obligatoire "theme.json" est manquant à la racine du thème.');
        }

        if (!$hasThemePhp) {
            throw new ThemeInstallationException('Le fichier obligatoire "src/Theme.php" est manquant dans l\'archive.');
        }

        $manifestJson = $zip->getFromIndex($manifestIndex);
        if (!is_string($manifestJson)) {
            throw new ThemeInstallationException('Impossible de lire "theme.json" dans l\'archive.');
        }

        $validation = $this->validator->validateJson($manifestJson, $rootSlug);
        if (!$validation['valid'] || $validation['manifest'] === null) {
            throw new ThemeInstallationException(
                'Manifeste theme.json invalide : ' . implode(' ', $validation['errors']),
            );
        }

        return [
            'manifest' => $validation['manifest'],
            'file_count' => $numFiles,
            'uncompressed_bytes' => $totalUncompressed,
        ];
    }

    private function extractSafely(string $zipPath, string $stagingRoot, string $expectedSlug): void
    {
        if (!@mkdir($stagingRoot, 0755, true) && !is_dir($stagingRoot)) {
            throw new ThemeInstallationException('Impossible de créer le dossier de staging.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CHECKCONS) !== true) {
            throw new ThemeInstallationException('Impossible de réouvrir l\'archive ZIP pour extraction.');
        }

        $canonicalRoot = realpath($stagingRoot);
        if ($canonicalRoot === false) {
            $zip->close();
            throw new ThemeInstallationException('Chemin de staging invalide.');
        }

        try {
            $writtenBytes = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }
                $name = $stat['name'];
                $segments = array_values(array_filter(explode('/', $name), static fn (string $s): bool => $s !== ''));
                if ($segments === [] || $segments[0] === '__MACOSX') {
                    continue;
                }
                if ($segments[0] !== $expectedSlug) {
                    throw new ThemeInstallationException('Dossier inattendu lors de l\'extraction.');
                }

                $destPath = $canonicalRoot . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
                if (str_ends_with($name, '/')) {
                    if (!is_dir($destPath)) {
                        @mkdir($destPath, 0755, true);
                    }
                    continue;
                }

                $parentDir = dirname($destPath);
                if (!is_dir($parentDir)) {
                    @mkdir($parentDir, 0755, true);
                }

                $realParent = realpath($parentDir);
                if ($realParent === false || !str_starts_with($realParent, $canonicalRoot)) {
                    throw new ThemeInstallationException('Confinement du chemin d\'extraction violé.');
                }

                $stream = $zip->getStream($name);
                if ($stream === false) {
                    throw new ThemeInstallationException(sprintf('Impossible d\'extraire "%s".', $name));
                }

                $out = fopen($destPath, 'wb');
                if ($out === false) {
                    fclose($stream);
                    throw new ThemeInstallationException(sprintf('Impossible d\'écrire "%s".', $name));
                }

                try {
                    while (!feof($stream)) {
                        $chunk = fread($stream, 65536);
                        if ($chunk === false) {
                            break;
                        }
                        $writtenBytes += strlen($chunk);
                        if ($writtenBytes > $this->maxUncompressedBytes) {
                            throw new ThemeInstallationException('Limite de taille décompressée dépassée à l\'extraction.');
                        }
                        fwrite($out, $chunk);
                    }
                } finally {
                    fclose($stream);
                    fclose($out);
                }
            }
        } finally {
            $zip->close();
        }
    }
}
