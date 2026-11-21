<?php

declare(strict_types=1);

namespace Prime\Api;

use Prime\Auth\User;
use Prime\Http\Request;
use Prime\Http\Response;

/**
 * Builds the single error format of the REST API:
 * `{"error": {"code": "snake_case", "message": "…", "status": 404, …extra}}`.
 *
 * The message comes from the `api.errors.{code}` catalogue entry, in the language of the
 * authenticated caller when there is one, otherwise in the site language.
 */
final class ApiError
{
    /**
     * @param array<string, mixed>  $extra   Additional members of the error object (e.g. `fields`)
     * @param array<string, string> $headers
     */
    public static function response(string $code, int $status, ?Request $request = null, array $extra = [], array $headers = []): Response
    {
        return Response::json([
            'error' => array_merge([
                'code' => $code,
                'message' => t('api.errors.' . $code, [], self::locale($request)),
                'status' => $status,
            ], $extra),
        ], $status, $headers);
    }

    public static function locale(?Request $request): ?string
    {
        $user = $request?->attribute('api.user');

        return $user instanceof User && $user->locale !== '' ? $user->locale : null;
    }
}
