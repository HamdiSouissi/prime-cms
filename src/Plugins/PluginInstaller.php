<?php

declare(strict_types=1);

namespace Prime\Plugins;

use Prime\Hooks\Hooks;
use Prime\Plugins\Exception\PluginInstallationException;
use Prime\Support\Version;
use ZipArchive;

/**
 * Secure ZIP plugin archive installer (L7).
 *
 * Performs strict pre-extraction validation WITHOUT ever executing plugin PHP code:
 * size limit, MIME + PK signature check, Zip Slip / traversal / NUL / backslash /
 * symlink guards, file count & uncompressed size & ZIP bomb ratio limits,
 * forbidden filenames (`.phar`, `.phtml`, `.htaccess`, `.user.ini`), single root folder
 * matching `plugin.json`'s `slug`, full manifest validation, temporary extraction in
 * `storage/tmp/`, and atomic swap with rollback backup.
 */
final class PluginInstaller
{
    private const FORBIDDEN_EXTENSIONS = ['phar', 'phtml'];
    private const FORBIDDEN_BASENAMES = ['.htaccess', '.user.ini'];
    private const ALLOWED_MIMES = [
        'application/zip',
        'application/x-zip-compressed',
        'multipart/x-zip',
        'application/octet-stream', // accepted ONLY when PK\x03\x04 magic bytes + ZipArchive succeed
    ];

    public function __construct(
        private readonly string $pluginsPath,
        private readonly string $tmpPath,
        private readonly ManifestValidator $validator,
        private readonly PluginRepository $repository,
        private readonly PluginManager $manager,
        private readonly Hooks $hooks,
        private readonly int $maxUploadMb = 20,
        private readonly int $maxZipFiles = 2000,
        private readonly int $maxUncompressedBytes = 104857600,
        private readonly float $maxCompressionRatio = 100.0,
    ) {
    }

