<?php

declare(strict_types=1);

/**
 * Traductions du plugin d’exemple hello-prime (français).
 */
return [
    'archive' => [
        'testimonial' => [
            'ajoutez_premier_temoignage_depuis_administration' => 'Ajoutez votre premier témoignage depuis l\'administration Prime CMS.',
            'aucun_temoignage_publie' => 'Aucun témoignage publié',
            'eyebrow' => 'Hello Prime',
            'retours_verifies_utilisateurs_partenaires' => 'Retours vérifiés de nos utilisateurs et partenaires.',
            'temoignages_clients' => 'Témoignages clients',
        ],
    ],
    'blocks' => [
        'highlight' => [
            'description' => 'Encart violet mettant en lumière un message clé ou un témoignage.',
            'title' => 'Mise en avant Hello Prime',
        ],
    ],
    'capability' => 'Gérer les réglages et messages Hello Prime',
    'dynamic' => [
        'default_title' => 'Témoignage client',
        'description' => 'Affiche un témoignage client publié tiré au sort.',
        'label' => 'Témoignage au hasard (Hello Prime)',
        'min_rating' => 'Note minimale (1 à 5)',
        'show_stars' => 'Afficher les étoiles',
        'style' => 'Style d’affichage',
        'style_card' => 'Carte complète',
        'style_compact' => 'Compact',
        'title' => 'Titre affiché',
    ],
    'fields' => [
        'author_photo' => 'Photo du client',
        'author_photo_help' => 'Portrait sélectionné depuis la médiathèque (optionnel).',
        'author_role' => 'Fonction / Entreprise',
        'author_role_placeholder' => 'Ex. Directrice produit chez Studio Horizon',
        'featured' => 'Mettre en avant sur la page publique',
        'group_description' => 'Métadonnées déclarées par le plugin Hello Prime.',
        'group_title' => 'Détails du témoignage',
        'rating' => 'Note (1 à 5)',
    ],
    'manifest' => [
        'description' => 'Plugin officiel de référence démontrant l\'ensemble de l\'API plugins Prime CMS : menu admin, capacités, réglages préfixés, migrations SQL, routes publiques, assets, notifications, types de contenus, taxonomies, champs personnalisés et blocs Prime Editor.',
    ],
    'notices' => [
        'welcome' => [
            'action' => 'Ouvrir Hello Prime',
            'message' => 'Personnalisez votre message d’accueil, gérez vos témoignages et testez le bloc Mise en avant.',
            'title' => 'Hello Prime est activé !',
        ],
    ],
    'settings' => [
        'greeting_help' => '160 caractères maximum, affiché sur la page publique /hello-prime.',
        'greeting_label' => 'Message d\'accueil',
        'moved' => 'Le message d\'accueil se règle désormais dans Réglages › Général : la section est générée par l\'API de réglages à partir de la déclaration du plugin.',
        'open' => 'Modifier dans Réglages › Général',
        'section_description' => 'Section ajoutée par le plugin Hello Prime avec l\'API de réglages.',
        'section_title' => 'Hello Prime — Message d\'accueil',
    ],
    'types' => [
        'source' => [
            'add_new' => 'Ajouter une source',
            'empty_title' => 'Aucune source enregistrée',
            'plural' => 'Sources',
            'singular' => 'Source',
        ],
        'testimonial' => [
            'add_new' => 'Nouveau témoignage',
            'edit' => 'Modifier le témoignage',
            'empty_description' => 'Ajoutez des retours clients à mettre en valeur sur votre site.',
            'empty_title' => 'Aucun témoignage enregistré',
            'plural' => 'Témoignages',
            'search_placeholder' => 'Rechercher un témoignage…',
            'singular' => 'Témoignage',
        ],
    ],
    'ui' => [
        'message_added' => 'Nouveau message ajouté dans la base de données.',
        'message_deleted' => 'Message supprimé.',
        'message_invalid' => 'Le message doit contenir entre 1 et 280 caractères.',
        'message_invalid_title' => 'Message invalide',
        'message_saved' => 'Message enregistré',
        'shortcut' => 'Configurer le message d’accueil et le livre d’or.',
        'subtitle' => 'Plugin de référence v:version — démonstration complète de l’API plugins Prime CMS.',
        'view_public' => 'Voir /hello-prime',
    ],
    'views' => [
        'admin' => [
            'action' => 'Action',
            'ajouter_entree' => 'Ajouter une entrée',
            'ajouter_message' => 'Ajouter le message',
            'aucun_message_enregistre' => 'Aucun message enregistré dans <code>:table</code> pour le moment.',
            'author' => 'Auteur',
            'date_utc' => 'Date (UTC)',
            'ecrivez_message_demonstration' => 'Écrivez un message de démonstration…',
            'enregistre_cle_prefixee' => 'Enregistré dans <code>pc_settings</code> sous la clé préfixée <code>:key</code>.',
            'greeting_label' => 'Message d’accueil',
            'insere_ligne_table_dediee' => 'Insère une ligne dans la table dédiée <code>:table</code> créée par la migration du plugin.',
            'message' => 'Message',
            'messages_enregistres' => '{0} Aucun message enregistré|{1} 1 message enregistré|[2,*] :count messages enregistrés',
            'nom' => 'Votre nom',
            'version' => 'Hello Prime · v:version',
        ],
        'public' => [
            'admin_link' => 'Administration Hello Prime →',
            'messages_recents' => 'Messages récents',
            'page_publique_servie_directement_plugin' => 'Cette page publique est servie directement par le plugin de référence <strong>hello-prime</strong> via <code>:router</code> et <code>:views</code>.',
            'retour_a_accueil' => '← Retour à l’accueil',
            'tagline' => 'Plugin Prime CMS · Hello Prime v:version',
            'title_suffix' => 'Hello Prime — :site',
        ],
    ],
];
