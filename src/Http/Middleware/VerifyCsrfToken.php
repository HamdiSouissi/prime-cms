<?php

declare(strict_types=1);

namespace Prime\Http\Middleware;

use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Security\Csrf;
use Prime\View\View;
use Throwable;

/**
 * Enforces CSRF token verification on all state-changing HTTP methods
 * (POST, PUT, PATCH, DELETE), returning HTTP 419 on missing or invalid tokens.
 */
final class VerifyCsrfToken implements MiddlewareInterface
{
    /**
     * @var list<string>
     */
    private const PROTECTED_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly Csrf $csrf,
        private readonly ?View $view = null,
    ) {
    }

    /**
     * @param callable(Request): Response $next
     */
    public function process(Request $request, callable $next): Response
    {
        // L'API gère son propre CSRF : ignorer toutes les routes sous /api/.
        if (str_starts_with($request->path(), '/api/')) {
            return $next($request);
        }

        if (!in_array($request->method(), self::PROTECTED_METHODS, true)) {
            return $next($request);
        }

        $token = $this->csrf->extractFromRequest($request);
        if ($this->csrf->validate($token)) {
            return $next($request);
        }

        if ($request->wantsJson()) {
            return Response::json([
                'error' => [
                    'code' => 419,
                    'message' => 'Jeton CSRF invalide ou session expirée.',
                ],
            ], 419);
        }

        if ($this->view !== null) {
            try {
                $html = $this->view->render('errors.419', [
                    'path' => $request->path(),
                ]);

                return Response::html($html, 419);
            } catch (Throwable) {
                // Fallback below if template rendering fails.
            }
        }

        return Response::html(
            '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>419 — Session expirée</title></head><body><h1>Session expirée</h1><p>Le jeton de sécurité du formulaire est invalide ou expiré. Rechargez la page et réessayez.</p></body></html>',
            419,
        );
    }
}
