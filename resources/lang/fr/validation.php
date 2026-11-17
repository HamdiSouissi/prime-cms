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
    'password_policy' => [
        'describe' => [
            'min_length' => 'Au moins :min caractères.',
            'mixed_case' => 'Une majuscule et une minuscule.',
            'number' => 'Au moins un chiffre.',
            'special' => 'Au moins un caractère spécial.',
            'personal' => 'Ni mot de passe courant, ni nom, ni e-mail.',
        ],
        'min_length' => 'Le mot de passe doit contenir au moins :min caractères (ajoutez des mots, chiffres ou symboles).',
        'mixed_case' => 'Le mot de passe doit contenir au moins une majuscule et une minuscule.',
        'number' => 'Le mot de passe doit contenir au moins un chiffre.',
        'special' => 'Le mot de passe doit contenir au moins un caractère spécial.',
        'max_length' => 'Le mot de passe ne peut pas dépasser :max caractères.',
        'repetition' => 'Choisissez un mot de passe plus varié plutôt qu\'un caractère répété.',
        'common' => 'Ce mot de passe est trop courant. Combinez plusieurs mots inhabituels ou utilisez un gestionnaire de mots de passe.',
        'email' => 'Le mot de passe ne doit pas contenir votre adresse e-mail.',
        'email_similar' => 'Le mot de passe est trop proche de votre adresse e-mail. Choisissez une phrase secrète distincte.',
        'display_name' => 'Le mot de passe ne doit pas reprendre votre nom affiché. Choisissez une combinaison distincte.',
        'name_token' => 'Le mot de passe ne doit pas contenir votre nom. Choisissez une phrase secrète distincte.',
        'site_name' => 'Le mot de passe ne doit pas reprendre le nom du site. Choisissez un mot de passe unique.',
    ],
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
