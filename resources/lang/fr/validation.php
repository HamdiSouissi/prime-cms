<?php

declare(strict_types=1);

/**
 * Messages d'erreur du validateur (`Prime\Validation\Validator`).
 *
 * Les variantes `min_*`, `max_*` et `between_*` couvrent les comparaisons numériques,
 * textuelles et par nombre d'éléments.
 */
return [
    'array' => 'Le champ « :label » doit être une liste.',
    'between' => 'Le champ « :label » doit être compris entre :min et :max.',
    'between_numeric' => 'Le champ « :label » doit être compris entre :min et :max.',
    'between_string' => 'Le champ « :label » doit contenir entre :min et :max caractères.',
    'boolean' => 'Le champ « :label » doit être vrai ou faux.',
    'confirmed' => 'La confirmation du champ « :label » ne correspond pas.',
    'date' => 'Le champ « :label » doit contenir une date valide.',
    'email' => 'Saisissez une adresse e-mail valide.',
    'exists' => 'La valeur sélectionnée pour « :label » est introuvable.',
    'in' => 'La valeur sélectionnée pour « :label » n’est pas valide.',
    'integer' => 'Le champ « :label » doit être un nombre entier.',
    'json' => 'Le champ « :label » doit contenir du JSON valide.',
    'matching_field' => '« :label » est requis lorsque :other est renseigné.',
    'max' => 'Le champ « :label » ne doit pas dépasser :max.',
    'max_array' => 'Le champ « :label » ne doit pas dépasser :max élément(s).',
    'max_numeric' => 'Le champ « :label » ne doit pas dépasser :max.',
    'max_string' => 'Le champ « :label » ne doit pas dépasser :max caractères.',
    'min' => 'Le champ « :label » doit comporter au moins :min.',
    'min_array' => 'Sélectionnez au moins :min élément(s) pour « :label ».',
    'min_numeric' => 'Le champ « :label » doit être supérieur ou égal à :min.',
    'min_string' => 'Le champ « :label » doit contenir au moins :min caractères.',
    'numeric' => 'Le champ « :label » doit être un nombre.',
    'origin' => 'La ligne « :value » du champ « :label » n’est pas une origine valide (ex. https://app.exemple.fr, sans chemin).',
    'password' => 'Le mot de passe est invalide.',
    'password_personal' => 'Ce mot de passe reprend des informations de votre compte.',
    'password_policy' => [
        'common' => 'Ce mot de passe est trop courant. Combinez plusieurs mots inhabituels ou utilisez un gestionnaire de mots de passe.',
        'describe' => [
            'min_length' => 'Au moins :min caractères.',
            'mixed_case' => 'Une majuscule et une minuscule.',
            'number' => 'Au moins un chiffre.',
            'personal' => 'Ni mot de passe courant, ni nom, ni e-mail.',
            'special' => 'Au moins un caractère spécial.',
        ],
        'display_name' => 'Le mot de passe ne doit pas reprendre votre nom affiché. Choisissez une combinaison distincte.',
        'email' => 'Le mot de passe ne doit pas contenir votre adresse e-mail.',
        'email_similar' => 'Le mot de passe est trop proche de votre adresse e-mail. Choisissez une phrase secrète distincte.',
        'max_length' => 'Le mot de passe ne peut pas dépasser :max caractères.',
        'min_length' => 'Le mot de passe doit contenir au moins :min caractères (ajoutez des mots, chiffres ou symboles).',
        'mixed_case' => 'Le mot de passe doit contenir au moins une majuscule et une minuscule.',
        'name_token' => 'Le mot de passe ne doit pas contenir votre nom. Choisissez une phrase secrète distincte.',
        'number' => 'Le mot de passe doit contenir au moins un chiffre.',
        'repetition' => 'Choisissez un mot de passe plus varié plutôt qu\'un caractère répété.',
        'site_name' => 'Le mot de passe ne doit pas reprendre le nom du site. Choisissez un mot de passe unique.',
        'special' => 'Le mot de passe doit contenir au moins un caractère spécial.',
    ],
    'password_weak' => 'Ce mot de passe est trop simple : choisissez-en un plus robuste.',
    'regex' => 'Le format du champ « :label » est invalide.',
    'required' => 'Le champ « :label » est obligatoire.',
    'slug' => 'Le champ « :label » ne peut contenir que des lettres minuscules, chiffres et tirets.',
    'string' => 'Le champ « :label » doit être une chaîne de caractères.',
    'unique' => 'Cette valeur pour « :label » est déjà utilisée.',
    'url' => 'Le champ « :label » doit être une URL valide.',
];
