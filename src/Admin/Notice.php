<?php

declare(strict_types=1);

namespace Prime\Admin;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Immutable value object representing a persistent admin dashboard notice.
 */
final readonly class Notice
{
    public const TYPE_INFO = 'info';
    public const TYPE_SUCCESS = 'success';
    public const TYPE_WARNING = 'warning';
    public const TYPE_DANGER = 'danger';

    public const ALLOWED_TYPES = [
        self::TYPE_INFO,
        self::TYPE_SUCCESS,
        self::TYPE_WARNING,
        self::TYPE_DANGER,
    ];

    public const ALLOWED_METHODS = ['GET', 'POST'];

    public string $createdAt;

    public function __construct(
        public string $id,
        public string $type,
        public string $title,
        public string $message,
        public ?string $actionLabel = null,
        public ?string $actionUrl = null,
        public string $actionMethod = 'GET',
        public ?string $capability = null,
        public bool $dismissible = true,
        public ?string $source = null,
        ?string $createdAt = null,
    ) {
        if (preg_match('/^[a-zA-Z0-9._-]{1,120}$/', $id) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid notice id [%s].', $id));
        }

        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException(sprintf('Invalid notice type [%s].', $type));
        }

        $method = strtoupper($actionMethod);
        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            throw new InvalidArgumentException(sprintf('Invalid notice action method [%s].', $actionMethod));
        }

        $this->createdAt = $createdAt !== null && $createdAt !== ''
            ? $createdAt
            : (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->message,
            'action_label' => $this->actionLabel,
            'action_url' => $this->actionUrl,
            'action_method' => strtoupper($this->actionMethod),
            'capability' => $this->capability,
            'dismissible' => $this->dismissible,
            'source' => $this->source,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * Hydrate a Notice from stored JSON data (returns null if malformed).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $id = $data['id'] ?? null;
        $type = $data['type'] ?? null;
        $title = $data['title'] ?? null;
        $message = $data['message'] ?? null;

        if (
            !is_string($id) || !is_string($type) || !is_string($title) || !is_string($message)
            || !in_array($type, self::ALLOWED_TYPES, true)
            || preg_match('/^[a-zA-Z0-9._-]{1,120}$/', $id) !== 1
        ) {
            return null;
        }

        $actionLabel = isset($data['action_label']) && is_string($data['action_label']) && $data['action_label'] !== ''
            ? $data['action_label']
            : null;
        $actionUrl = isset($data['action_url']) && is_string($data['action_url']) && $data['action_url'] !== ''
            ? $data['action_url']
            : null;
        $actionMethod = isset($data['action_method']) && is_string($data['action_method'])
            && in_array(strtoupper($data['action_method']), self::ALLOWED_METHODS, true)
            ? strtoupper($data['action_method'])
            : 'GET';
        $capability = isset($data['capability']) && is_string($data['capability']) && $data['capability'] !== ''
            ? $data['capability']
            : null;
        $dismissible = array_key_exists('dismissible', $data) ? (bool) $data['dismissible'] : true;
        $source = isset($data['source']) && is_string($data['source']) && $data['source'] !== ''
            ? $data['source']
            : null;
        $createdAt = isset($data['created_at']) && is_string($data['created_at']) && $data['created_at'] !== ''
            ? $data['created_at']
            : null;

        return new self(
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
            createdAt: $createdAt,
        );
    }
}
