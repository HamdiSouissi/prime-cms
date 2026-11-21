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
        'unsupported_media_type' => 'The request body must be sent as JSON (Content-Type: application/json).',
        'invalid_json' => 'The request body is not valid JSON.',
        'payload_too_large' => 'The request body exceeds the allowed size.',
        'validation_failed' => 'Some fields are invalid.',
        'conflict' => 'The resource is locked or in a state that does not allow this operation.',
        'precondition_failed' => 'The resource changed since you last read it (If-Match).',
        'csrf_mismatch' => 'Missing or invalid X-Prime-CSRF header.',
    ],
    'validation' => [
        'file_missing' => 'No file was sent (field "file").',
        'setting_unknown' => 'This setting does not exist or cannot be changed.',
        'display_name' => 'The display name is required (191 characters maximum).',
        'locale' => 'This language is not available.',
    ],
];
