<?php

declare(strict_types=1);

namespace Prime\Plugins;

use Prime\Admin\Notice;
use Prime\Admin\Notices;

/**
 * Plugin-scoped persistent dashboard notices facade (`PluginContext::notices()`).
 * Automatically tags every notice with `source = $slug` so uninstalling the plugin
 * removes all of its notifications cleanly.
 */
final class PluginNotices
{
    public function __construct(
        private readonly Notices $notices,
        private readonly string $slug,
    ) {
    }

    public function add(
        string $id,
        string $type,
        string $title,
        string $message,
        ?string $actionLabel = null,
        ?string $actionUrl = null,
        string $actionMethod = 'GET',
        ?string $capability = null,
        bool $dismissible = true,
    ): void {
        $fullId = str_starts_with($id, 'plugin.' . $this->slug . '.')
            ? $id
            : 'plugin.' . $this->slug . '.' . ltrim($id, '.');

        $this->notices->add(new Notice(
            id: $fullId,
            type: $type,
            title: $title,
            message: $message,
            actionLabel: $actionLabel,
            actionUrl: $actionUrl,
            actionMethod: $actionMethod,
            capability: $capability,
            dismissible: $dismissible,
            source: $this->slug,
        ));
    }

    public function remove(string $id): bool
    {
        $fullId = str_starts_with($id, 'plugin.' . $this->slug . '.')
            ? $id
            : 'plugin.' . $this->slug . '.' . ltrim($id, '.');

        return $this->notices->remove($fullId);
    }

    public function clearAll(): int
    {
        return $this->notices->removeBySource($this->slug);
    }
}
