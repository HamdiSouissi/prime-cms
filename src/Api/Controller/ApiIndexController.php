<?php

declare(strict_types=1);

namespace Prime\Api\Controller;

use Prime\Http\Request;
use Prime\Http\Response;

/**
 * `GET /api/v1` — Point d'entrée public de l'API REST.
 */
final class ApiIndexController extends ApiController
{
    public function __invoke(Request $request): Response
    {
        $authenticated = $request->attribute('api.user') !== null;

        return $this->json([
            'data' => [
                'api_version' => 'v1',
                'authenticated' => $authenticated,
            ],
        ]);
    }
}
