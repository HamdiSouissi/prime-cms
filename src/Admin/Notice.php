<?php

declare(strict_types=1);

namespace Prime\Admin;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Prime\Admin\Data\FilterValueHelper;

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
     * Hydrate and validate a Notice from stored JSON or filter data (returns null if malformed).
     */
    public static function fromArray(mixed $data): ?self
    {
        if ($data instanceof self) {
            return $data;
        }

        if (!is_array($data)) {
            return null;
        }

        $id = $data['id'] ?? null;
        $type = $data['type'] ?? null;
        $title = $data['title'] ?? null;
        $message = $data['message'] ?? null;

        if (
            !is_string($id) || !is_string($type) || !is_string($title) || !is_string($message)
            || trim($title) === '' || trim($message) === ''
            || !in_array($type, self::ALLOWED_TYPES, true)
            || preg_match('/^[a-zA-Z0-9._-]{1,120}$/', $id) !== 1
        ) {
            return null;
        }

        $actionLabel = null;
        if (array_key_exists('action_label', $data) && $data['action_label'] !== null) {
            if (!is_string($data['action_label'])) {
                return null;
            }
            $actionLabel = trim($data['action_label']) !== '' ? trim($data['action_label']) : null;
        }

        $actionUrl = null;
        if (array_key_exists('action_url', $data) && $data['action_url'] !== null && $data['action_url'] !== '') {
            if (!is_string($data['action_url']) || !FilterValueHelper::isSafeUrl($data['action_url'])) {
                return null;
            }
            $actionUrl = trim($data['action_url']);
        }

        $actionMethod = 'GET';
        if (array_key_exists('action_method', $data) && $data['action_method'] !== null && $data['action_method'] !== '') {
            if (!is_string($data['action_method']) || !in_array(strtoupper($data['action_method']), self::ALLOWED_METHODS, true)) {
                return null;
            }
            $actionMethod = strtoupper($data['action_method']);
        }

        $capability = isset($data['capability']) && is_string($data['capability']) && trim($data['capability']) !== ''
            ? trim($data['capability'])
            : null;
        $dismissible = array_key_exists('dismissible', $data) ? (bool) $data['dismissible'] : true;
        $source = isset($data['source']) && is_string($data['source']) && trim($data['source']) !== ''
            ? trim($data['source'])
            : null;
        $createdAt = isset($data['created_at']) && is_string($data['created_at']) && trim($data['created_at']) !== ''
            ? trim($data['created_at'])
            : null;

        return new self(
            id: $id,
            type: $type,
            title: trim($title),
            message: trim($message),
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