    /**
     * Inspect a ZIP archive without extracting it and return its validated manifest
     * and any replacement conflict metadata.
     *
     * @return array{
     *     manifest: PluginManifest,
     *     warnings: list<string>,
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
            throw new PluginInstallationException('L\'extension PHP "zip" (ZipArchive) est requise pour installer un plugin.');
        }

        if (!is_file($zipPath) || !is_readable($zipPath)) {
            throw new PluginInstallationException('L\'archive ZIP est introuvable ou illisible.');
        }

        $fileSize = filesize($zipPath);
        if ($fileSize === false || $fileSize === 0) {
            throw new PluginInstallationException('L\'archive ZIP est vide.');
        }

        $maxBytes = $this->maxUploadMb * 1024 * 1024;
        if ($fileSize > $maxBytes) {
            throw new PluginInstallationException(sprintf(
                'L\'archive dépasse la taille maximale autorisée (%d Mo).',
                $this->maxUploadMb,
            ));
        }

        // Verify ZIP PK signature (`PK\x03\x04` or empty-zip `PK\x05\x06`)
        $handle = fopen($zipPath, 'rb');
        $magic = $handle !== false ? fread($handle, 4) : false;
        if ($handle !== false) {
            fclose($handle);
        }
        if ($magic !== "PK\x03\x04") {
            throw new PluginInstallationException('Le fichier fourni n\'est pas une archive ZIP valide (signature invalide).');
        }

        // Verify MIME type via finfo when available
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $zipPath);
                finfo_close($finfo);
                if (is_string($mime) && !in_array($mime, self::ALLOWED_MIMES, true)) {
                    throw new PluginInstallationException(sprintf(
                        'Type MIME non autorisé : "%s" (application/zip requis).',
                        $mime,
                    ));
                }
            }
        }

        $zip = new ZipArchive();
        $openResult = $zip->open($zipPath, ZipArchive::CHECKCONS);
        if ($openResult !== true) {
            throw new PluginInstallationException('Impossible d\'ouvrir l\'archive ZIP (archive corrompue ou format non supporté).');
        }

        try {
            $inspection = $this->validateZipContents($zip);
        } finally {
            $zip->close();
        }

        $manifest = $inspection['manifest'];
        $existing = $this->manager->get($manifest->slug, fresh: true);
        $existingVersion = $existing !== null ? $existing->version() : null;
        $isUpgrade = false;
        $isDowngradeOrSame = false;

        if ($existingVersion !== null && Version::isValid($existingVersion)) {
            $cmp = Version::compare($manifest->version, $existingVersion);
            $isUpgrade = $cmp > 0;
            $isDowngradeOrSame = $cmp <= 0;
        }

        return [
            'manifest' => $manifest,
            'warnings' => $inspection['warnings'],
            'file_count' => $inspection['file_count'],
            'uncompressed_bytes' => $inspection['uncompressed_bytes'],
            'existing_version' => $existingVersion,
            'is_upgrade' => $isUpgrade,
            'is_downgrade_or_same' => $isDowngradeOrSame,
        ];
    }

    /**
     * Validate, safely extract, and atomically install (or replace) a plugin from a ZIP archive.
     *
     * @return array{manifest: PluginManifest, replaced: bool, previous_version: ?string, warnings: list<string>}
     */
    public function installFromZip(string $zipPath, bool $replaceExisting = false, bool $force = false): array
    {
        $info = $this->inspectZip($zipPath);
        $manifest = $info['manifest'];
        $slug = $manifest->slug;
        $targetDir = rtrim($this->pluginsPath, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . $slug;

        $existsOnDisk = is_dir($targetDir);
        $previousVersion = $info['existing_version'];

        if ($existsOnDisk) {
            if (!$replaceExisting && !$force) {
                throw new PluginInstallationException(
                    sprintf(
                        'Le plugin "%s" (version %s) est déjà installé. Confirmez le remplacement pour installer la version %s.',
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

            if ($info['is_downgrade_or_same'] && !$force) {
                throw new PluginInstallationException(
                    sprintf(
                        'La version de l\'archive (%s) est inférieure ou égale à la version installée (%s). Utilisez --force ou confirmez explicitement pour forcer le remplacement.',
                        $manifest->version,
                        $previousVersion ?? 'inconnue',
                    ),
                    conflictSlug: $slug,
                    existingVersion: $previousVersion ?? '0.0.0',
                    incomingVersion: $manifest->version,
                    isDowngradeOrSame: true,
                );
            }
        }

        if (!is_dir($this->tmpPath)) {
            if (!mkdir($this->tmpPath, 0755, true) && !is_dir($this->tmpPath)) {
                throw new PluginInstallationException('Impossible de créer le dossier temporaire storage/tmp.');
            }
        }

        if (!is_dir($this->pluginsPath)) {
            if (!mkdir($this->pluginsPath, 0755, true) && !is_dir($this->pluginsPath)) {
                throw new PluginInstallationException('Impossible de créer le dossier content/plugins.');
            }
        }

        $stagingRoot = rtrim($this->tmpPath, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . 'plugin-install-' . bin2hex(random_bytes(8));
        if (!mkdir($stagingRoot, 0755, true)) {
            throw new PluginInstallationException('Impossible de créer le répertoire d\'extraction temporaire.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CHECKCONS) !== true) {
            AssetPublisher::deleteDirectory($stagingRoot);
            throw new PluginInstallationException('Impossible d\'ouvrir l\'archive ZIP pour extraction.');
        }

        try {
            $this->extractSafely($zip, $stagingRoot, $slug);
        } catch (PluginInstallationException $exception) {
            $zip->close();
            AssetPublisher::deleteDirectory($stagingRoot);
            throw $exception;
        }
        $zip->close();

        $extractedPluginDir = $stagingRoot . DIRECTORY_SEPARATOR . $slug;
        if (!is_dir($extractedPluginDir) || !is_file($extractedPluginDir . DIRECTORY_SEPARATOR . 'plugin.json')) {
            AssetPublisher::deleteDirectory($stagingRoot);
            throw new PluginInstallationException('L\'extraction de l\'archive a échoué : dossier racine introuvable.');
        }

        // Atomic swap with rollback backup if replacing an existing plugin
        $backupDir = null;
        try {
            if ($existsOnDisk) {
                $backupDir = rtrim($this->tmpPath, DIRECTORY_SEPARATOR . '/')
                    . DIRECTORY_SEPARATOR . 'plugin-backup-' . $slug . '-' . bin2hex(random_bytes(6));

                if (!rename($targetDir, $backupDir)) {
                    throw new PluginInstallationException(sprintf(
                        'Impossible de sauvegarder la version précédente de "%s" avant remplacement.',
                        $slug,
                    ));
                }
            }

            if (!rename($extractedPluginDir, $targetDir)) {
                if ($backupDir !== null && is_dir($backupDir)) {
                    @rename($backupDir, $targetDir);
                }
                throw new PluginInstallationException(sprintf(
                    'Impossible de déplacer le plugin "%s" dans content/plugins/.',
                    $slug,
                ));
            }
        } finally {
            AssetPublisher::deleteDirectory($stagingRoot);
        }

        // Clean up backup after atomic swap succeeded
        if ($backupDir !== null && is_dir($backupDir)) {
            AssetPublisher::deleteDirectory($backupDir);
        }

        // Update DB state: new plugins start inactive; active replaced plugins run upgrade migrations/assets
        $existingDb = $this->repository->find($slug);
        if ($existingDb === null) {
            $this->repository->recordInstalled($slug, $manifest->version, $manifest->hash);
        } elseif ($existingDb['status'] === PluginInfo::STATUS_ACTIVE && $previousVersion !== null) {
            $this->manager->syncUpgradedPlugin($slug, $previousVersion);
        } else {
            $this->repository->recordInstalled($slug, $manifest->version, $manifest->hash);
        }

        $this->manager->invalidateCache();
        $this->hooks->emit('plugin.installed', $manifest, $existsOnDisk, $previousVersion);

        return [
            'manifest' => $manifest,
            'replaced' => $existsOnDisk,
            'previous_version' => $previousVersion,
            'warnings' => $info['warnings'],
        ];
    }

    /**
     * Validate every single entry in the ZIP archive BEFORE writing anything to disk.
     *
     * @return array{manifest: PluginManifest, warnings: list<string>, file_count: int, uncompressed_bytes: int}
     */
    private function validateZipContents(ZipArchive $zip): array
    {
        $numFiles = $zip->numFiles;
        if ($numFiles === 0) {
            throw new PluginInstallationException('L\'archive ZIP ne contient aucun fichier.');
        }

        if ($numFiles > $this->maxZipFiles) {
            throw new PluginInstallationException(sprintf(
                'L\'archive contient trop d\'entrées (%d, maximum autorisé : %d).',
                $numFiles,
                $this->maxZipFiles,
            ));
        }

        $totalUncompressed = 0;
        $totalCompressed = 0;
        $rootDirs = [];
        $manifestEntryName = null;

        for ($i = 0; $i < $numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new PluginInstallationException('Impossible de lire les métadonnées d\'une entrée de l\'archive ZIP.');
            }

            $name = $stat['name'];

            // Ignore harmless macOS metadata folders if present, or strictly reject root-level junk?
            // Security-first: validate EVERY entry path strictly!
            if ($name === '' || str_contains($name, "\0")) {
                throw new PluginInstallationException('Archive rejetée : nom de fichier contenant un octet nul (NUL).');
            }

            if (str_contains($name, '\\')) {
                throw new PluginInstallationException(sprintf('Archive rejetée : séparateur antislash interdit dans "%s".', $name));
            }

            if (str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) === 1) {
                throw new PluginInstallationException(sprintf('Archive rejetée : chemin absolu interdit ("%s").', $name));
            }

            $segments = explode('/', rtrim($name, '/'));
            foreach ($segments as $segment) {
                if ($segment === '..' || $segment === '.') {
                    throw new PluginInstallationException(sprintf('Archive rejetée : traversée de répertoire interdite ("%s").', $name));
                }
            }

            // Detect Unix symbolic links inside ZIP external attributes (S_IFLNK = 0120000)
            $opsys = 0;
            $attr = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr)) {
                $unixMode = ($attr >> 16) & 0170000;
                if ($unixMode === 0120000) {
                    throw new PluginInstallationException(sprintf('Archive rejetée : les liens symboliques sont interdits ("%s").', $name));
                }
            }

            $isDir = str_ends_with($name, '/');
            $rootDirs[$segments[0]] = true;

            if (count($rootDirs) > 1) {
                throw new PluginInstallationException(
                    'L\'archive ZIP doit contenir un unique dossier racine nommé d\'après le slug du plugin.',
                );
            }

            if (!$isDir) {
                $baseLower = strtolower(basename($name));
                if (in_array($baseLower, self::FORBIDDEN_BASENAMES, true)) {
                    throw new PluginInstallationException(sprintf('Archive rejetée : fichier interdit "%s".', $name));
                }

                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (in_array($ext, self::FORBIDDEN_EXTENSIONS, true)) {
                    throw new PluginInstallationException(sprintf('Archive rejetée : extension interdite ".%s" dans "%s".', $ext, $name));
                }

                $size = (int) $stat['size'];
                $compSize = (int) $stat['comp_size'];

                if ($size < 0 || $compSize < 0) {
                    throw new PluginInstallationException('Archive rejetée : taille d\'entrée invalide.');
                }

                $totalUncompressed += $size;
                $totalCompressed += $compSize;

                if ($totalUncompressed > $this->maxUncompressedBytes) {
                    throw new PluginInstallationException(sprintf(
                        'Archive rejetée : taille décompressée excessive (limite : %d Mo).',
                        (int) round($this->maxUncompressedBytes / (1024 * 1024)),
                    ));
                }

                if ($size > 1024 * 1024 && $compSize > 0 && ($size / $compSize) > $this->maxCompressionRatio) {
                    throw new PluginInstallationException('Archive rejetée : ratio de compression suspect (protection anti-ZIP bomb).');
                }

                if (count($segments) === 2 && $segments[1] === 'plugin.json') {
                    $manifestEntryName = $name;
                }
            }
        }

        if ($totalUncompressed > 1024 * 1024 && $totalCompressed > 0 && ($totalUncompressed / $totalCompressed) > $this->maxCompressionRatio) {
            throw new PluginInstallationException('Archive rejetée : ratio global de compression suspect (protection anti-ZIP bomb).');
        }

        $rootSlug = (string) array_key_first($rootDirs);
        if ($manifestEntryName === null) {
            throw new PluginInstallationException(sprintf(
                'Le fichier "%s/plugin.json" est introuvable à la racine de l\'archive.',
                $rootSlug,
            ));
        }

        $manifestRaw = $zip->getFromName($manifestEntryName);
        if (!is_string($manifestRaw) || $manifestRaw === '') {
            throw new PluginInstallationException('Impossible de lire plugin.json dans l\'archive ZIP.');
        }

        $validation = $this->validator->validateJson($manifestRaw, $rootSlug);
        if ($validation['manifest'] === null || $validation['errors'] !== []) {
            throw new PluginInstallationException(
                'Manifeste plugin.json invalide : ' . implode(' ; ', $validation['errors']),
            );
        }

        return [
            'manifest' => $validation['manifest'],
            'warnings' => $validation['warnings'],
            'file_count' => $numFiles,
            'uncompressed_bytes' => $totalUncompressed,
        ];
    }

    /**
     * Extract validated entries stream-by-stream while re-verifying every destination path stays
     * strictly inside `$stagingRoot/$expectedRootSlug`.
     */
    private function extractSafely(ZipArchive $zip, string $stagingRoot, string $expectedRootSlug): void
    {
        $realStaging = realpath($stagingRoot);
        if ($realStaging === false) {
            throw new PluginInstallationException('Dossier temporaire introuvable lors de l\'extraction.');
        }

        $writtenBytes = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name) || $name === '') {
                continue;
            }

            if ($name !== $expectedRootSlug . '/' && !str_starts_with($name, $expectedRootSlug . '/')) {
                throw new PluginInstallationException('Entrée ZIP hors du dossier racine attendu.');
            }

            $destPath = $realStaging . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, rtrim($name, '/'));

