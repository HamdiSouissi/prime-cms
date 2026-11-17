<?php

declare(strict_types=1);

namespace Prime\Auth;

use Prime\Content\LabelTranslator;
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
            'label' => 'core.capabilities.items.dashboard__view',
            'group' => 'core.capabilities.groups.dashboard',
        ],

        // Utilisateurs
        'users.view' => [
            'label' => 'core.capabilities.items.users__view',
            'group' => 'core.capabilities.groups.users',
        ],
        'users.create' => [
            'label' => 'core.capabilities.items.users__create',
            'group' => 'core.capabilities.groups.users',
        ],
        'users.edit' => [
            'label' => 'core.capabilities.items.users__edit',
            'group' => 'core.capabilities.groups.users',
        ],
        'users.delete' => [
            'label' => 'core.capabilities.items.users__delete',
            'group' => 'core.capabilities.groups.users',
        ],
        'users.manage_roles' => [
            'label' => 'core.capabilities.items.users__manage_roles',
            'group' => 'core.capabilities.groups.users',
        ],

        // Rôles
        'roles.manage' => [
            'label' => 'core.capabilities.items.roles__manage',
            'group' => 'core.capabilities.groups.roles',
        ],

        // Contenus
        'content.view' => [
            'label' => 'core.capabilities.items.content__view',
            'group' => 'core.capabilities.groups.content',
        ],
        'content.create' => [
            'label' => 'core.capabilities.items.content__create',
            'group' => 'core.capabilities.groups.content',
        ],
        'content.edit_own' => [
            'label' => 'core.capabilities.items.content__edit_own',
            'group' => 'core.capabilities.groups.content',
        ],
        'content.edit_any' => [
            'label' => 'core.capabilities.items.content__edit_any',
            'group' => 'core.capabilities.groups.content',
        ],
        'content.publish' => [
            'label' => 'core.capabilities.items.content__publish',
            'group' => 'core.capabilities.groups.content',
        ],
        'content.delete_own' => [
            'label' => 'core.capabilities.items.content__delete_own',
            'group' => 'core.capabilities.groups.content',
        ],
        'content.delete_any' => [
            'label' => 'core.capabilities.items.content__delete_any',
            'group' => 'core.capabilities.groups.content',
        ],
        'content.unfiltered_html' => [
            'label' => 'core.capabilities.items.content__unfiltered_html',
            'group' => 'core.capabilities.groups.content',
        ],

        // Médias
        'media.view' => [
            'label' => 'core.capabilities.items.media__view',
            'group' => 'core.capabilities.groups.media',
        ],
        'media.upload' => [
            'label' => 'core.capabilities.items.media__upload',
            'group' => 'core.capabilities.groups.media',
        ],
        'media.delete_any' => [
            'label' => 'core.capabilities.items.media__delete_any',
            'group' => 'core.capabilities.groups.media',
        ],

        // Apparence, Extensions, Réglages
        'themes.manage' => [
            'label' => 'core.capabilities.items.themes__manage',
            'group' => 'core.capabilities.groups.appearance',
        ],
        'appearance.manage' => [
            'label' => 'core.capabilities.items.appearance__manage',
            'group' => 'core.capabilities.groups.appearance',
        ],
        'plugins.manage' => [
            'label' => 'core.capabilities.items.plugins__manage',
            'group' => 'core.capabilities.groups.plugins',
        ],
        'settings.manage' => [
            'label' => 'core.capabilities.items.settings__manage',
            'group' => 'core.capabilities.groups.settings',
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

        foreach ($registry as $key => $meta) {
            $registry[$key] = [
                'label' => LabelTranslator::resolve($meta['label']),
                'group' => LabelTranslator::resolve($meta['group']),
            ];
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
