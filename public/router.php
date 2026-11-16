<?php

declare(strict_types=1);

/**
 * Router script for PHP's built-in development server:
 *
 *   php -S localhost:8080 -t public public/router.php
 *
 * Ensures non-existent file-like URLs (notably `/uploads/YYYY/MM/file.ext` when
 * the `public/uploads` symlink is absent) are routed to `public/index.php` instead
 * of PHP's built-in server returning its own 404.
 */

$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$rawPath = (string) (parse_url($requestUri, PHP_URL_PATH) ?: '/');
$decodedPath = rawurldecode($rawPath);

// Block null bytes and hidden files/segments (e.g. /.htaccess, /uploads/.htaccess, /.env, /..)
if (str_contains($decodedPath, "\0") || preg_match('#(^|/)\.#', $decodedPath) === 1) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Forbidden';

    return true;
}

if ($decodedPath !== '/') {
    $candidate = __DIR__ . $decodedPath;
    if (is_file($candidate)) {
        $ext = strtolower((string) pathinfo($candidate, PATHINFO_EXTENSION));

        // Never execute or serve scripts/HTML/SVG directly from /uploads/
        if (
            str_starts_with($decodedPath, '/uploads/')
            && preg_match('/^(?:php|php[3-8]|phtml|phar|phps|pht|shtml|cgi|pl|py|rb|sh|bash|exe|svg|svgz|html|htm|xhtml)$/', $ext) === 1
        ) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Forbidden';

            return true;
        }

        // Let PHP's built-in server serve existing static assets in public/
        if ($ext !== 'php') {
            $realCandidate = realpath($candidate);
            $realPublic = realpath(__DIR__);
            $realUploads = realpath(dirname(__DIR__) . '/content/uploads');

            $insidePublic = $realCandidate !== false && $realPublic !== false
                && str_starts_with($realCandidate, $realPublic . DIRECTORY_SEPARATOR);
            $insideUploads = $realCandidate !== false && $realUploads !== false
                && str_starts_with($realCandidate, $realUploads . DIRECTORY_SEPARATOR);

            if ($insidePublic || $insideUploads) {
                return false;
            }
        }
    }
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
