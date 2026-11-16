<?php

declare(strict_types=1);

namespace Prime\Auth;

use Prime\Hooks\Hooks;

/**
 * Central capability registry using `domain.action` keys, French labels, and logical groups.
 *
 * Extensible via the `auth.capabilities` filter hook so plugins and future phases
 * can declare additional permissions cleanly.
 */
final class Capabilities
{
    public const WILDCARD = '*';

    /**
     * Core Prime CMS capabilities keyed by `domain.action`.
     *
     * @var array<string, array{label: string, group: string}>
     */
    private const CORE = [
        // Tableau de bord
        'dashboard.view' => [
            'label' => 'Accéder au tableau de bord',
            'group' => 'Tableau de bord',
        ],

        // Utilisateurs
        'users.view' => [
            'label' => 'Consulter la liste des utilisateurs',
            'group' => 'Utilisateurs',
        ],
        'users.create' => [
            'label' => 'Créer des utilisateurs',
            'group' => 'Utilisateurs',
        ],
        'users.edit' => [
            'label' => 'Modifier les comptes utilisateurs',
            'group' => 'Utilisateurs',
        ],
        'users.delete' => [
            'label' => 'Supprimer des utilisateurs',
            'group' => 'Utilisateurs',
        ],
        'users.manage_roles' => [
            'label' => 'Modifier les rôles attribués aux utilisateurs',
            'group' => 'Utilisateurs',
        ],

        // Rôles
        'roles.manage' => [
            'label' => 'Gérer les rôles et leurs permissions',
            'group' => 'Rôles',
        ],

        // Contenus
        'content.view' => [
            'label' => 'Consulter les contenus',
            'group' => 'Contenus',
        ],
        'content.create' => [
            'label' => 'Créer des contenus',
            'group' => 'Contenus',
        ],
        'content.edit_own' => [
            'label' => 'Modifier ses propres contenus',
            'group' => 'Contenus',
        ],
        'content.edit_any' => [
            'label' => 'Modifier les contenus de tous les auteurs',
            'group' => 'Contenus',
        ],
        'content.publish' => [
            'label' => 'Publier et dépublier des contenus',
            'group' => 'Contenus',
        ],
        'content.delete_own' => [
            'label' => 'Supprimer ses propres contenus',
            'group' => 'Contenus',
        ],
        'content.delete_any' => [
            'label' => 'Supprimer les contenus de tous les auteurs',
            'group' => 'Contenus',
        ],
        'content.unfiltered_html' => [
            'label' => 'Insérer du HTML libre dans l\'éditeur de blocs',
            'group' => 'Contenus',
        ],

        // Médias
        'media.view' => [
            'label' => 'Consulter la médiathèque',
            'group' => 'Médias',
        ],
        'media.upload' => [
            'label' => 'Téléverser des fichiers médias',
            'group' => 'Médias',
        ],
        'media.delete_any' => [
            'label' => 'Supprimer n\'importe quel média',
            'group' => 'Médias',
        ],

        // Apparence, Extensions, Réglages
        'themes.manage' => [
            'label' => 'Gérer les thèmes, la personnalisation, les menus et les zones',
            'group' => 'Apparence',
        ],
        'appearance.manage' => [
            'label' => 'Gérer les thèmes, menus et apparence (hérité)',
            'group' => 'Apparence',
        ],
        'plugins.manage' => [
            'label' => 'Installer et gérer les extensions',
            'group' => 'Extensions',
        ],
        'settings.manage' => [
            'label' => 'Modifier les réglages généraux du site',
            'group' => 'Réglages',
        ],
    ];

    public function __construct(private readonly ?Hooks $hooks = null)
    {
    }

    /**
     * Return all registered capabilities after applying the `auth.capabilities` filter.
     *
     * @return array<string, array{label: string, group: string}>
     */
    public function all(): array
    {
        $registry = self::CORE;

        if ($this->hooks !== null) {
            $filtered = $this->hooks->apply('auth.capabilities', $registry);
            if (is_array($filtered)) {
                $normalized = [];
                foreach ($filtered as $key => $meta) {
                    if (
                        is_string($key)
                        && $key !== ''
                        && is_array($meta)
                        && isset($meta['label'], $meta['group'])
                        && is_string($meta['label'])
                        && is_string($meta['group'])
                    ) {
                        $normalized[$key] = [
                            'label' => $meta['label'],
                            'group' => $meta['group'],
                        ];
                    }
                }
                $registry = $normalized;
            }
        }

        return $registry;
    }

    /**
     * Return capabilities organized by group name for matrix rendering.
     *
     * @return array<string, array<string, string>> Group => [capability => label]
     */
    public function grouped(): array
    {
        $groups = [];
        foreach ($this->all() as $key => $meta) {
            $groups[$meta['group']][$key] = $meta['label'];
        }

        return $groups;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    public function exists(string $capability): bool
    {
        if ($capability === self::WILDCARD) {
            return true;
        }

        return isset($this->all()[$capability]);
    }

    /**
     * Filter a user-submitted list of capability keys down to known registered capabilities
     * (never accepting `*` from arbitrary role forms).
     *
     * @param list<string> $capabilities
     * @return list<string>
     */
    public function filterValid(array $capabilities): array
    {
        $known = $this->all();
        $valid = [];

        foreach ($capabilities as $cap) {
            if (isset($known[$cap]) && !in_array($cap, $valid, true)) {
                $valid[] = $cap;
            }
        }

        return $valid;
    }
}
