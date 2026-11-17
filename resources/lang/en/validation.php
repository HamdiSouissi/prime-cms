<?php

declare(strict_types=1);

/**
 * Validator error messages (`Prime\Validation\Validator`).
 *
 * The `min_*`, `max_*` and `between_*` variants cover numeric, string and item-count comparisons.
 */
return [
    'required' => 'The :label field is required.',
    'string' => 'The :label field must be a text value.',
    'email' => 'Please enter a valid email address.',
    'integer' => 'The :label field must be a whole number.',
    'boolean' => 'The :label field must be true or false.',
    'numeric' => 'The :label field must be a number.',
    'url' => 'The :label field must be a valid URL.',
    'slug' => 'The :label field may only contain lowercase letters, numbers and hyphens.',
    'confirmed' => 'The :label confirmation does not match.',
    'in' => 'The selected value for :label is not valid.',
    'regex' => 'The :label field has an invalid format.',
    'unique' => 'That :label is already in use.',
    'exists' => 'The selected :label could not be found.',
    'date' => 'The :label field must contain a valid date.',
    'password' => 'The password is not valid.',
    'password_weak' => 'This password is too simple: choose a stronger one.',
    'password_personal' => 'This password contains information from your account.',
    'password_policy' => [
        'describe' => [
            'min_length' => 'At least :min characters.',
            'mixed_case' => 'One uppercase and one lowercase letter.',
            'number' => 'At least one number.',
            'special' => 'At least one special character.',
            'personal' => 'No common password, no name, no e-mail.',
        ],
        'min_length' => 'The password must contain at least :min characters (add words, numbers or symbols).',
        'mixed_case' => 'The password must contain at least one uppercase and one lowercase letter.',
        'number' => 'The password must contain at least one number.',
        'special' => 'The password must contain at least one special character.',
        'max_length' => 'The password cannot exceed :max characters.',
        'repetition' => 'Choose a more varied password instead of repeating one character.',
        'common' => 'This password is too common. Combine several unusual words or use a password manager.',
        'email' => 'The password must not contain your e-mail address.',
        'email_similar' => 'The password is too close to your e-mail address. Choose a distinct passphrase.',
        'display_name' => 'The password must not reuse your display name. Choose a distinct combination.',
        'name_token' => 'The password must not contain your name. Choose a distinct passphrase.',
        'site_name' => 'The password must not reuse the site name. Choose a unique password.',
    ],
    'min' => 'The :label field must be at least :min.',
    'min_numeric' => 'The :label field must be greater than or equal to :min.',
    'min_string' => 'The :label field must contain at least :min characters.',
    'min_array' => 'Please select at least :min item(s) for :label.',
    'max' => 'The :label field may not be greater than :max.',
    'max_numeric' => 'The :label field may not be greater than :max.',
    'max_string' => 'The :label field may not be longer than :max characters.',
    'max_array' => 'The :label field may not contain more than :max item(s).',
    'between' => 'The :label field must be between :min and :max.',
    'between_numeric' => 'The :label field must be between :min and :max.',
    'between_string' => 'The :label field must contain between :min and :max characters.',
    'array' => 'The :label field must be a list.',
    'json' => 'The :label field must contain valid JSON.',
    'matching_field' => 'The :label field is required when :other is provided.',
];
