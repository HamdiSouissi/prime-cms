<?php

declare(strict_types=1);

/**
 * Messages d'erreur du validateur (`Prime\Validation\Validator`).
 *
 * Les variantes `min_*`, `max_*` et `between_*` couvrent les comparaisons numériques,
 * textuelles et par nombre d'éléments.
 */
return [
    'required' => 'Le champ « :label » est obligatoire.',
    'string' => 'Le champ « :label » doit être une chaîne de caractères.',
    'email' => 'Saisissez une adresse e-mail valide.',
    'integer' => 'Le champ « :label » doit être un nombre entier.',
    'boolean' => 'Le champ « :label » doit être vrai ou faux.',
    'numeric' => 'Le champ « :label » doit être un nombre.',
    'url' => 'Le champ « :label » doit être une URL valide.',
    'slug' => 'Le champ « :label » ne peut contenir que des lettres minuscules, chiffres et tirets.',
    'confirmed' => 'La confirmation du champ « :label » ne correspond pas.',
    'in' => 'La valeur sélectionnée pour « :label » n’est pas valide.',
    'regex' => 'Le format du champ « :label » est invalide.',
    'unique' => 'Cette valeur pour « :label » est déjà utilisée.',
    'exists' => 'La valeur sélectionnée pour « :label » est introuvable.',
    'date' => 'Le champ « :label » doit contenir une date valide.',
    'password' => 'Le mot de passe est invalide.',
    'password_weak' => 'Ce mot de passe est trop simple : choisissez-en un plus robuste.',
    'password_personal' => 'Ce mot de passe reprend des informations de votre compte.',
    'min' => 'Le champ « :label » doit comporter au moins :min.',
    'min_numeric' => 'Le champ « :label » doit être supérieur ou égal à :min.',
    'min_string' => 'Le champ « :label » doit contenir au moins :min caractères.',
    'min_array' => 'Sélectionnez au moins :min élément(s) pour « :label ».',
    'max' => 'Le champ « :label » ne doit pas dépasser :max.',
    'max_numeric' => 'Le champ « :label » ne doit pas dépasser :max.',
    'max_string' => 'Le champ « :label » ne doit pas dépasser :max caractères.',
    'max_array' => 'Le champ « :label » ne doit pas dépasser :max élément(s).',
    'between' => 'Le champ « :label » doit être compris entre :min et :max.',
    'between_numeric' => 'Le champ « :label » doit être compris entre :min et :max.',
    'between_string' => 'Le champ « :label » doit contenir entre :min et :max caractères.',
    'array' => 'Le champ « :label » doit être une liste.',
    'json' => 'Le champ « :label » doit contenir du JSON valide.',
    'matching_field' => '« :label » est requis lorsque :other est renseigné.',
];
