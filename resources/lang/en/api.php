<?php

declare(strict_types=1);

return [
    'errors' => [
        'unauthenticated' => 'Authentication required.',
        'forbidden' => 'Access denied.',
        'not_found' => 'Resource not found.',
        'invalid_request' => 'Invalid request.',
        'method_not_allowed' => 'Method not allowed.',
        'server_error' => 'Internal server error.',
        'invalid_status' => 'Unknown status.',
        'rate_limited' => 'Too many requests. Please try again later.',
        'public_read_required' => 'Authentication required to access this content.',
        'validation_failed' => 'Validation failed.',
        'unsupported_media_type' => 'Request body must be JSON.',
        'invalid_json' => 'Request body is invalid JSON.',
        'payload_too_large' => 'Request body exceeds the maximum allowed size.',
        'csrf_mismatch' => 'CSRF token missing or invalid.',
        'precondition_failed' => 'Precondition (If-Match) failed.',
        'conflict' => 'Conflict: resource is locked or already modified.',
    ],
];
