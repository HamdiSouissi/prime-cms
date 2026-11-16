<?php

declare(strict_types=1);

namespace Prime\Media;

use finfo;
use Prime\Http\Exception\ForbiddenException;
use Prime\Http\Exception\NotFoundException;
use Prime\Http\Request;
use Prime\Http\Response;

/**
 * PHP fallback controller serving uploaded media at `GET|HEAD /uploads/{path:.+}`
 * when the `public/uploads` symlink is absent or bypassed.
 *
 * Enforces strict path traversal protection, extension allowlisting, sandbox CSP,
 * `X-Content-Type-Options: nosniff`, immutable caching with ETag/Last-Modified 304,
 * HTTP Range 206 partial content, and `Content-Disposition: attachment` for non-inline files.
 */
final class MediaServingController
{
    private const UPLOAD_CSP = "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox";

    /**
     * Extensions safe to display inline inside the browser (all other allowed types force download).
     *
     * @var list<string>
     */
    private const INLINE_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif',
        'pdf',
        'mp3', 'ogg', 'wav', 'm4a',
        'mp4', 'webm',
    ];

    /**
     * Canonical MIME map for safe serving (never serving `text/html`).
     *
     * @var array<string, string>
     */
    private const CANONICAL_MIMES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'txt' => 'text/plain; charset=UTF-8',
        'csv' => 'text/csv; charset=UTF-8',
        'mp3' => 'audio/mpeg',
        'ogg' => 'audio/ogg',
        'wav' => 'audio/wav',
        'm4a' => 'audio/mp4',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'zip' => 'application/zip',
    ];

    public function __construct(
        private readonly MediaConfig $config,
    ) {
    }

    public function serve(Request $request, string $path): Response
    {
        $decodedPath = rawurldecode($path);

        // 1. Strict path traversal and hidden-file guard
        if (
            $decodedPath === ''
            || str_contains($decodedPath, "\0")
            || str_contains($decodedPath, '\\')
            || str_contains($decodedPath, '..')
        ) {
            throw new ForbiddenException('Accès refusé.');
        }

        $segments = explode('/', ltrim($decodedPath, '/'));
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
                throw new ForbiddenException('Accès refusé.');
            }
        }

        // 2. Strict allowed extension check (configured allowlist + webp variants)
        $filename = $segments[count($segments) - 1];
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowedMap = $this->config->allowedExtensionMap();
        if ($extension === '' || (!isset($allowedMap[$extension]) && $extension !== 'webp')) {
            throw new ForbiddenException('Type de fichier non autorisé.');
        }

        // 3. Resolve real path strictly inside `content/uploads/`
        $uploadsRoot = realpath($this->config->uploadsAbsolutePath());
        if ($uploadsRoot === false || !is_dir($uploadsRoot)) {
            throw new NotFoundException('Fichier introuvable.');
        }

        $candidate = $uploadsRoot . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
        $resolved = realpath($candidate);

        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
            throw new NotFoundException('Fichier introuvable.');
        }

        if (!str_starts_with($resolved, $uploadsRoot . DIRECTORY_SEPARATOR)) {
            throw new ForbiddenException('Accès refusé.');
        }

        $size = filesize($resolved);
        $mtime = filemtime($resolved);
        if ($size === false || $mtime === false) {
            throw new NotFoundException('Fichier introuvable.');
        }

        $contentType = $this->resolveContentType($resolved, $extension);
        $etag = sprintf('"%x-%x"', $mtime, $size);
        $lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

        $headers = [
            'Content-Type' => $contentType,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => self::UPLOAD_CSP,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => $etag,
            'Last-Modified' => $lastModified,
            'Accept-Ranges' => 'bytes',
        ];

        if (!in_array($extension, self::INLINE_EXTENSIONS, true)) {
            $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename) ?? 'download';
            $headers['Content-Disposition'] = sprintf('attachment; filename="%s"', $safeFilename);
        }

        // 4. Conditional GET (304 Not Modified)
        if ($this->isNotModified($request, $etag, $mtime)) {
            return new Response('', 304, $headers);
        }

        $isHead = $request->isMethod('HEAD');

        // 5. HTTP Range support (206 Partial Content / 416 Range Not Satisfiable)
        $rangeHeader = $request->header('range');
        if ($rangeHeader !== null && trim($rangeHeader) !== '' && $size > 0) {
            $range = $this->parseRangeHeader($rangeHeader, $size);
            if ($range === null) {
                return new Response('', 416, array_merge($headers, [
                    'Content-Range' => sprintf('bytes */%d', $size),
                ]));
            }

            [$start, $end] = $range;
            $length = ($end - $start) + 1;
            $headers['Content-Range'] = sprintf('bytes %d-%d/%d', $start, $end, $size);
            $headers['Content-Length'] = (string) $length;

            if ($isHead) {
                return new Response('', 206, $headers);
            }

            $slice = $this->readFileRange($resolved, $start, $length);

            return new Response($slice, 206, $headers);
        }

        // 6. Full response (200 OK)
        $headers['Content-Length'] = (string) $size;
        if ($isHead) {
            return new Response('', 200, $headers);
        }

        $body = @file_get_contents($resolved);
        if (!is_string($body)) {
            throw new NotFoundException('Fichier illisible.');
        }

        return new Response($body, 200, $headers);
    }

    private function resolveContentType(string $path, string $extension): string
    {
        if (isset(self::CANONICAL_MIMES[$extension])) {
            return self::CANONICAL_MIMES[$extension];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($path);
        if (is_string($detected) && $detected !== '' && !str_contains(strtolower($detected), 'html')) {
            return $detected;
        }

        return 'application/octet-stream';
    }

    private function isNotModified(Request $request, string $etag, int $mtime): bool
    {
        $ifNoneMatch = $request->header('if-none-match');
        if ($ifNoneMatch !== null && trim($ifNoneMatch) !== '') {
            $candidates = array_map('trim', explode(',', $ifNoneMatch));
            foreach ($candidates as $candidate) {
                if ($candidate === '*' || $candidate === $etag || $candidate === 'W/' . $etag) {
                    return true;
                }
            }

            return false;
        }

        $ifModifiedSince = $request->header('if-modified-since');
        if ($ifModifiedSince !== null && trim($ifModifiedSince) !== '') {
            $sinceTs = strtotime($ifModifiedSince);
            if ($sinceTs !== false && $mtime <= $sinceTs) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse a single HTTP `Range: bytes=start-end` header against `$fileSize`.
     *
     * @return array{0: int, 1: int}|null Null when the range is malformed or unsatisfiable
     */
    private function parseRangeHeader(string $header, int $fileSize): ?array
    {
        if (preg_match('/^bytes=(\d*)-(\d*)$/i', trim($header), $m) !== 1) {
            return null;
        }

        $rawStart = $m[1];
        $rawEnd = $m[2];

        if ($rawStart === '' && $rawEnd === '') {
            return null;
        }

        if ($rawStart === '') {
            // Suffix range: last N bytes
            $suffix = (int) $rawEnd;
            if ($suffix <= 0) {
                return null;
            }
            $start = max(0, $fileSize - $suffix);
            $end = $fileSize - 1;

            return [$start, $end];
        }

        $start = (int) $rawStart;
        $end = $rawEnd !== '' ? min((int) $rawEnd, $fileSize - 1) : ($fileSize - 1);

        if ($start < 0 || $start >= $fileSize || $end < $start) {
            return null;
        }

        return [$start, $end];
    }

    private function readFileRange(string $path, int $start, int $length): string
    {
        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            throw new NotFoundException('Fichier illisible.');
        }

        try {
            if (fseek($fp, $start) !== 0) {
                return '';
            }
            $data = '';
            $remaining = $length;
            while ($remaining > 0 && !feof($fp)) {
                $chunk = fread($fp, min(65536, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $data .= $chunk;
                $remaining -= strlen($chunk);
            }

            return $data;
        } finally {
            fclose($fp);
        }
    }
}
