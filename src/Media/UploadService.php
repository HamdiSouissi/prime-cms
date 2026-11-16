<?php

declare(strict_types=1);

namespace Prime\Media;

use DateTimeImmutable;
use DateTimeZone;
use finfo;
use Prime\Auth\User;
use Prime\Hooks\Hooks;
use Prime\Media\Exception\MediaException;
use Prime\Media\Exception\MediaValidationException;
use Prime\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Defensive media upload, replacement, metadata update, regeneration, and deletion service.
 */
final class UploadService
{
    /**
     * Dangerous extensions rejected anywhere inside double extensions (e.g. `photo.php.jpg`).
     *
     * @var list<string>
     */
    private const DANGEROUS_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps', 'pht',
        'shtml', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'zsh',
        'exe', 'bat', 'cmd', 'com', 'msi', 'dll', 'scr', 'vbs', 'ps1',
        'js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx',
        'asp', 'aspx', 'jsp', 'jspx',
        'htaccess', 'htpasswd',
        'svg', 'svgz', 'html', 'htm', 'xhtml', 'xml', 'swf',
    ];

    /**
     * Expected `IMAGETYPE_*` constants per allowed image extension.
     *
     * @var array<string, list<int>>
     */
    private const EXPECTED_IMAGE_TYPES = [
        'jpg' => [IMAGETYPE_JPEG],
        'jpeg' => [IMAGETYPE_JPEG],
        'png' => [IMAGETYPE_PNG],
        'gif' => [IMAGETYPE_GIF],
        'webp' => [IMAGETYPE_WEBP],
        'avif' => [19], // IMAGETYPE_AVIF (PHP 8.1+)
    ];

    public function __construct(
        private readonly MediaConfig $config,
        private readonly MediaRepository $media,
        private readonly MediaFolderRepository $folders,
        private readonly ImageProcessor $images,
        private readonly UploadsGuard $guard,
        private readonly ?Hooks $hooks = null,
    ) {
    }

    /**
     * Validate, store, process, and register a newly uploaded file.
     *
     * @param array<string, mixed> $uploadedFile PHP `$_FILES` entry (`name`, `tmp_name`, `size`, `error`)
     * @param array<string, mixed> $metadata Optional initial `title`, `alt`, `caption`, `description`
     * @return array{media: Media, duplicate: ?Media}
     */
    public function upload(
        array $uploadedFile,
        ?User $actor = null,
        ?int $folderId = null,
        array $metadata = [],
    ): array {
        if ($folderId !== null && ($folderId <= 0 || $this->folders->find($folderId) === null)) {
            throw new MediaValidationException(
                'Le dossier de destination sélectionné est introuvable.',
                ['folder_id' => ['Dossier introuvable.']],
            );
        }

        $validated = $this->validateUploadedFile($uploadedFile);

        if ($this->hooks !== null) {
            $this->hooks->action('media.before_upload', $validated, $actor, $folderId);
        }

        $this->guard->ensureProtectedUploadsDirectory();
        $uploadsRoot = $this->config->uploadsAbsolutePath();

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $subDir = $now->format('Y/m');
        $relPath = $this->generateUniqueRelativePath($subDir, $validated['stem'], $validated['extension']);
        $absPath = $uploadsRoot . '/' . $relPath;
        $filename = basename($relPath);

        $writtenFiles = [];

        try {
            $width = null;
            $height = null;
            $sizeBytes = $validated['size'];
            $variants = [];

            if ($validated['kind'] === Media::KIND_IMAGE) {
                $processed = $this->images->processUpload(
                    $validated['tmp_path'],
                    $relPath,
                    $validated['extension'],
                    focalX: 0.5,
                    focalY: 0.5,
                );
                $width = $processed['width'];
                $height = $processed['height'];
                $sizeBytes = $processed['size_bytes'];
                $variants = $processed['variants'];
                $writtenFiles[] = $absPath;
                foreach ($variants as $v) {
                    $writtenFiles[] = $uploadsRoot . '/' . ltrim($v['path'], '/');
                    if (isset($v['webp']) && is_string($v['webp'])) {
                        $writtenFiles[] = $uploadsRoot . '/' . ltrim($v['webp'], '/');
                    }
                }
            } else {
                $this->writeAtomicCopy($validated['tmp_path'], $absPath);
                $writtenFiles[] = $absPath;
                $actualSize = filesize($absPath);
                if ($actualSize !== false) {
                    $sizeBytes = $actualSize;
                }
            }

            $checksum = hash_file('sha256', $absPath);
            if (!is_string($checksum) || $checksum === '') {
                throw new MediaException('Impossible de calculer l\'empreinte SHA-256 du fichier.');
            }

            $duplicate = $this->media->findFirstByChecksum($checksum);

            $defaultTitle = trim((string) ($metadata['title'] ?? ''));
            if ($defaultTitle === '') {
                $defaultTitle = $this->humanTitleFromFilename($validated['original_name']);
            }

            $created = $this->media->create([
                'folder_id' => $folderId,
                'author_id' => $actor?->id,
                'disk_path' => $relPath,
                'filename' => $filename,
                'original_name' => $validated['original_name'],
                'extension' => $validated['extension'],
                'mime_type' => $validated['mime_type'],
                'kind' => $validated['kind'],
                'size_bytes' => $sizeBytes,
                'width' => $width,
                'height' => $height,
                'checksum' => $checksum,
                'alt' => mb_substr(trim((string) ($metadata['alt'] ?? '')), 0, 255, 'UTF-8'),
                'title' => mb_substr($defaultTitle, 0, 255, 'UTF-8'),
                'caption' => isset($metadata['caption']) && is_string($metadata['caption']) ? trim($metadata['caption']) : null,
                'description' => isset($metadata['description']) && is_string($metadata['description']) ? trim($metadata['description']) : null,
                'focal_x' => 0.5,
                'focal_y' => 0.5,
                'variants' => $variants,
            ]);

            if ($this->hooks !== null) {
                $this->hooks->action('media.uploaded', $created, $actor, $duplicate);
            }

            return [
                'media' => $created,
                'duplicate' => $duplicate,
            ];
        } catch (Throwable $e) {
            foreach (array_unique($writtenFiles) as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }

            if ($e instanceof MediaException) {
                throw $e;
            }

            throw new MediaException('Échec de l\'enregistrement du média : ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Replace an existing media file while preserving its ID and base URL (same directory + stem).
     *
     * @param array<string, mixed> $uploadedFile
     */
    public function replace(Media $existing, array $uploadedFile, ?User $actor = null): Media
    {
        $validated = $this->validateUploadedFile($uploadedFile);

        if ($this->hooks !== null) {
            $this->hooks->action('media.before_upload', $validated, $actor, $existing->folderId);
        }

        $this->guard->ensureProtectedUploadsDirectory();
        $uploadsRoot = $this->config->uploadsAbsolutePath();

        // Keep exact base directory & filename stem so URL stays identical when extension matches
        $dir = dirname($existing->diskPath);
        $stem = pathinfo($existing->diskPath, PATHINFO_FILENAME);
        $newRelPath = ($dir !== '.' && $dir !== '' ? $dir . '/' : '') . $stem . '.' . $validated['extension'];
        $newAbsPath = $uploadsRoot . '/' . $newRelPath;

        // Stage into a unique temporary sibling path first so any failure leaves the existing file untouched
        $stagingRelPath = ($dir !== '.' && $dir !== '' ? $dir . '/' : '') . '.tmp-' . bin2hex(random_bytes(6)) . '.' . $validated['extension'];
        $stagingAbsPath = $uploadsRoot . '/' . $stagingRelPath;

        $oldPaths = $existing->allRelativePaths();
        $newWrittenPaths = [];

        try {
            $width = null;
            $height = null;
            $sizeBytes = $validated['size'];
            $variants = [];

            if ($validated['kind'] === Media::KIND_IMAGE) {
                // Validate/process to staging first to verify GD succeeds completely
                $staged = $this->images->processUpload(
                    $validated['tmp_path'],
                    $stagingRelPath,
                    $validated['extension'],
                    focalX: $existing->focalX,
                    focalY: $existing->focalY,
                );
                // Clean up staging variants before final atomic write at canonical path
                @unlink($stagingAbsPath);
                foreach ($staged['variants'] as $sv) {
                    @unlink($uploadsRoot . '/' . ltrim($sv['path'], '/'));
                    if (isset($sv['webp']) && is_string($sv['webp'])) {
                        @unlink($uploadsRoot . '/' . ltrim($sv['webp'], '/'));
                    }
                }

                // Now write directly to canonical path and regenerate final variants
                $processed = $this->images->processUpload(
                    $validated['tmp_path'],
                    $newRelPath,
                    $validated['extension'],
                    focalX: $existing->focalX,
                    focalY: $existing->focalY,
                );
                $width = $processed['width'];
                $height = $processed['height'];
                $sizeBytes = $processed['size_bytes'];
                $variants = $processed['variants'];
                $newWrittenPaths[$newRelPath] = true;
                foreach ($variants as $v) {
                    $newWrittenPaths[$v['path']] = true;
                    if (isset($v['webp']) && is_string($v['webp'])) {
                        $newWrittenPaths[$v['webp']] = true;
                    }
                }
            } else {
                $this->writeAtomicCopy($validated['tmp_path'], $newAbsPath);
                $newWrittenPaths[$newRelPath] = true;
                $actualSize = filesize($newAbsPath);
                if ($actualSize !== false) {
                    $sizeBytes = $actualSize;
                }
            }

            $checksum = hash_file('sha256', $newAbsPath);
            if (!is_string($checksum) || $checksum === '') {
                throw new MediaException('Impossible de calculer l\'empreinte SHA-256 du fichier de remplacement.');
            }

            $updated = $this->media->update($existing->id, [
                'disk_path' => $newRelPath,
                'filename' => basename($newRelPath),
                'original_name' => $validated['original_name'],
                'extension' => $validated['extension'],
                'mime_type' => $validated['mime_type'],
                'kind' => $validated['kind'],
                'size_bytes' => $sizeBytes,
                'width' => $width,
                'height' => $height,
                'checksum' => $checksum,
                'variants' => $variants,
            ]);

            if ($updated === null) {
                throw new MediaException('Média introuvable après remplacement.');
            }

            // Remove old files that are no longer part of the new media asset
            foreach ($oldPaths as $oldRel) {
                if (!isset($newWrittenPaths[$oldRel])) {
                    $this->safeDeleteRelativePath($oldRel);
                }
            }

            if ($this->hooks !== null) {
                $this->hooks->action('media.updated', $updated, $actor);
            }

            return $updated;
        } catch (Throwable $e) {
            if (is_file($stagingAbsPath)) {
                @unlink($stagingAbsPath);
            }

            if ($e instanceof MediaException) {
                throw $e;
            }

            throw new MediaException('Impossible de remplacer le fichier : ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Update editable metadata (`alt`, `title`, `caption`, `description`, `folder_id`, `focal_x`, `focal_y`).
     * Automatically regenerates cropped variants when the focal point changes on an image.
     *
     * @param array<string, mixed> $input
     */
    public function updateMetadata(Media $media, array $input, ?User $actor = null): Media
    {
        $fields = [];

        if (array_key_exists('alt', $input)) {
            $fields['alt'] = mb_substr(trim((string) $input['alt']), 0, 255, 'UTF-8');
        }
        if (array_key_exists('title', $input)) {
            $fields['title'] = mb_substr(trim((string) $input['title']), 0, 255, 'UTF-8');
        }
        if (array_key_exists('caption', $input)) {
            $cap = trim((string) $input['caption']);
            $fields['caption'] = $cap !== '' ? $cap : null;
        }
        if (array_key_exists('description', $input)) {
            $desc = trim((string) $input['description']);
            $fields['description'] = $desc !== '' ? $desc : null;
        }
        if (array_key_exists('folder_id', $input)) {
            $rawFolder = $input['folder_id'];
            if ($rawFolder === null || $rawFolder === '' || $rawFolder === 0 || $rawFolder === '0') {
                $fields['folder_id'] = null;
            } else {
                $fid = (int) $rawFolder;
                if ($fid <= 0 || $this->folders->find($fid) === null) {
                    throw new MediaValidationException(
                        'Le dossier sélectionné est introuvable.',
                        ['folder_id' => ['Dossier introuvable.']],
                    );
                }
                $fields['folder_id'] = $fid;
            }
        }

        $focalChanged = false;
        if (array_key_exists('focal_x', $input) && is_numeric($input['focal_x'])) {
            $fx = round(max(0.0, min(1.0, (float) $input['focal_x'])), 3);
            if (abs($fx - $media->focalX) > 0.0005) {
                $focalChanged = true;
            }
            $fields['focal_x'] = $fx;
        }
        if (array_key_exists('focal_y', $input) && is_numeric($input['focal_y'])) {
            $fy = round(max(0.0, min(1.0, (float) $input['focal_y'])), 3);
            if (abs($fy - $media->focalY) > 0.0005) {
                $focalChanged = true;
            }
            $fields['focal_y'] = $fy;
        }

        $updated = $fields !== [] ? ($this->media->update($media->id, $fields) ?? $media) : $media;

        if ($focalChanged && $updated->isImage()) {
            $variants = $this->images->regenerate($updated);
            $updated = $this->media->update($updated->id, ['variants' => $variants]) ?? $updated;
        }

        if ($this->hooks !== null) {
            $this->hooks->action('media.updated', $updated, $actor);
        }

        return $updated;
    }

    /**
     * Regenerate GD variants for a media item and persist the updated `variants` JSON.
     */
    public function regenerate(Media $media, ?string $onlySize = null, ?User $actor = null): Media
    {
        if (!$media->isImage()) {
            return $media;
        }

        $variants = $this->images->regenerate($media, $onlySize);
        $updated = $this->media->update($media->id, ['variants' => $variants]) ?? $media;

        if ($this->hooks !== null) {
            $this->hooks->action('media.updated', $updated, $actor);
        }

        return $updated;
    }

    /**
     * Delete a media item from database and remove its original + all generated variants from disk.
     */
    public function delete(Media $media, ?User $actor = null): void
    {
        $paths = $media->allRelativePaths();
        $this->media->delete($media->id);

        foreach ($paths as $relPath) {
            $this->safeDeleteRelativePath($relPath);
        }

        if ($this->hooks !== null) {
            $this->hooks->action('media.deleted', $media, $actor);
        }
    }

    /**
     * Defensive validation of an uploaded file (zero writes performed).
     *
     * @param array<string, mixed> $file
     * @return array{
     *   tmp_path: string,
     *   original_name: string,
     *   stem: string,
     *   extension: string,
     *   mime_type: string,
     *   kind: string,
     *   size: int
     * }
     */
    private function validateUploadedFile(array $file): array
    {
        $errorCode = isset($file['error']) && is_numeric($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($errorCode !== UPLOAD_ERR_OK) {
            throw new MediaValidationException($this->uploadErrorMessage($errorCode), ['file' => [$this->uploadErrorMessage($errorCode)]]);
        }

        $rawName = is_string($file['name'] ?? null) ? $file['name'] : '';
        $tmpPath = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';

        if ($rawName === '' || $tmpPath === '' || !is_file($tmpPath) || !is_readable($tmpPath)) {
            throw new MediaValidationException('Aucun fichier valide n\'a été reçu.', ['file' => ['Aucun fichier valide n\'a été reçu.']]);
        }

        // Reject null bytes and path traversal characters in filename
        if (str_contains($rawName, "\0") || str_contains($rawName, '/') || str_contains($rawName, '\\')) {
            throw new MediaValidationException('Le nom du fichier contient des caractères interdits.', ['file' => ['Nom de fichier invalide.']]);
        }

        $cleanName = trim($rawName);
        if ($cleanName === '' || str_starts_with($cleanName, '.')) {
            throw new MediaValidationException('Les fichiers cachés ou sans nom ne sont pas autorisés.', ['file' => ['Nom de fichier invalide.']]);
        }

        // Check empty file
        $actualSize = filesize($tmpPath);
        if ($actualSize === false || $actualSize <= 0) {
            throw new MediaValidationException('Le fichier est vide et ne peut pas être téléversé.', ['file' => ['Le fichier est vide.']]);
        }

        // Check max upload size (accounting for PHP upload_max_filesize / post_max_size)
        $limit = $this->config->effectiveUploadLimit();
        if ($actualSize > $limit['bytes']) {
            $msg = $limit['phpLimited']
                ? sprintf(
                    'Le fichier dépasse la taille maximale autorisée par le serveur PHP (%s Mo ; limite configurée : %d Mo).',
                    number_format($limit['megabytes'], 1, ',', ' '),
                    $this->config->configuredMaxUploadMb(),
                )
                : sprintf(
                    'Le fichier dépasse la taille maximale autorisée (%d Mo).',
                    $this->config->configuredMaxUploadMb(),
                );
            throw new MediaValidationException($msg, ['file' => [$msg]]);
        }

        // Inspect all extension segments for dangerous double extensions (e.g. photo.php.jpg)
        $parts = explode('.', $cleanName);
        if (count($parts) < 2) {
            throw new MediaValidationException('Le fichier doit posséder une extension reconnue.', ['file' => ['Extension manquante.']]);
        }

        $extension = strtolower(array_pop($parts));
        if ($extension === '' || preg_match('/^[a-z0-9]{1,16}$/', $extension) !== 1) {
            throw new MediaValidationException('L\'extension du fichier est invalide.', ['file' => ['Extension invalide.']]);
        }

        // Any intermediate segment (after the base filename) matching a dangerous extension is rejected!
        if (count($parts) >= 2) {
            $intermediateSegments = array_slice($parts, 1);
            foreach ($intermediateSegments as $seg) {
                if (in_array(strtolower(trim($seg)), self::DANGEROUS_EXTENSIONS, true)) {
                    throw new MediaValidationException(
                        sprintf('Double extension dangereuse détectée (.%s.%s). Téléversement refusé.', strtolower($seg), $extension),
                        ['file' => ['Double extension dangereuse détectée.']],
                    );
                }
            }
        }

        $allowedMap = $this->config->allowedExtensionMap();
        if (!isset($allowedMap[$extension])) {
            if (in_array($extension, ['svg', 'svgz'], true)) {
                $msg = 'Les fichiers SVG sont désactivés par défaut pour des raisons de sécurité (risque XSS).';
            } else {
                $msg = sprintf('Le type de fichier « .%s » n\'est pas autorisé.', $extension);
            }
            throw new MediaValidationException($msg, ['file' => [$msg]]);
        }

        $kind = $allowedMap[$extension]['kind'];
        $allowedMimes = $allowedMap[$extension]['mimes'];

        // Inspect leading 4 KB for PHP / script / polyglot payloads BEFORE anything else
        $this->assertNoPolyglotOrExecutableContent($tmpPath);

        // Real MIME detection via finfo
        $detectedMime = $this->detectRealMime($tmpPath);
        if (!in_array($detectedMime, $allowedMimes, true)) {
            throw new MediaValidationException(
                sprintf(
                    'Le contenu réel du fichier (%s) ne correspond pas à l\'extension « .%s ».',
                    $detectedMime,
                    $extension,
                ),
                ['file' => ['Le type MIME réel ne correspond pas à l\'extension.']],
            );
        }

        // Strict structural checks for PDF, OOXML/ODF documents, and images
        if ($extension === 'pdf') {
            $head = @file_get_contents($tmpPath, false, null, 0, 1024);
            if (!is_string($head) || !str_contains($head, '%PDF-')) {
                throw new MediaValidationException(
                    'Le fichier PDF est invalide ou corrompu.',
                    ['file' => ['En-tête PDF invalide.']],
                );
            }
        } elseif (in_array($extension, ['docx', 'xlsx', 'pptx'], true)) {
            $this->assertValidZipDocument($tmpPath, '[Content_Types].xml', $extension);
        } elseif (in_array($extension, ['odt', 'ods'], true)) {
            $this->assertValidZipDocument($tmpPath, 'mimetype', $extension);
        }

        if ($kind === Media::KIND_IMAGE) {
            $this->assertValidImage($tmpPath, $extension);
        }

        $stem = implode('-', $parts);

        return [
            'tmp_path' => $tmpPath,
            'original_name' => $cleanName,
            'stem' => $stem,
            'extension' => $extension,
            'mime_type' => $detectedMime,
            'kind' => $kind,
            'size' => $actualSize,
        ];
    }

    private function assertNoPolyglotOrExecutableContent(string $path): void
    {
        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            throw new MediaValidationException('Impossible de lire le fichier téléversé.');
        }

        try {
            $head = fread($fp, 4096);
        } finally {
            fclose($fp);
        }

        if (!is_string($head) || $head === '') {
            throw new MediaValidationException('Le fichier est vide.');
        }

        // Reject files starting with `<?php`, `<?=`, or `<script` (after optional UTF-8 BOM & whitespace)
        $trimmedHead = ltrim($head, "\xEF\xBB\xBF \t\r\n");
        if (preg_match('/^(?:<\?php\b|<\?=|<script\b)/i', $trimmedHead) === 1) {
            throw new MediaValidationException(
                'Contenu exécutable ou script détecté en début de fichier. Téléversement refusé.',
                ['file' => ['Contenu exécutable interdit.']],
            );
        }

        // Reject polyglots containing `<?php` or `<?=` anywhere in the first 4 KB
        if (preg_match('/<\?php\b|<\?=/i', $head) === 1) {
            throw new MediaValidationException(
                'Signature PHP détectée dans l\'en-tête du fichier (fichier polyglotte refusé).',
                ['file' => ['Fichier polyglotte refusé.']],
            );
        }
    }

    private function assertValidImage(string $tmpPath, string $extension): void
    {
        $info = @getimagesize($tmpPath);
        if (!is_array($info)) {
            throw new MediaValidationException(
                'Le fichier ne contient pas une image valide.',
                ['file' => ['Image invalide ou corrompue.']],
            );
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        $imageType = (int) $info[2];

        if ($width <= 0 || $height <= 0) {
            throw new MediaValidationException(
                'Les dimensions de l\'image sont invalides.',
                ['file' => ['Dimensions d\'image invalides.']],
            );
        }

        $expectedTypes = self::EXPECTED_IMAGE_TYPES[$extension] ?? [];
        if ($expectedTypes !== [] && !in_array($imageType, $expectedTypes, true)) {
            throw new MediaValidationException(
                sprintf('Le format réel de l\'image ne correspond pas à l\'extension « .%s ».', $extension),
                ['file' => ['Format d\'image incompatible avec l\'extension.']],
            );
        }

        $pixels = $width * $height;
        $maxPixels = $this->config->maxPixels();
        if ($pixels > $maxPixels) {
            $mp = round($pixels / 1_000_000, 1);
            throw new MediaValidationException(
                sprintf(
                    'L\'image dépasse la résolution maximale autorisée (%s MP pour une limite de %d MP).',
                    number_format($mp, 1, ',', ' '),
                    $this->config->maxMegapixels(),
                ),
                ['file' => ['Résolution d\'image trop élevée.']],
            );
        }
    }

    private function assertValidZipDocument(string $tmpPath, string $requiredEntry, string $extension): void
    {
        if (!class_exists(ZipArchive::class)) {
            return;
        }
        $zip = new ZipArchive();
        if ($zip->open($tmpPath) !== true) {
            throw new MediaValidationException(
                sprintf('Le document « .%s » est corrompu ou n\'est pas une archive valide.', $extension),
                ['file' => ['Archive document invalide.']],
            );
        }

        $hasEntry = $zip->locateName($requiredEntry) !== false;
        $zip->close();

        if (!$hasEntry) {
            throw new MediaValidationException(
                sprintf('La structure du document « .%s » est invalide.', $extension),
                ['file' => ['Structure de document invalide.']],
            );
        }
    }

    private function detectRealMime(string $path): string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($path);

        return is_string($detected) && $detected !== '' ? strtolower(trim($detected)) : 'application/octet-stream';
    }

    private function generateUniqueRelativePath(string $subDir, string $stem, string $extension): string
    {
        $slug = Str::slug($stem, '-', 80);
        if ($slug === '') {
            $slug = 'fichier';
        }

        $uploadsRoot = $this->config->uploadsAbsolutePath();
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $suffix = bin2hex(random_bytes(3)); // 6 hex chars
            $candidate = sprintf('%s/%s-%s.%s', $subDir, $slug, $suffix, $extension);
            if (!is_file($uploadsRoot . '/' . $candidate) && $this->media->findByDiskPath($candidate) === null) {
                return $candidate;
            }
        }

        return sprintf('%s/%s-%s.%s', $subDir, $slug, bin2hex(random_bytes(5)), $extension);
    }

    private function writeAtomicCopy(string $sourcePath, string $destPath): void
    {
        $dir = dirname($destPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new MediaException(sprintf('Impossible de créer le dossier [%s].', $dir));
        }

        $tmpDest = $dir . '/.upload-' . bin2hex(random_bytes(6)) . '.tmp';
        if (!@copy($sourcePath, $tmpDest)) {
            @unlink($tmpDest);
            throw new MediaException('Impossible d\'écrire le fichier sur le disque.');
        }

        if (!@rename($tmpDest, $destPath)) {
            @unlink($tmpDest);
            throw new MediaException('Impossible de finaliser l\'écriture du fichier.');
        }

        @chmod($destPath, 0644);
    }

    private function safeDeleteRelativePath(string $relPath): void
    {
        $clean = ltrim($relPath, '/');
        if ($clean === '' || str_contains($clean, '..') || str_contains($clean, "\0")) {
            return;
        }

        $uploadsRoot = realpath($this->config->uploadsAbsolutePath());
        if ($uploadsRoot === false) {
            return;
        }

        $candidate = $uploadsRoot . '/' . $clean;
        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved)) {
            return;
        }

        if (!str_starts_with($resolved, $uploadsRoot . DIRECTORY_SEPARATOR)) {
            return;
        }

        @unlink($resolved);
    }

    private function humanTitleFromFilename(string $filename): string
    {
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        $spaced = preg_replace('/[-_]+/', ' ', $stem);
        $trimmed = trim(is_string($spaced) ? $spaced : $stem);

        return $trimmed !== '' ? $trimmed : $filename;
    }

    private function uploadErrorMessage(int $code): string
    {
        $limit = $this->config->effectiveUploadLimit();

        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => sprintf(
                'Le fichier dépasse la taille maximale autorisée (%s Mo).',
                number_format($limit['megabytes'], 1, ',', ' '),
            ),
            UPLOAD_ERR_PARTIAL => 'Le téléversement du fichier a été interrompu.',
            UPLOAD_ERR_NO_FILE => 'Aucun fichier n\'a été envoyé.',
            UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire manquant sur le serveur.',
            UPLOAD_ERR_CANT_WRITE => 'Impossible d\'écrire le fichier temporaire sur le disque.',
            UPLOAD_ERR_EXTENSION => 'Le téléversement a été bloqué par une extension PHP.',
            default => 'Erreur inconnue lors du téléversement du fichier.',
        };
    }
}
