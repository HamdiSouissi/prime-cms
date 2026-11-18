<?php

declare(strict_types=1);

namespace Prime\Api\Middleware;

use Prime\Http\Middleware\MiddlewareInterface;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Settings\Settings;
use JsonException;

/**
 * Valide le corps des requêtes API.
 *
 * - POST et PATCH exigent un corps JSON (`Content-Type: application/json`),
 *   sinon 415 `unsupported_media_type`.
 * - JSON invalide → 400 `invalid_json`.
 * - Profondeur JSON limitée à 32 niveaux.
 * - Taille maximale `api.max_body_kb` (ko), au-delà 413 `payload_too_large`.
 *
 * Le JSON décodé est stocké dans l'attribut `api.body` de la requête pour les
 * contrôleurs. Les erreurs de validation applicatives (422 `validation_failed`)
 * sont produites par les contrôleurs via `ApiController::validationFailed()`.
 */
final class ApiBody implements MiddlewareInterface
{
    private const MAX_DEPTH = 32;

    public function __construct(private readonly Settings $settings)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $method = strtoupper((string) $request->method());

        // Seuls POST et PATCH exigent un corps JSON.
        if ($method !== 'POST' && $method !== 'PATCH') {
            return $next($request);
        }

        $contentType = $request->header('content-type') ?? '';
        if (!str_contains((string) $contentType, 'application/json')) {
            return $this->jsonError('unsupported_media_type', 415);
        }

        $raw = $request->rawBody();
        if ($raw === '' || $raw === null) {
            return $this->jsonError('invalid_json', 400);
        }

        // Taille maximale du corps.
        $maxKb = (int) $this->settings->getInt('api.max_body_kb', 128);
        if ($maxKb > 0 && strlen($raw) > $maxKb * 1024) {
            return $this->jsonError('payload_too_large', 413);
        }

        // Décodage JSON avec limitation de profondeur.
        $depth = 0;
        try {
            $decoded = $this->decodeJsonLimited((string) $raw, $depth);
        } catch (JsonException) {
            return $this->jsonError('invalid_json', 400);
        }

        if ($decoded === null || $depth > self::MAX_DEPTH) {
            return $this->jsonError('invalid_json', 400);
        }

        // Stocke le corps décodé dans un attribut de la requête.
        $request = $request->withAttribute('api.body', $decoded);

        return $next($request);
    }

    /**
     * Décode un JSON en limitant la profondeur de récursion.
     *
     * @return array<string, mixed>|list<mixed>|string|int|float|bool|null
     * @throws JsonException
     */
    private function decodeJsonLimited(string $json, int &$depth): mixed
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if ($decoded === null) {
            return null;
        }

        $depth = $this->maxDepth($decoded);

        return $decoded;
    }

    /**
     * Calcule la profondeur maximale d'une valeur décodée.
     *
     * @param mixed $value
     */
    private function maxDepth(mixed $value, int $current = 0): int
    {
        if (is_array($value)) {
            if ($value === []) {
                return $current + 1;
            }

            $max = $current + 1;
            foreach ($value as $item) {
                $itemDepth = $this->maxDepth($item, $current + 1);
                if ($itemDepth > $max) {
                    $max = $itemDepth;
                }
            }

            return $max;
        }

        return $current;
    }

    private function jsonError(string $code, int $status): Response
    {
        return Response::json([
            'error' => [
                'code' => $code,
                'message' => $this->errorMessage($code),
                'status' => $status,
            ],
        ], $status, [
            'Content-Type' => 'application/json',
        ]);
    }

    private function errorMessage(string $code): string
    {
        return match ($code) {
            'unsupported_media_type' => 'Le corps de la requête doit être au format JSON.',
            'invalid_json' => 'Le corps de la requête est un JSON invalide.',
            'payload_too_large' => 'Le corps de la requête dépasse la taille maximale autorisée.',
            default => 'Erreur de requête.',
        };
    }
}
