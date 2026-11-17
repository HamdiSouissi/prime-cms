<?php

declare(strict_types=1);

/**
 * Public site and Aurora theme strings (English).
 */
return [
    'archive' => [
        'author' => 'Author',
        'category' => 'Category: :name',
        'count' => ':count item|:count items',
        'day' => 'Archive for :date',
        'empty' => 'No published content in this archive.',
        'eyebrow' => 'Archive',
        'month' => 'Monthly archive: :month :year',
        'tag' => 'Tag: :name',
        'title' => 'Archives',
        'year' => 'Yearly archive: :year',
    ],
    'attr' => [
        'a_propos_de_l_auteur' => 'About the author',
        'barre_laterale' => 'Sidebar',
        'etiquettes' => 'Tags',
        'rechercher_un_article_une_page' => 'Search for a post, a page…',
        'rechercher_un_contenu' => 'Search for content…',
    ],
    'aurora' => [
        '404' => [
            'articles_recents' => 'Recent posts',
            'page_recherchez_peut_etre_ete' => 'The page you are looking for may have been moved, renamed or deleted.',
            'parcourir_blog' => 'Browse the blog',
            'rechercher_site' => 'Search the site',
        ],
        'about_title' => 'About',
        'archive' => [
            'default' => [
                'aucun_contenu_publie' => 'No content published',
                'rubrique_ne_contient_aucun_element' => 'This section has no items yet.',
            ],
        ],
        'author' => [
            'aucun_article_publie' => 'No post published',
            'pas_encore_publie_article' => 'has not published any post yet.',
        ],
        'blog' => [
            'aucun_article_publie' => 'No post published',
            'prochains_articles_apparaitront_ici_des' => 'Upcoming posts will appear here as soon as they are published.',
        ],
        'contact_title' => 'Contact us',
        'cta_button' => 'Open the dashboard',
        'cta_text' => 'Create your first piece of content in seconds.',
        'cta_title' => 'Ready to publish?',
        'entry' => [
            'post' => [
                'larr_article_precedent' => '&larr; Previous post',
                'min_lecture' => 'min read',
                'tous_articles_publies_par' => 'View all posts published by :name.',
            ],
        ],
        'features_title' => 'What Prime CMS can do',
        'footer_note' => 'Built with Prime CMS.',
        'front' => [
            'aucun_article_encore_ete_publie' => 'No post has been published yet. Create your first content from the admin.',
            'min_lecture' => 'min read',
        ],
        'hero_cta' => 'Explore the blog',
        'hero_eyebrow' => 'Prime CMS',
        'hero_text' => 'A calm and fast theme that puts your content first.',
        'hero_title' => 'Write, publish, share',
        'latest' => 'Latest posts',
        'latest_more' => 'See all posts',
        'partials' => [
            'card' => [
                'min_lecture' => ':count min read',
            ],
            'footer' => [
                'footer_nav' => 'Footer navigation',
            ],
            'header' => [
                'blog' => 'Blog',
                'home' => 'Home',
                'ouvrir_menu_navigation' => 'Open the navigation menu',
                'ouvrir_recherche' => 'Open search',
                'primary_nav' => 'Primary navigation',
                'rechercher_site' => 'Search the site',
            ],
            'pagination' => [
                'larr_precedent' => '&larr; Previous',
            ],
        ],
        'search' => [
            'aucun_resultat' => 'No results',
            'essayez_autres_mots_cles_explorez' => 'Try other keywords or browse our latest posts.',
            'tapez_mot_cle' => 'Type a keyword…',
            'terme_recherche' => 'Search term',
        ],
        'templates' => [
            'author' => [
                'eyebrow' => 'Author',
            ],
            'blog' => [
                'eyebrow' => 'Journal',
            ],
        ],
        'term' => [
            'default' => [
                'aucun_article_rubrique' => 'No post in this section',
                'aucun_contenu_publie_encore_associe' => 'No published content is associated with this term yet.',
            ],
        ],
        'theme' => [
            'description' => 'Default editorial theme for Prime CMS: clean, fast, accessible (WCAG AA) and free of external dependencies.',
            'options' => [
                'fields' => [
                    'accent_color' => [
                        'couleur_d_accentuation' => 'Accent colour',
                    ],
                    'bg_color' => [
                        'couleur_d_arriere_plan' => 'Background colour',
                    ],
                    'container_width' => [
                        'largeur_maximale_du_site_px' => 'Maximum site width (px)',
                    ],
                    'font_body' => [
                        'choices' => [
                            'inter' => 'Inter (Default)',
                            'jakarta' => 'Plus Jakarta Sans',
                            'system' => 'Native system font',
                        ],
                        'police_du_texte_courant' => 'Body font',
                    ],
                    'font_heading' => [
                        'choices' => [
                            'inter' => 'Inter (Neutral)',
                            'jakarta' => 'Plus Jakarta Sans (Editorial)',
                            'serif' => 'Georgia / Classic serif',
                        ],
                        'police_des_titres' => 'Heading font',
                    ],
                    'footer_text' => [
                        'texte_de_pied_de_page' => 'Footer text',
                    ],
                    'header_sticky' => [
                        'fixer_l_en_tete_au_defilement' => 'Stick the header on scroll',
                    ],
                    'show_author_box' => [
                        'afficher_l_encart_auteur_sous_les_articles' => 'Show the author box under posts',
                    ],
                    'show_header_search' => [
                        'afficher_le_bouton_de_recherche_dans_l_en_tete' => 'Show the search button in the header',
                    ],
                    'show_related' => [
                        'afficher_les_articles_similaires' => 'Show related posts',
                    ],
                    'show_sidebar' => [
                        'afficher_la_barre_laterale_quand_elle_contient_des_blocs' => 'Show the sidebar when it contains blocks',
                    ],
                    'surface_color' => [
                        'couleur_des_cartes' => 'Card colour',
                    ],
                    'text_color' => [
                        'couleur_du_texte_principal' => 'Body text colour',
                    ],
                ],
                'sections' => [
                    'colors' => [
                        'couleurs' => 'Colours',
                        'description' => 'Main site palette. The default coral (#C9441F) meets WCAG AA contrast.',
                    ],
                    'footer' => [
                        'description' => 'Notice shown at the bottom of every page.',
                        'pied_de_page' => 'Footer',
                    ],
                    'header' => [
                        'description' => 'Navigation bar and search behaviour.',
                        'en_tete' => 'Header',
                    ],
                    'layout' => [
                        'description' => 'Maximum content width and display of post elements.',
                        'mise_en_page' => 'Layout',
                    ],
                    'typography' => [
                        'description' => 'Local fonts served directly by your server (no external resource).',
                        'typographie' => 'Typography',
                    ],
                ],
            ],
            'zones' => [
                'footer_1' => [
                    'description' => 'First footer column (introduction or contact details).',
                    'pied_de_page_colonne_1' => 'Footer — Column 1',
                ],
                'footer_2' => [
                    'description' => 'Second footer column (useful links or categories).',
                    'pied_de_page_colonne_2' => 'Footer — Column 2',
                ],
                'footer_3' => [
                    'description' => 'Third footer column (latest posts or search).',
                    'pied_de_page_colonne_3' => 'Footer — Column 3',
                ],
                'sidebar' => [
                    'barre_laterale' => 'Sidebar',
                    'description' => 'Side column shown on the blog, archives and posts when it contains blocks.',
                ],
            ],
        ],
    ],
    'back_to_top' => 'Back to top',
    'blog' => [
        'title' => 'Blog',
    ],
    'by_author' => 'By :author',
    'comments' => [
        'by' => 'by :author',
        'closed' => 'Comments are closed.',
        'count' => ':count comment|:count comments',
        'email' => 'Email',
        'form_title' => 'Leave a comment',
        'message' => 'Comment',
        'moderated' => 'Your comment will be published after approval.',
        'name' => 'Name',
        'pending' => 'Awaiting moderation',
        'reply' => 'Reply',
        'submit' => 'Post comment',
        'title' => 'Comments',
        'website' => 'Website',
    ],
    'continue_reading' => 'Continue reading',
    'dynamic_blocks' => [
        'fields' => [
            'limit' => 'Number of items (1 to 12)',
            'menu_id' => 'Menu to display',
            'show_count' => 'Show the number of items',
            'show_excerpt' => 'Show the excerpt',
            'show_image' => 'Show the featured image',
            'taxonomy' => 'Taxonomy',
            'title' => 'Displayed title',
            'title_optional' => 'Displayed title (optional)',
            'type' => 'Content type',
        ],
        'latest_entries' => [
            'default_title' => 'Latest posts',
            'description' => 'Lists the latest published posts or content.',
            'label' => 'Latest content',
        ],
        'menu' => [
            'description' => 'Displays a saved navigation menu.',
            'label' => 'Navigation menu',
            'none' => 'No saved menu',
            'placeholder' => 'Navigation',
        ],
        'search_form' => [
            'description' => 'Accessible search field that leads to the search page.',
            'label' => 'Search form',
        ],
        'term_list' => [
            'default_title' => 'Categories',
            'description' => 'Lists the site\'s categories or tags.',
            'label' => 'Term list',
        ],
    ],
    'error' => [
        'not_found_title' => 'Page not found',
    ],
    'errors' => [
        '404' => [
            'heading' => 'Page not found',
        ],
    ],
    'footer' => [
        'back_to_top' => 'Back to top',
        'copyright' => '© :year :site. All rights reserved.',
        'powered_by' => 'Powered by :name',
        'rss' => 'RSS feed',
        'sitemap' => 'Sitemap',
    ],
    'forbidden' => [
        'login' => 'Sign in',
        'message' => 'You must be signed in to view this content.',
        'title' => 'Access denied',
    ],
    'forms' => [
        'consent' => 'I agree that this information is stored.',
        'error' => 'The form could not be sent.',
        'required_fields' => 'Fields marked with an asterisk are required.',
        'sending' => 'Sending…',
        'sent' => 'Message sent. Thank you!',
        'submit' => 'Send',
    ],
    'front' => [
        'welcome' => 'Welcome to :name',
    ],
    'home' => [
        'ecriture_prete' => 'Writable',
        'etat_json_health' => 'JSON status (/health)',
        'etat_systeme' => 'System status',
        'noyau_place' => 'The core is in place',
        'permissions_a_verifier' => 'Permissions to check',
    ],
    'in_category' => 'In :category',
    'no_excerpt' => 'No excerpt yet.',
    'not_found' => [
        'heading' => 'Nothing here',
        'home' => 'Back to the home page',
        'message' => 'The content you are looking for may have been moved, deleted, or is no longer available.',
        'search' => 'Try a search',
        'title' => 'Page not found',
    ],
    'pages' => 'Pages',
    'pagination' => [
        'current' => 'Page :page of :total',
        'go_to' => 'Go to page :page',
        'next' => 'Next →',
        'page' => 'Page :page',
        'previous' => '← Previous',
    ],
    'password_protected' => 'Protected content',
    'password_protected_help' => 'Enter the password to view this content.',
    'password_submit' => 'View',
    'post' => [
        'next_post' => 'Next post →',
        'related' => 'Related posts',
    ],
    'post_nav' => [
        'back_to_blog' => 'Back to the blog',
        'next' => 'Next',
        'next_post' => 'Next post',
        'previous' => 'Previous',
        'previous_post' => 'Previous post',
    ],
    'posted_in' => 'Posted in',
    'published_on' => 'Published on :date',
    'read_more' => 'Read more',
    'reading_time' => ':count minute read|:count minutes read',
    'search' => [
        'count' => ':count result found|:count results found',
        'eyebrow' => 'Search',
        'heading' => 'Search the site',
        'label' => 'Search:',
        'none' => 'Nothing matches your search.',
        'placeholder' => 'Search this site…',
        'results_for' => 'Results for “:query”',
        'submit' => 'Search',
        'title' => 'Search',
        'title_with_query' => 'Search: :query',
        'try_again' => 'Try other keywords.',
    ],
    'skip_content' => 'Skip to content',
    'skip_to_content' => 'Skip to main content',
    'sticky' => 'Sticky',
    'tagged' => 'Tags',
    'updated_on' => 'Updated on :date',
    'widgets' => [
        'about' => 'About',
        'categories' => 'Categories',
        'empty' => 'Nothing to display.',
        'recent_posts' => 'Recent content',
        'search' => 'Search',
        'tags' => 'Tags',
    ],
];
