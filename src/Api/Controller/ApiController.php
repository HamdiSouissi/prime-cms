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
     * La clé est traduite via le catalogue `api.errors.{key}` dans la langue donnée
     * (ou la langue par défaut du site).
     *
     * Format : {"error": {"code": "...", "message": "...", "status": N}}
     *
     * @param array<string, mixed> $replace
     * @param array<string, string> $headers
     */
    protected function error(string $key, array $replace = [], int $status = 400, ?string $code = null, ?string $locale = null, array $headers = []): Response
    {
        $code = $code ?? $key;
        $message = t('api.errors.' . $key, $replace, $locale);

        return Response::json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'status' => $status,
            ],
        ], $status, $headers);
    }

    /**
     * Retourne une réponse JSON 422 d'erreur de validation.
     *
     * Format : {"error": {"code": "validation_failed", "message": "...",
     * "status": 422, "fields": {champ: message traduit}}}
     *
     * @param array<string, string> $fields  Champ => message d'erreur traduit
     * @param array<string, string> $headers
     */
    protected function validationFailed(array $fields, array $headers = []): Response
    {
        return Response::json([
            'error' => [
                'code' => 'validation_failed',
                'message' => t('api.errors.validation_failed', [], 'fr'),
                'status' => 422,
                'fields' => $fields,
            ],
        ], 422, array_merge([
            'Content-Type' => 'application/json',
        ], $headers));
    }

    /**
     * Retourne une réponse JSON 401 non authentifié.
     *
     * @param array<string, mixed> $replace
     * @param array<string, string> $headers
     */
    protected function unauthorized(string $key = 'unauthenticated', array $replace = [], ?string $locale = null, array $headers = []): Response
    {
        return $this->error($key, $replace, 401, 'unauthenticated', $locale, $headers);
    }

    /**
     * Retourne une réponse JSON 403 interdit.
     *
     * @param array<string, mixed> $replace
     * @param array<string, string> $headers
     */
    protected function forbidden(string $key = 'forbidden', array $replace = [], ?string $locale = null, array $headers = []): Response
    {
        return $this->error($key, $replace, 403, 'forbidden', $locale, $headers);
    }

    /**
     * Retourne une réponse JSON 404 non trouvé.
     *
     * @param array<string, mixed> $replace
     * @param array<string, string> $headers
     */
    protected function notFound(string $key = 'not_found', array $replace = [], ?string $locale = null, array $headers = []): Response
    {
        return $this->error($key, $replace, 404, 'not_found', $locale, $headers);
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

        return $user !== null ? true : $this->unauthorized();
    }
}
