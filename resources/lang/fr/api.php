<?php

declare(strict_types=1);

return [
    'errors' => [
        'unauthenticated' => 'Authentification requise.',
        'forbidden' => 'Accès interdit.',
        'not_found' => 'Ressource introuvable.',
        'invalid_request' => 'Requête invalide.',
        'method_not_allowed' => 'Méthode non autorisée.',
        'server_error' => 'Erreur interne du serveur.',
        'invalid_status' => 'Statut inconnu.',
        'rate_limited' => 'Trop de requêtes. Veuillez réessayer ultérieurement.',
        'unsupported_media_type' => 'Le corps de la requête doit être envoyé en JSON (Content-Type: application/json).',
        'invalid_json' => 'Le corps de la requête n’est pas un JSON valide.',
        'payload_too_large' => 'Le corps de la requête dépasse la taille autorisée.',
        'validation_failed' => 'Certains champs sont invalides.',
        'conflict' => 'La ressource est verrouillée ou dans un état qui ne permet pas cette opération.',
        'precondition_failed' => 'La ressource a été modifiée depuis votre dernière lecture (If-Match).',
        'csrf_mismatch' => 'En-tête X-Prime-CSRF manquant ou invalide.',
    ],
    'validation' => [
        'display_name' => 'Le nom affiché est obligatoire (191 caractères maximum).',
        'locale' => 'Cette langue n’est pas disponible.',
    ],
];
