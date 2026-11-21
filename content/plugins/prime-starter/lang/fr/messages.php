<?php

declare(strict_types=1);

/**
 * Traductions du plugin Prime Starter (français).
 */
return [
    'blocks' => [
        'markdown' => [
            'description' => 'Texte écrit en Markdown, converti en HTML propre à l’affichage.',
            'title' => 'Markdown',
        ],
    ],
    'capability' => 'Gérer le carnet de notes (Prime Starter)',
    'colors' => [
        'mint' => 'Menthe',
        'rose' => 'Rose',
        'sand' => 'Sable',
        'sky' => 'Ciel',
    ],
    'dynamic' => [
        'default_title' => 'Dernières notes',
        'description' => 'Liste les dernières notes publiques du carnet.',
        'label' => 'Notes du carnet (Prime Starter)',
        'limit' => 'Nombre de notes (1 à 10)',
        'title' => 'Titre',
    ],
    'editor' => [
        'help' => 'Titres avec #, listes avec -, **gras**, *italique*, `code` et [liens](https://exemple.fr).',
        'label' => 'Contenu (Markdown)',
    ],
    'fields' => [
        'body' => 'Contenu',
        'body_help' => 'Markdown accepté : # titre, - liste, **gras**, *italique*, [lien](https://…).',
        'color' => 'Couleur',
        'pinned' => 'Épinglée',
        'pinned_help' => 'Les notes épinglées passent en tête de liste.',
        'private' => 'Privée',
        'public' => 'Publique',
        'public_help' => 'Visible sur la page publique du carnet et dans l’API sans authentification.',
        'title' => 'Titre',
        'updated_at' => 'Modifiée le',
        'visibility' => 'Visibilité',
    ],
    'mail' => [
        'action' => 'Ouvrir le carnet',
        'intro' => ':count note a changé ces :days derniers jours sur :site.|:count notes ont changé ces :days derniers jours sur :site.',
        'subject' => 'Carnet : :count note modifiée cette semaine|Carnet : :count notes modifiées cette semaine',
        'title' => 'Votre carnet de la semaine',
    ],
    'manifest' => [
        'description' => 'Plugin de départ : un carnet de notes qui montre chaque point d’extension (admin, réglages, page publique, API, blocs, éditeur, tâches, e-mail).',
    ],
    'menu' => 'Carnet',
    'notices' => [
        'welcome' => [
            'action' => 'Ouvrir le carnet',
            'message' => 'Prime Starter est actif : écrivez une première note, puis copiez le plugin avec bin/prime make:plugin.',
            'title' => 'Carnet prêt',
        ],
    ],
    'public' => [
        'description' => ':count note publique dans le carnet.|:count notes publiques dans le carnet.',
        'disabled' => 'Cette page n’est pas disponible.',
        'empty' => 'Aucune note publique pour le moment.',
    ],
    'scheduler' => [
        'digest' => 'Résumé hebdomadaire du carnet par e-mail (si une adresse est réglée).',
    ],
    'settings' => [
        'digest_email' => 'E-mail du résumé hebdomadaire',
        'digest_email_help' => 'Chaque lundi, les notes modifiées dans la semaine sont envoyées à cette adresse. Vide : aucun envoi.',
        'markdown_posts' => 'Écrire les articles en Markdown',
        'markdown_posts_help' => 'Remplace Prime Editor par un éditeur Markdown sur les articles.',
        'public_enabled' => 'Page publique du carnet',
        'public_enabled_help' => 'Publie les notes marquées « Publique » à l’adresse /notes.',
        'public_title' => 'Titre de la page publique',
        'public_title_help' => 'Affiché en tête de la page /notes et dans l’onglet du navigateur.',
        'section_description' => 'Page publique, éditeur Markdown et résumé par e-mail du plugin Prime Starter.',
        'section_title' => 'Carnet',
    ],
    'system' => [
        'label' => 'Carnet (Prime Starter)',
        'table_missing' => 'Table des notes introuvable : réactivez le plugin.',
        'value' => ':count note|:count notes',
    ],
    'ui' => [
        'actions' => 'Actions',
        'confirm_delete' => 'Supprimer la note « :title » ? Cette action est définitive.',
        'create' => 'Créer la note',
        'created' => 'Note créée.',
        'delete_named' => 'Supprimer « :title »',
        'deleted' => 'Note « :title » supprimée.',
        'edit' => 'Modifier la note',
        'edit_named' => 'Modifier « :title »',
        'empty_description' => 'Les notes servent de mémo pour l’équipe ; rendez-en certaines publiques pour les afficher sur le site.',
        'empty_title' => 'Aucune note pour l’instant',
        'missing' => 'Cette note n’existe plus.',
        'new' => 'Nouvelle note',
        'save' => 'Enregistrer',
        'subtitle' => 'Notes de l’équipe, en Markdown. Les notes publiques apparaissent sur /notes.',
        'table_caption' => 'Notes du carnet',
        'title' => 'Carnet',
        'updated' => 'Note enregistrée.',
        'view_public' => 'Voir la page publique',
    ],
    'validation' => [
        'body_max' => 'Le contenu dépasse :max caractères.',
        'summary' => 'La note n’a pas été enregistrée.',
        'title_max' => 'Le titre dépasse :max caractères.',
        'title_required' => 'Donnez un titre à la note.',
    ],
];
