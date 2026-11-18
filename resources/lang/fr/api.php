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
        'public_read_required' => 'Authentification requise pour accéder à ce contenu.',
        'validation_failed' => 'Échec de validation.',
        'unsupported_media_type' => 'Le corps de la requête doit être au format JSON.',
        'invalid_json' => 'Le corps de la requête est un JSON invalide.',
        'payload_too_large' => 'Le corps de la requête dépasse la taille maximale autorisée.',
        'csrf_mismatch' => 'Jeton CSRF manquant ou invalide.',
        'precondition_failed' => 'La condition préalable (If-Match) n’est pas satisfaite.',
        'conflict' => 'Conflit : la ressource est verrouillée ou déjà modifiée.',
    ],
];
