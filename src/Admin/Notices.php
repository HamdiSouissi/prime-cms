<?php

declare(strict_types=1);

namespace Prime\Admin;

use Prime\Auth\User;
use Prime\Log\Logger;
use Prime\Settings\Settings;
use Throwable;

/**
 * Persistent dashboard notification service backed by `pc_settings` (`admin.notices`).
 *
 * Notices survive across requests until explicitly removed (e.g. when a faulty plugin is
 * reactivated or uninstalled) or dismissed by the user (when `dismissible = true`).
 * Per-user dismissals are stored under `admin.notices.dismissed.{userId}` so dismissing
 * a notice does not hide it for other administrators.
 */
final class Notices
{
    public const SETTINGS_KEY = 'admin.notices';
    private const DISMISSED_PREFIX = 'admin.notices.dismissed.';

    public function __construct(
        private readonly ?Settings $settings = null,
        private readonly ?Logger $logger = null,
    ) {
    }

    /**
     * Add or replace a persistent dashboard notice (keyed by `$notice->id`).
     */
    public function add(Notice $notice): void
    {
        $notices = $this->loadMap();
        $notices[$notice->id] = $notice;
        $this->saveMap($notices);
    }

    /**
     * Remove a persistent notice globally by ID.
     */
    public function remove(string $id): bool
    {
        $notices = $this->loadMap();
        if (!isset($notices[$id])) {
            return false;
        }

        unset($notices[$id]);
        $this->saveMap($notices);

        return true;
    }

    /**
     * Remove all persistent notices registered by a given source (e.g. plugin slug).
     */
    public function removeBySource(string $source): int
    {
        $notices = $this->loadMap();
        $removed = 0;

        foreach ($notices as $id => $notice) {
            if ($notice->source === $source) {
                unset($notices[$id]);
                $removed++;
            }
        }

        if ($removed > 0) {
            $this->saveMap($notices);
        }

        return $removed;
    }

    /**
     * Dismiss a dismissible notice for the specified user.
     */
    public function dismiss(string $id, int $userId): bool
    {
        $notices = $this->loadMap();
        if (!isset($notices[$id]) || !$notices[$id]->dismissible) {
            return false;
        }

        $dismissed = $this->dismissedForUser($userId);
        if (!in_array($id, $dismissed, true)) {
            $dismissed[] = $id;
            $this->saveDismissedForUser($userId, $dismissed);
        }

        return true;
    }

    /**
     * Return all stored notices in newest-first order.
     *
     * @return list<Notice>
     */
    public function all(): array
    {
        return array_reverse(array_values($this->loadMap()));
    }

    /**
     * Return notices visible to `$user` (matching capability scope and not dismissed).
     *
     * @return list<Notice>
     */
    public function forUser(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $dismissed = array_flip($this->dismissedForUser($user->id));
        $visible = [];

        foreach ($this->all() as $notice) {
            if ($notice->dismissible && isset($dismissed[$notice->id])) {
                continue;
            }

            if ($notice->capability === null) {
                if (!$user->isAdministrator()) {
                    continue;
                }
            } elseif (!$user->can($notice->capability)) {
                continue;
            }

            $visible[] = $notice;
        }

        return $visible;
    }

    /**
     * Convenience helper for adding a plugin-scoped notice cleanly.
     */
    public function addForSource(
        string $source,
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
        $this->add(new Notice(
            id: $id,
            type: $type,
            title: $title,
            message: $message,
            actionLabel: $actionLabel,
            actionUrl: $actionUrl,
            actionMethod: $actionMethod,
            capability: $capability,
            dismissible: $dismissible,
            source: $source,
        ));
    }

    /**
     * @return array<string, Notice>
     */
    private function loadMap(): array
    {
        if ($this->settings === null) {
            return [];
        }

        try {
            $raw = $this->settings->get(self::SETTINGS_KEY, []);
        } catch (Throwable) {
            return [];
        }

        if (!is_array($raw)) {
            return [];
        }

        $map = [];
        foreach ($raw as $index => $item) {
            $notice = Notice::fromArray($item);
            if ($notice === null) {
                $this->logger?->warning('Notification admin invalide ignorée lors du chargement.', [
                    'index' => $index,
                ]);
                continue;
            }
            $map[$notice->id] = $notice;
        }

        return $map;
    }

    /**
     * @param array<string, Notice> $map
     */
    private function saveMap(array $map): void
    {
        if ($this->settings === null) {
            return;
        }

        $serialized = [];
        foreach ($map as $notice) {
            $serialized[] = $notice->toArray();
        }

        try {
            $this->settings->set(self::SETTINGS_KEY, $serialized, autoload: true);
        } catch (Throwable) {
            // Gracefully ignore persistence errors before database installation completes.
        }
    }

    /**
     * @return list<string>
     */
    private function dismissedForUser(int $userId): array
    {
        if ($this->settings === null) {
            return [];
        }

        try {
            $raw = $this->settings->get(self::DISMISSED_PREFIX . $userId, []);
        } catch (Throwable) {
            return [];
        }

        if (!is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $entry) {
            if (is_string($entry) && $entry !== '') {
                $ids[] = $entry;
            }
        }

        return $ids;
    }

    /**
     * @param list<string> $ids
     */
    private function saveDismissedForUser(int $userId, array $ids): void
    {
        if ($this->settings === null) {
            return;
        }

        try {
            $this->settings->set(self::DISMISSED_PREFIX . $userId, array_values(array_unique($ids)), autoload: false);
        } catch (Throwable) {
            // Ignore when DB is unavailable.
        }
    }
}
