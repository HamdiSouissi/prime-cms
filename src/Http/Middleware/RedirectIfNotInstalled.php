<?php

declare(strict_types=1);

namespace Prime\Http\Middleware;

use Prime\Http\Exception\NotFoundException;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Http\Session;
use Prime\Install\InstallCheckerInterface;
use Prime\Kernel\Application;

/**
 * Redirects every non-installer request to `/install` before installation is complete,
 * and returns 404 for all `/install*` routes once Prime CMS is installed.
 */
final class RedirectIfNotInstalled implements MiddlewareInterface
{
    public const COMPLETION_FLASH_KEY = 'install_just_completed';

    public function __construct(
        private readonly InstallCheckerInterface $checker,
        private readonly Application $app,
        private readonly Session $session,
    ) {
    }

    /**
     * @param callable(Request): Response $next
     */
    public function process(Request $request, callable $next): Response
    {
        $path = $request->path();
        $isInstallRoute = $path === '/install' || str_starts_with($path, '/install/');

        if ($this->checker->isInstalled()) {
            if ($isInstallRoute) {
                // Allow the one-time Step 7 confirmation right after finishing Step 6.
                if (
                    $path === '/install/done'
                    && $request->isMethod('GET')
                    && $this->session->hasFlash(self::COMPLETION_FLASH_KEY)
                ) {
                    return $next($request);
                }

                throw new NotFoundException('Page introuvable.');
            }

            return $next($request);
        }

        if ($isInstallRoute || $path === '/health' || $this->isExistingPublicAsset($path)) {
            return $next($request);
        }

        return Response::redirect('/install', 302);
    }

    private function isExistingPublicAsset(string $path): bool
    {
        if ($path === '/' || str_contains($path, '..') || str_contains($path, "\0")) {
            return false;
        }

        $publicRoot = realpath($this->app->publicPath());
        if ($publicRoot === false) {
            return false;
        }

        $candidate = realpath($publicRoot . DIRECTORY_SEPARATOR . ltrim($path, '/'));
        if ($candidate === false || !is_file($candidate)) {
            return false;
        }

        if (!str_starts_with($candidate, $publicRoot . DIRECTORY_SEPARATOR)) {
            return false;
        }

        $basename = basename($candidate);

        // Never treat PHP entry scripts or Apache config as static assets.
        return $basename !== 'index.php' && $basename !== '.htaccess' && !str_ends_with(strtolower($basename), '.php');
    }
}
