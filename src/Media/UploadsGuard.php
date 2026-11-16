<?php

declare(strict_types=1);

namespace Prime\Media;

use RuntimeException;

/**
 * Manages `content/uploads/.htaccess` hardening and the `public/uploads -> ../content/uploads` symlink.
 */
final class UploadsGuard
{
    public function __construct(
        private readonly MediaConfig $config,
    ) {
    }

    /**
     * Ensure `content/uploads/` exists and contains a hardened `.htaccess` blocking script execution.
     */
    public function ensureProtectedUploadsDirectory(): string
    {
        $uploadsDir = $this->config->uploadsAbsolutePath();
        if (!is_dir($uploadsDir) && !@mkdir($uploadsDir, 0755, true) && !is_dir($uploadsDir)) {
            throw new RuntimeException(sprintf('Impossible de créer le répertoire de téléversement [%s].', $uploadsDir));
        }

        $htaccessPath = $uploadsDir . '/.htaccess';
        $desired = self::htaccessContent();
        $current = is_file($htaccessPath) ? @file_get_contents($htaccessPath) : false;

        if ($current !== $desired) {
            @file_put_contents($htaccessPath, $desired, LOCK_EX);
        }

        return $uploadsDir;
    }

    /**
     * Ensure `public/uploads` symlinks to `../content/uploads` and `content/uploads/.htaccess` is written.
     *
     * @return array{status: 'created'|'already_linked'|'failed', link: string, target: string, message: string}
     */
    public function createPublicSymlink(): array
    {
        $uploadsDir = $this->ensureProtectedUploadsDirectory();
        $linkPath = $this->config->publicLinkAbsolutePath();
        $relativeTarget = '../content/uploads';

        if (is_link($linkPath)) {
            $currentTarget = @readlink($linkPath);
            $resolvedLink = @realpath($linkPath);
            $resolvedUploads = @realpath($uploadsDir);

            if ($currentTarget === $relativeTarget || ($resolvedLink !== false && $resolvedLink === $resolvedUploads)) {
                return [
                    'status' => 'already_linked',
                    'link' => $linkPath,
                    'target' => $relativeTarget,
                    'message' => 'Le lien symbolique public/uploads -> ../content/uploads est déjà en place.',
                ];
            }

            @unlink($linkPath);
        } elseif (file_exists($linkPath)) {
            return [
                'status' => 'failed',
                'link' => $linkPath,
                'target' => $relativeTarget,
                'message' => 'public/uploads existe déjà mais n\'est pas un lien symbolique. Supprimez-le ou déplacez-le avant de relancer media:link.',
            ];
        }

        $publicParent = dirname($linkPath);
        if (!is_dir($publicParent) && !@mkdir($publicParent, 0755, true) && !is_dir($publicParent)) {
            return [
                'status' => 'failed',
                'link' => $linkPath,
                'target' => $relativeTarget,
                'message' => sprintf('Impossible de créer le dossier parent [%s].', $publicParent),
            ];
        }

        if (!@symlink($relativeTarget, $linkPath)) {
            return [
                'status' => 'failed',
                'link' => $linkPath,
                'target' => $relativeTarget,
                'message' => 'Impossible de créer le lien symbolique public/uploads (le fallback PHP GET /uploads/{path} reste actif).',
            ];
        }

        return [
            'status' => 'created',
            'link' => $linkPath,
            'target' => $relativeTarget,
            'message' => 'Lien symbolique public/uploads -> ../content/uploads créé avec succès.',
        ];
    }

    /**
     * Canonical Apache `.htaccess` written inside `content/uploads/`.
     */
    public static function htaccessContent(): string
    {
        return <<<'HTACCESS'
# Prime CMS — Hardened uploads directory (auto-generated)
# Never execute PHP, CGI, or scripts from user-uploaded files.

Options -Indexes -ExecCGI

<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php8.c>
    php_flag engine off
</IfModule>

<FilesMatch "\.(?i:php|php[3-8]|phtml|phar|phps|pht|shtml|cgi|pl|py|rb|sh|bash|exe|bat|cmd|svg|svgz|html|htm|xhtml)$">
    SetHandler default-handler
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
</FilesMatch>

<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set Content-Security-Policy "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox"
    Header always set Cache-Control "public, max-age=31536000, immutable"

    # Force download for non-(image, PDF, audio, video) files
    <FilesMatch "\.(?i:zip|docx|xlsx|pptx|odt|ods|txt|csv)$">
        Header always set Content-Disposition "attachment"
    </FilesMatch>
</IfModule>
HTACCESS . "\n";
    }
}
