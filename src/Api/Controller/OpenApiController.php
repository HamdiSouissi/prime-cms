<?php

declare(strict_types=1);

namespace Prime\Api\Controller;

use Prime\Api\OpenApiDocument;
use Prime\Http\Request;
use Prime\Http\Response;

/**
 * `GET /api/v1/openapi.json` — machine-readable description of the operations available
 * to the caller (core and plugins).
 */
final class OpenApiController extends ApiController
{
    public function __construct(private readonly OpenApiDocument $document)
    {
    }

    public function show(Request $request): Response
    {
        return Response::json($this->document->build($this->currentUser($request)), 200, [
            'Cache-Control' => $this->currentUser($request) !== null ? 'private, no-store' : 'public, max-age=300',
        ]);
    }
}
