<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Réglages déclaratifs de Prime CMS
|--------------------------------------------------------------------------
|
| Chaque champ décrit à la fois : la clé persistée dans `pc_settings`, son type
| de stockage, son widget d'administration, sa valeur par défaut, ses règles de
| validation et sa visibilité. Les écrans d'administration, `prime settings:set`
| et les points d'entrée REST lisent tous ce registre : ajouter un réglage ne
| demande donc qu'une entrée ici (ou un filtre `settings.register` côté extension).
|
| Types disponibles : text, textarea, code, number, email, url, checkbox, toggle,
| radio, select, multiselect, color, date, datetime, time, media, hidden, secret.
*/

return [
    'sections' => [
        'general' => [
            'label' => 'Général',
            'description' => 'Identité du site, horaires et visibilité',
            'icon' => 'settings',
            'priority' => 10,
            'page' => 'general',
        ],
        'languages' => [
            'label' => 'Langues',
            'description' => 'Langue du site, de l’administration et catalogues de traduction',
            'icon' => 'languages',
            'priority' => 20,
            'page' => 'general',
        ],
        'security' => [
            'label' => 'Sécurité',
            'description' => 'Mots de passe, sessions, double authentification et limitation de débit',
            'icon' => 'shield',
            'priority' => 30,
            'page' => 'general',
        ],
        'api' => [
            'label' => 'API REST',
            'description' => 'Activation, lecture publique, CORS, limitation de débit et durée de vie des jetons d’API.',
            'icon' => 'shield',
            'priority' => 35,
            'page' => 'security',
        ],
        'reading' => [
            'label' => 'Lecture',
            'description' => 'Page d’accueil, flux et affichage des contenus',
            'icon' => 'book-open',
            'priority' => 40,
            'page' => 'general',
        ],
        'writing' => [
            'label' => 'Écriture',
            'description' => 'Formats, catégories par défaut et corbeille',
            'icon' => 'pen-line',
            'priority' => 50,
            'page' => 'general',
        ],
        'permalinks' => [
            'label' => 'Permaliens',
            'description' => 'Structure des adresses publiques',
            'icon' => 'link',
            'priority' => 60,
            'page' => 'general',
        ],
        'seo' => [
            'label' => 'Référencement',
            'description' => 'Titres, sitemap et partage social',
            'icon' => 'search',
            'priority' => 70,
            'page' => 'general',
        ],
        'performance' => [
            'label' => 'Performance',
            'description' => 'Cache de données, cache de pages et purge',
            'icon' => 'zap',
            'priority' => 80,
            'page' => 'general',
        ],
        'mail' => [
            'label' => 'E-mails',
            'description' => 'Envoi, gabarits et journal des notifications',
            'icon' => 'mail',
            'priority' => 90,
            'page' => 'general',
        ],
        'scheduler' => [
            'label' => 'Tâches planifiées',
            'description' => 'Planificateur, file d’attente et automatisations',
            'icon' => 'clock',
            'priority' => 100,
            'page' => 'general',
        ],
        'advanced' => [
            'label' => 'Avancé',
            'description' => 'Options techniques réservées aux administrateurs',
            'icon' => 'terminal',
            'priority' => 110,
            'page' => 'general',
        ],
    ],

    'fields' => [
        // — Général —
        ['name' => 'site.name', 'label' => 'Titre du site', 'type' => 'text', 'section' => 'general', 'default' => 'Prime CMS', 'rules' => 'required|max:120', 'public' => true, 'priority' => 10],
        ['name' => 'site.tagline', 'label' => 'Sous-titre', 'type' => 'text', 'section' => 'general', 'default' => '', 'rules' => 'max:160', 'public' => true, 'priority' => 20],
        ['name' => 'site.description', 'label' => 'Description', 'type' => 'textarea', 'section' => 'general', 'default' => '', 'rules' => 'max:500', 'public' => true, 'priority' => 30],
        ['name' => 'site.admin_email', 'label' => 'E-mail d’administration', 'type' => 'email', 'section' => 'general', 'default' => '', 'rules' => 'required|email|max:191', 'priority' => 40],
        ['name' => 'site.logo_id', 'label' => 'Logo', 'type' => 'media', 'section' => 'general', 'default' => 0, 'priority' => 50, 'public' => true],
        ['name' => 'site.icon_id', 'label' => 'Icône du site', 'type' => 'media', 'section' => 'general', 'default' => 0, 'priority' => 60, 'public' => true],
        ['name' => 'general.timezone', 'label' => 'Fuseau horaire', 'type' => 'select', 'section' => 'general', 'default' => 'UTC', 'priority' => 70, 'options' => 'timezones'],
        // Numeric keys would read as a plain list of values: the `['label' => …]` form keeps 0–6.
        ['name' => 'general.start_of_week', 'label' => 'Premier jour de la semaine', 'type' => 'select', 'section' => 'general', 'default' => '1', 'priority' => 80, 'options' => [
            '0' => ['label' => 'Dimanche'],
            '1' => ['label' => 'Lundi'],
            '2' => ['label' => 'Mardi'],
            '3' => ['label' => 'Mercredi'],
            '4' => ['label' => 'Jeudi'],
            '5' => ['label' => 'Vendredi'],
            '6' => ['label' => 'Samedi'],
        ]],
        ['name' => 'general.date_format', 'label' => 'Format de date', 'type' => 'text', 'section' => 'general', 'default' => 'd/m/Y', 'rules' => 'max:32', 'priority' => 90],
        ['name' => 'general.time_format', 'label' => 'Format d’heure', 'type' => 'text', 'section' => 'general', 'default' => 'H:i', 'rules' => 'max:32', 'priority' => 100],
        ['name' => 'general.privacy', 'label' => 'Visibilité du site', 'type' => 'toggle', 'section' => 'general', 'default' => false, 'priority' => 110, 'public' => true,
            'description' => 'Quand ce réglage est activé, le site public est réservé aux utilisateurs connectés.'],

        // — Langues —
        ['name' => 'language.site', 'label' => 'Langue du site', 'type' => 'select', 'section' => 'languages', 'default' => 'fr', 'priority' => 10, 'public' => true, 'options' => 'locales'],
        ['name' => 'language.admin', 'label' => 'Langue d’administration', 'type' => 'select', 'section' => 'languages', 'default' => '', 'priority' => 20, 'options' => 'locales',
            'description' => 'Vide : chaque utilisateur utilise la langue de son profil.'],
        ['name' => 'language.fallback', 'label' => 'Langue de secours', 'type' => 'select', 'section' => 'languages', 'default' => 'en', 'priority' => 30, 'options' => 'locales'],
        ['name' => 'language.per_user', 'label' => 'Langue personnalisable par utilisateur', 'type' => 'toggle', 'section' => 'languages', 'default' => true, 'priority' => 40],
        ['name' => 'language.detect_browser', 'label' => 'Suivre la langue du navigateur sur le site public', 'type' => 'toggle', 'section' => 'languages', 'default' => false, 'priority' => 50],

        // — Sécurité —
        ['name' => 'security.password_min_length', 'label' => 'Longueur minimale des mots de passe', 'type' => 'number', 'section' => 'security', 'default' => 10, 'rules' => 'required|min:8|max:64', 'priority' => 10],
        ['name' => 'security.password_require_mixed_case', 'label' => 'Majuscules et minuscules', 'type' => 'toggle', 'section' => 'security', 'default' => true, 'priority' => 20],
        ['name' => 'security.password_require_number', 'label' => 'Au moins un chiffre', 'type' => 'toggle', 'section' => 'security', 'default' => true, 'priority' => 30],
        ['name' => 'security.password_require_special', 'label' => 'Au moins un caractère spécial', 'type' => 'toggle', 'section' => 'security', 'default' => false, 'priority' => 40],
        ['name' => 'security.password_block_common', 'label' => 'Bloquer les mots de passe courants', 'type' => 'toggle', 'section' => 'security', 'default' => true, 'priority' => 50],
        ['name' => 'security.session_lifetime', 'label' => 'Durée de vie de la session (minutes)', 'type' => 'number', 'section' => 'security', 'default' => 1440, 'rules' => 'required|min:15|max:43200', 'priority' => 60],
        ['name' => 'security.remember_days', 'label' => 'Durée du cookie « Se souvenir de moi » (jours)', 'type' => 'number', 'section' => 'security', 'default' => 14, 'rules' => 'required|min:1|max:365', 'priority' => 70],
        ['name' => 'security.login_max_attempts', 'label' => 'Tentatives de connexion avant blocage', 'type' => 'number', 'section' => 'security', 'default' => 5, 'rules' => 'required|min:3|max:50', 'priority' => 80],
        ['name' => 'security.login_lockout_minutes', 'label' => 'Fenêtre de blocage (minutes)', 'type' => 'number', 'section' => 'security', 'default' => 15, 'rules' => 'required|min:1|max:1440', 'priority' => 90],
        ['name' => 'security.two_factor_required_roles', 'label' => 'Rôles soumis à la double authentification', 'type' => 'multiselect', 'section' => 'security', 'default' => ['administrator'], 'priority' => 100, 'options' => 'roles'],
        ['name' => 'security.session_absolute_lifetime', 'label' => 'Durée absolue de la session (minutes)', 'type' => 'number', 'section' => 'security', 'default' => 0, 'rules' => 'min:0|max:43200', 'priority' => 65,
            'description' => '0 : aucune limite absolue ; seule l’inactivité est prise en compte.'],
        ['name' => 'security.two_factor_enabled', 'label' => 'Autoriser la double authentification', 'type' => 'toggle', 'section' => 'security', 'default' => false, 'priority' => 95,
            'description' => 'Interrupteur maître : éteint, aucun compte n’est tenu de s’inscrire, même si des rôles sont listés ci-dessous. Un administrateur verrouillé dehors est plus coûteux qu’un double facteur optionnel.'],
        ['name' => 'security.two_factor_issuer', 'label' => 'Émetteur TOTP', 'type' => 'text', 'section' => 'security', 'default' => 'Prime CMS', 'rules' => 'required|max:80', 'priority' => 96],
        ['name' => 'security.two_factor_challenge_minutes', 'label' => 'Durée du défi TOTP (minutes)', 'type' => 'number', 'section' => 'security', 'default' => 5, 'rules' => 'required|min:1|max:60', 'priority' => 105],
        ['name' => 'security.reset_token_minutes', 'label' => 'Validité d’un lien de réinitialisation (minutes)', 'type' => 'number', 'section' => 'security', 'default' => 60, 'rules' => 'required|min:5|max:1440', 'priority' => 115],
        ['name' => 'security.invite_token_minutes', 'label' => 'Validité d’une invitation (minutes)', 'type' => 'number', 'section' => 'security', 'default' => 2880, 'rules' => 'required|min:60|max:20160', 'priority' => 120],
        ['name' => 'security.force_logout_all', 'label' => 'Fermer toutes les sessions au changement de mot de passe', 'type' => 'toggle', 'section' => 'security', 'default' => true, 'priority' => 110],

        // — API REST —
        ['name' => 'api.enabled', 'label' => 'Activer l’API REST', 'type' => 'toggle', 'section' => 'api', 'default' => true, 'priority' => 10],
        ['name' => 'api.public_read', 'label' => 'Lecture publique sans authentification', 'type' => 'toggle', 'section' => 'api', 'default' => true, 'priority' => 20,
            'description' => 'Quand activé, les contenus publiés sont lisibles sans jeton d’API.'],
        ['name' => 'api.cors_origins', 'label' => 'Origines CORS autorisées', 'type' => 'textarea', 'section' => 'api', 'default' => '', 'priority' => 30,
            'description' => 'Une origine par ligne. Laissez vide pour n’envoyer aucun en-tête CORS.'],
        ['name' => 'api.rate_limit_authenticated', 'label' => 'Limite de débit par jeton (requêtes par minute)', 'type' => 'number', 'section' => 'api', 'default' => 60, 'rules' => 'min:1|max:10000', 'priority' => 40],
        ['name' => 'api.rate_limit_anonymous', 'label' => 'Limite de débit anonyme (requêtes par minute)', 'type' => 'number', 'section' => 'api', 'default' => 20, 'rules' => 'min:1|max:10000', 'priority' => 50],
        ['name' => 'api.max_body_kb', 'label' => 'Taille maximale du corps (Ko)', 'type' => 'number', 'section' => 'api', 'default' => 1024, 'rules' => 'min:1|max:10240', 'priority' => 60],
        ['name' => 'api.token_max_days', 'label' => 'Durée maximale de vie d’un jeton (jours)', 'type' => 'number', 'section' => 'api', 'default' => 365, 'rules' => 'min:1|max:3650', 'priority' => 70],

        // — Lecture —
        ['name' => 'reading.front_mode', 'label' => 'Sur la page d’accueil', 'type' => 'radio', 'section' => 'reading', 'default' => 'posts', 'priority' => 10, 'options' => [
            'posts' => 'Vos derniers articles',
            'page' => 'Une page fixe',
        ]],
        ['name' => 'reading.front_page_id', 'label' => 'Page d’accueil', 'type' => 'select', 'section' => 'reading', 'default' => 0, 'priority' => 20, 'options' => 'pages',
            'conditions' => ['field' => 'reading.front_mode', 'value' => 'page']],
        ['name' => 'reading.posts_page_id', 'label' => 'Page des articles', 'type' => 'select', 'section' => 'reading', 'default' => 0, 'priority' => 30, 'options' => 'pages',
            'conditions' => ['field' => 'reading.front_mode', 'value' => 'page']],
        ['name' => 'reading.posts_per_page', 'label' => 'Articles par page', 'type' => 'number', 'section' => 'reading', 'default' => 10, 'rules' => 'required|min:1|max:100', 'priority' => 40],
        ['name' => 'reading.excerpt_length', 'label' => 'Longueur de l’extrait (mots)', 'type' => 'number', 'section' => 'reading', 'default' => 30, 'rules' => 'min:5|max:200', 'priority' => 50],
        ['name' => 'reading.feed_items', 'label' => 'Éléments par flux', 'type' => 'number', 'section' => 'reading', 'default' => 10, 'rules' => 'min:1|max:50', 'priority' => 60],
        ['name' => 'reading.feed_mode', 'label' => 'Contenu du flux', 'type' => 'radio', 'section' => 'reading', 'default' => 'excerpt', 'priority' => 70, 'options' => [
            'full' => 'Texte complet',
            'excerpt' => 'Extrait',
        ]],

        // — Écriture —
        ['name' => 'writing.default_category', 'label' => 'Catégorie par défaut', 'type' => 'select', 'section' => 'writing', 'default' => '', 'priority' => 10, 'options' => 'categories'],
        ['name' => 'writing.trash_days', 'label' => 'Vider la corbeille après (jours)', 'type' => 'number', 'section' => 'writing', 'default' => 30, 'rules' => 'min:1|max:365', 'priority' => 20],
        ['name' => 'writing.autosave_minutes', 'label' => 'Enregistrement automatique (minutes)', 'type' => 'number', 'section' => 'writing', 'default' => 1, 'rules' => 'min:1|max:15', 'priority' => 30],
        ['name' => 'writing.revisions', 'label' => 'Conserver les révisions', 'type' => 'toggle', 'section' => 'writing', 'default' => true, 'priority' => 40],

        // — Permaliens —
        ['name' => 'permalinks.post_structure', 'label' => 'Structure des articles', 'type' => 'select', 'section' => 'permalinks', 'default' => '/blog/%postname%', 'priority' => 10, 'options' => [
            '/blog/%postname%' => 'Nom de l’article',
            '/blog/%year%/%month%/%postname%' => 'Mois et nom',
            '/blog/%year%/%monthnum%/%day%/%postname%' => 'Jour et nom',
            '/?p=%post_id%' => 'Numérique',
        ]],
        ['name' => 'permalinks.category_base', 'label' => 'Préfixe des catégories', 'type' => 'text', 'section' => 'permalinks', 'default' => 'categorie', 'rules' => 'max:60', 'priority' => 20],
        ['name' => 'permalinks.tag_base', 'label' => 'Préfixe des étiquettes', 'type' => 'text', 'section' => 'permalinks', 'default' => 'etiquette', 'rules' => 'max:60', 'priority' => 30],
        ['name' => 'permalinks.author_base', 'label' => 'Préfixe des auteurs', 'type' => 'text', 'section' => 'permalinks', 'default' => 'auteur', 'rules' => 'max:60', 'priority' => 40],
        ['name' => 'permalinks.search_base', 'label' => 'Préfixe de recherche', 'type' => 'text', 'section' => 'permalinks', 'default' => 'recherche', 'rules' => 'max:60', 'priority' => 50],
        ['name' => 'permalinks.trailing_slash', 'label' => 'Termine les URL par /', 'type' => 'toggle', 'section' => 'permalinks', 'default' => true, 'priority' => 60],

        // — Référencement —
        ['name' => 'seo.title_template', 'label' => 'Modèle de titre', 'type' => 'text', 'section' => 'seo', 'default' => '%title% %separator% %site%', 'rules' => 'max:160', 'priority' => 10],
        ['name' => 'seo.separator', 'label' => 'Séparateur', 'type' => 'select', 'section' => 'seo', 'default' => '—', 'priority' => 20, 'options' => [
            '—' => 'Tiret demi-cadratin —',
            '|' => 'Barre verticale |',
            '-' => 'Trait d’union -',
            '·' => 'Point médian ·',
            '»' => 'Guillemet »',
        ]],
        ['name' => 'seo.default_description', 'label' => 'Méta-description par défaut', 'type' => 'textarea', 'section' => 'seo', 'default' => '', 'rules' => 'max:300', 'priority' => 30],
        ['name' => 'seo.default_image_id', 'label' => 'Image de partage par défaut', 'type' => 'media', 'section' => 'seo', 'default' => 0, 'priority' => 40],
        ['name' => 'seo.global_noindex', 'label' => 'Demander aux moteurs de ne pas indexer', 'type' => 'toggle', 'section' => 'seo', 'default' => false, 'priority' => 50],
        ['name' => 'seo.sitemap_enabled', 'label' => 'Sitemap XML', 'type' => 'toggle', 'section' => 'seo', 'default' => true, 'priority' => 60],
        ['name' => 'seo.robots_custom', 'label' => 'robots.txt personnalisé', 'type' => 'code', 'section' => 'seo', 'default' => '', 'rules' => 'max:5000', 'priority' => 70],

        // — Performance —
        ['name' => 'cache.driver', 'label' => 'Moteur de cache', 'type' => 'select', 'section' => 'performance', 'default' => 'file', 'priority' => 10, 'options' => [
            'file' => 'Fichiers (storage/cache)',
            'apcu' => 'APCu (mémoire partagée)',
        ]],
        ['name' => 'cache.page_enabled', 'label' => 'Cache des pages', 'type' => 'toggle', 'section' => 'performance', 'default' => true, 'priority' => 20],
        ['name' => 'cache.page_ttl', 'label' => 'Durée de vie du cache (secondes)', 'type' => 'number', 'section' => 'performance', 'default' => 600, 'rules' => 'required|min:10|max:86400', 'priority' => 30,
            'conditions' => ['field' => 'cache.page_enabled', 'value' => '1']],
        ['name' => 'cache.assets', 'label' => 'Minifier et concaténer le CSS de l’administration', 'type' => 'toggle', 'section' => 'performance', 'default' => true, 'priority' => 40],

        // — E-mails —
        ['name' => 'mail.driver', 'label' => 'Méthode d’envoi', 'type' => 'select', 'section' => 'mail', 'default' => 'log', 'priority' => 10, 'options' => [
            'log' => 'Journal de bord (aucun envoi réel)',
            'native' => 'Fonction mail() de PHP',
            'smtp' => 'Serveur SMTP',
            'queue' => 'File d’attente SMTP',
            'null' => 'Aucun envoi (désactivé)',
        ]],
        ['name' => 'mail.from_address', 'label' => 'Adresse de l’expéditeur', 'type' => 'email', 'section' => 'mail', 'default' => 'no-reply@prime-cms.local', 'rules' => 'required|email|max:191', 'priority' => 20],
        ['name' => 'mail.from_name', 'label' => 'Nom de l’expéditeur', 'type' => 'text', 'section' => 'mail', 'default' => 'Prime CMS', 'rules' => 'max:120', 'priority' => 30],
        ['name' => 'mail.reply_to', 'label' => 'Adresse de réponse', 'type' => 'email', 'section' => 'mail', 'default' => '', 'rules' => 'email|max:191', 'priority' => 40],
        ['name' => 'mail.host', 'label' => 'Hôte SMTP', 'type' => 'text', 'section' => 'mail', 'default' => '', 'rules' => 'max:191', 'priority' => 50, 'conditions' => ['field' => 'mail.driver', 'value' => ['smtp', 'queue']]],
        ['name' => 'mail.port', 'label' => 'Port SMTP', 'type' => 'number', 'section' => 'mail', 'default' => 587, 'rules' => 'min:1|max:65535', 'priority' => 60, 'conditions' => ['field' => 'mail.driver', 'value' => ['smtp', 'queue']]],
        ['name' => 'mail.encryption', 'label' => 'Chiffrement', 'type' => 'select', 'section' => 'mail', 'default' => 'tls', 'priority' => 70, 'options' => [
            '' => 'Aucun',
            'ssl' => 'SSL (implicite)',
            'tls' => 'STARTTLS',
        ], 'conditions' => ['field' => 'mail.driver', 'value' => ['smtp', 'queue']]],
        ['name' => 'mail.username', 'label' => 'Identifiant SMTP', 'type' => 'text', 'section' => 'mail', 'default' => '', 'rules' => 'max:191', 'priority' => 80, 'conditions' => ['field' => 'mail.driver', 'value' => ['smtp', 'queue']]],
        ['name' => 'mail.password', 'label' => 'Mot de passe SMTP', 'type' => 'secret', 'section' => 'mail', 'default' => null, 'priority' => 90, 'conditions' => ['field' => 'mail.driver', 'value' => ['smtp', 'queue']]],
        ['name' => 'mail.timeout', 'label' => 'Délai d’expiration (secondes)', 'type' => 'number', 'section' => 'mail', 'default' => 15, 'rules' => 'min:5|max:120', 'priority' => 100, 'conditions' => ['field' => 'mail.driver', 'value' => ['smtp', 'queue']]],
        ['name' => 'mail.verify_tls', 'label' => 'Vérifier le certificat du serveur', 'type' => 'toggle', 'section' => 'mail', 'default' => true, 'priority' => 110, 'conditions' => ['field' => 'mail.driver', 'value' => ['smtp', 'queue']]],
        ['name' => 'mail.log_enabled', 'label' => 'Journaliser les envois', 'type' => 'toggle', 'section' => 'mail', 'default' => true, 'priority' => 120],
        ['name' => 'mail.log_days', 'label' => 'Conserver le journal (jours)', 'type' => 'number', 'section' => 'mail', 'default' => 30, 'rules' => 'min:1|max:365', 'priority' => 130, 'conditions' => ['field' => 'mail.log_enabled', 'value' => '1']],

        // — Tâches planifiées —
        ['name' => 'scheduler.enabled', 'label' => 'Activer le planificateur', 'type' => 'toggle', 'section' => 'scheduler', 'default' => true, 'priority' => 10],
        ['name' => 'scheduler.fallback', 'label' => 'Exécuter au passage des visiteurs', 'type' => 'toggle', 'section' => 'scheduler', 'default' => true, 'priority' => 20,
            'description' => 'Utile quand aucune tâche cron système n’est configurable.'],
        ['name' => 'scheduler.interval', 'label' => 'Intervalle minimal entre deux exécutions (secondes)', 'type' => 'number', 'section' => 'scheduler', 'default' => 60, 'rules' => 'required|min:10|max:3600', 'priority' => 30],
        ['name' => 'scheduler.queue_tries', 'label' => 'Tentatives par tâche en file', 'type' => 'number', 'section' => 'scheduler', 'default' => 3, 'rules' => 'min:1|max:10', 'priority' => 40],
        ['name' => 'scheduler.queue_ttl_minutes', 'label' => 'Abandonner une tâche après (minutes)', 'type' => 'number', 'section' => 'scheduler', 'default' => 60, 'rules' => 'min:5|max:1440', 'priority' => 50],

        // — Avancé —
        ['name' => 'advanced.maintenance', 'label' => 'Mode maintenance', 'type' => 'toggle', 'section' => 'advanced', 'default' => false, 'priority' => 10, 'public' => true],
        ['name' => 'advanced.maintenance_message', 'label' => 'Message de maintenance', 'type' => 'textarea', 'section' => 'advanced', 'default' => '', 'rules' => 'max:500', 'priority' => 20, 'public' => true,
            'conditions' => ['field' => 'advanced.maintenance', 'value' => '1']],
        ['name' => 'media.image_sizes', 'label' => 'Tailles d’image', 'type' => 'hidden', 'section' => 'advanced', 'default' => [], 'priority' => 30],
        ['name' => 'advanced.debug_bar', 'label' => 'Barre de débogage (administrateurs uniquement)', 'type' => 'toggle', 'section' => 'advanced', 'default' => false, 'priority' => 40],
    ],
];
