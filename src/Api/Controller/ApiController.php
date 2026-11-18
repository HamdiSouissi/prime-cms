<?php

declare(strict_types=1);

namespace Prime\Api\Controller;

use Prime\Auth\User;
use Prime\Http\Request;
use Prime\Http\Response;

/**
 * Contrôleur de base pour l'API REST Prime CMS.
 *
 * Fournit des helpers pour retourner des réponses JSON cohérentes (succès, erreur, validation).
 */
abstract class ApiController
{
    /**
     * Retourne une réponse JSON de succès.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    protected function json(array $data, int $status = 200, array $headers = []): Response
    {
        return Response::json($data, $status, $headers);
    }

    /**
     * Retourne une réponse JSON d'erreur au format standard.
     *
     * Format : {"error": {"code": "...", "message": "...", "status": N}}
     *
     * @param array<string, string> $headers
     */
    protected function error(string $message, int $status = 400, string $code = 'invalid_request', array $headers = []): Response
    {
        return Response::json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'status' => $status,
            ],
        ], $status, $headers);
    }

    /**
     * Retourne une réponse JSON 401 non authentifié.
     *
     * @param array<string, string> $headers
     */
    protected function unauthorized(string $message = 'Authentification requise.', array $headers = []): Response
    {
        return $this->error($message, 401, 'unauthenticated', $headers);
    }

    /**
     * Retourne une réponse JSON 403 interdit.
     *
     * @param array<string, string> $headers
     */
    protected function forbidden(string $message = 'Accès interdit.', array $headers = []): Response
    {
        return $this->error($message, 403, 'forbidden', $headers);
    }

    /**
     * Retourne une réponse JSON 404 non trouvé.
     *
     * @param array<string, string> $headers
     */
    protected function notFound(string $message = 'Ressource introuvable.', array $headers = []): Response
    {
        return $this->error($message, 404, 'not_found', $headers);
    }

    /**
     * Retourne une réponse JSON 204 No Content.
     *
     * @param array<string, string> $headers
     */
    protected function noContent(array $headers = []): Response
    {
        return Response::noContent($headers);
    }

    /**
     * Extrait l'utilisateur courant depuis la requête (attribut défini par le middleware d'auth API).
     */
    protected function currentUser(Request $request): ?User
    {
        return $request->attribute('api.user');
    }

    /**
     * Vérifie que l'utilisateur courant est authentifié, sinon retourne une réponse 401.
     *
     * @return true|Response Retourne true si authentifié, une Response 401 sinon.
     */
    protected function requireAuth(Request $request): true|Response
    {
        $user = $this->currentUser($request);

        return $user !== null ? true : $this->unauthorized('Authentification requise.');
    }
}
