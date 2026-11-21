<?php

declare(strict_types=1);

namespace Prime\Api\Middleware;

use JsonException;
use Prime\Api\ApiError;
use Prime\Api\ApiRoute;
use Prime\Http\Middleware\MiddlewareInterface;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Settings\Settings;

/**
 * Validates the request body of API routes (`api.body:<json|multipart|none>`).
 *
 * - The size limit (`api.max_body_kb`) is checked first: 413 `payload_too_large`.
 * - A JSON route needs `Content-Type: application/json` as soon as a body is sent: 415.
 * - The JSON must decode (depth 32) to an object or a list: otherwise 400 `invalid_json`.
 * - A multipart route accepts `multipart/form-data` only.
 *
 * The decoded payload is exposed as the `api.body` request attribute (an empty array when
 * no body was sent), so controllers never read the raw input themselves.
 */
final class ApiBody implements MiddlewareInterface
{
    private const MAX_DEPTH = 32;

    public function __construct(
        private readonly Settings $settings,
        private readonly string $mode = ApiRoute::BODY_NONE,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $method = strtoupper($request->method());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request->withAttribute('api.body', []));
        }

        $raw = $request->rawBody();
        $declared = (int) ($request->server('CONTENT_LENGTH') ?? 0);
        $size = max($declared, strlen($raw));
        $limitKb = max(16, $this->settings->getInt('api.max_body_kb', 1024));
        if ($size > $limitKb * 1024) {
            return ApiError::response('payload_too_large', 413, $request);
        }

        $contentType = strtolower(trim(explode(';', (string) ($request->header('content-type') ?? ''))[0]));

        if ($this->mode === ApiRoute::BODY_MULTIPART) {
            if ($contentType !== 'multipart/form-data') {
                return ApiError::response('unsupported_media_type', 415, $request);
            }

            return $next($request->withAttribute('api.body', $request->body()));
        }

        if (trim($raw) === '') {
            return $next($request->withAttribute('api.body', []));
        }

        if ($contentType !== 'application/json' && !str_ends_with($contentType, '+json')) {
            return ApiError::response('unsupported_media_type', 415, $request);
        }

        try {
            $decoded = json_decode($raw, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ApiError::response('invalid_json', 400, $request);
        }

        if (!is_array($decoded)) {
            return ApiError::response('invalid_json', 400, $request);
        }

        return $next($request->withAttribute('api.body', $decoded));
    }
}
