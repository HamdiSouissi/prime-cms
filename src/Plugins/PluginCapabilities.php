<?php

declare(strict_types=1);

namespace Prime\Plugins;

use InvalidArgumentException;
use Prime\Auth\Capabilities;
use Prime\Hooks\Hooks;

/**
 * Capability registration helper exposed via `PluginContext::capabilities()`.
 *
 * Registers capabilities into the core `auth.capabilities` filter automatically grouped
 * under the plugin's display name so they appear in the Roles matrix seamlessly.
 */
final class PluginCapabilities
{
    /**
     * @var array<string, array{label: string, group: string}>
     */
    private array $registered = [];

    private bool $hookAttached = false;

    public function __construct(
        private readonly Hooks $hooks,
        private readonly Capabilities $capabilities,
        private readonly string $pluginSlug,
        private readonly string $defaultGroup,
    ) {
    }

    /**
     * Register a capability (e.g. `hello_prime.manage`) grouped under this plugin's name.
     */
    public function register(string $key, string $label, ?string $group = null): self
    {
        $cleanKey = trim($key);
        if ($cleanKey === '' || $cleanKey === Capabilities::WILDCARD || preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $cleanKey) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Invalid plugin capability key [%s]. Expected dot notation like "my_plugin.manage".', $key),
            );
        }

        $this->registered[$cleanKey] = [
            'label' => trim($label) !== '' ? trim($label) : $cleanKey,
            'group' => $group !== null && trim($group) !== '' ? trim($group) : $this->defaultGroup,
        ];

        $this->ensureHookAttached();

        return $this;
    }

    /**
     * Register multiple capabilities at once (`['key' => 'French label']`).
     *
     * @param array<string, string> $map
     */
    public function registerMany(array $map, ?string $group = null): self
    {
        foreach ($map as $key => $label) {
            $this->register($key, $label, $group);
        }

        return $this;
    }

    public function registry(): Capabilities
    {
        return $this->capabilities;
    }

    private function ensureHookAttached(): void
    {
        if ($this->hookAttached) {
            return;
        }

        $this->hookAttached = true;
        $this->hooks->filter('auth.capabilities', function (mixed $caps): mixed {
            if (!is_array($caps)) {
                return $caps;
            }

            foreach ($this->registered as $key => $meta) {
                $caps[$key] = $meta;
            }

            return $caps;
        }, priority: 10, owner: $this->pluginSlug);
    }
}