            if (str_ends_with($name, '/')) {
                if (!is_dir($destPath) && !mkdir($destPath, 0755, true) && !is_dir($destPath)) {
                    throw new PluginInstallationException(sprintf('Impossible de créer le répertoire "%s".', $name));
                }
                continue;
            }

            $parent = dirname($destPath);
            if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
                throw new PluginInstallationException(sprintf('Impossible de créer le répertoire parent pour "%s".', $name));
            }

            $realParent = realpath($parent);
            if ($realParent === false || ($realParent !== $realStaging && !str_starts_with($realParent, $realStaging . DIRECTORY_SEPARATOR))) {
                throw new PluginInstallationException('Tentative d\'écriture hors du répertoire d\'extraction bloquée (Zip Slip).');
            }

            $inStream = $zip->getStream($name);
            if ($inStream === false) {
                throw new PluginInstallationException(sprintf('Impossible de lire le fichier "%s" dans l\'archive.', $name));
            }

            $outStream = fopen($destPath, 'wb');
            if ($outStream === false) {
                fclose($inStream);
                throw new PluginInstallationException(sprintf('Impossible d\'écrire le fichier "%s".', $name));
            }

            try {
                while (!feof($inStream)) {
                    $chunk = fread($inStream, 65536);
                    if ($chunk === false) {
                        throw new PluginInstallationException(sprintf('Erreur de lecture sur "%s".', $name));
                    }
                    $writtenBytes += strlen($chunk);
                    if ($writtenBytes > $this->maxUncompressedBytes) {
                        throw new PluginInstallationException('Limite de taille décompressée dépassée pendant l\'extraction.');
                    }
                    if (fwrite($outStream, $chunk) === false) {
                        throw new PluginInstallationException(sprintf('Erreur d\'écriture sur "%s".', $name));
                    }
                }
            } finally {
                fclose($inStream);
                fclose($outStream);
            }

            @chmod($destPath, 0644);
        }
    }
}
