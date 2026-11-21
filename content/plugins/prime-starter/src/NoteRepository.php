<?php

declare(strict_types=1);

namespace PrimeStarter;

use DateTimeImmutable;
use DateTimeZone;
use Prime\Database\Connection;

/**
 * Notes of the starter plugin, stored in its own table (`{prefix}prime_starter_notes`).
 *
 * @phpstan-type Note array{id: int, title: string, body: string, color: string, pinned: bool, public: bool, author_id: ?int, created_at: string, updated_at: string}
 * @phpstan-type NoteInput array{title: string, body: string, color: string, pinned: bool, public: bool}
 */
final class NoteRepository
{
    public const TABLE = 'prime_starter_notes';

    public const COLORS = ['sand', 'mint', 'sky', 'rose'];

    public const TITLE_MAX = 160;

    public const BODY_MAX = 20000;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Pinned notes first, then the most recently changed.
     *
     * @return list<Note>
     */
    public function all(bool $publicOnly = false, int $limit = 200): array
    {
        $query = $this->db->table(self::TABLE);
        if ($publicOnly) {
            $query->where('public', '=', 1);
        }

        $rows = $query->orderBy('pinned', 'DESC')->orderBy('updated_at', 'DESC')->orderBy('id', 'DESC')->limit($limit)->get();

        return array_map(static fn (array $row): array => self::hydrate($row), $rows);
    }

    /**
     * @return Note|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->table(self::TABLE)->where('id', '=', $id)->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function count(): int
    {
        return $this->db->table(self::TABLE)->count();
    }

    /**
     * Notes changed since `$since`, for the weekly digest.
     *
     * @return list<Note>
     */
    public function changedSince(DateTimeImmutable $since): array
    {
        $rows = $this->db->table(self::TABLE)
            ->where('updated_at', '>=', $since->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'))
            ->orderBy('updated_at', 'DESC')
            ->limit(50)
            ->get();

        return array_map(static fn (array $row): array => self::hydrate($row), $rows);
    }

    /**
     * Validate raw input. Returns the clean values and the errors keyed by field.
     *
     * @param array<string, mixed> $input
     * @return array{0: NoteInput, 1: array<string, string>}
     */
    public static function validate(array $input): array
    {
        $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';
        $body = is_string($input['body'] ?? null) ? trim(str_replace("\r\n", "\n", $input['body'])) : '';
        $color = is_string($input['color'] ?? null) && in_array($input['color'], self::COLORS, true) ? $input['color'] : self::COLORS[0];

        $errors = [];
        if ($title === '') {
            $errors['title'] = t('plugin::prime-starter.validation.title_required');
        } elseif (mb_strlen($title) > self::TITLE_MAX) {
            $errors['title'] = t('plugin::prime-starter.validation.title_max', ['max' => self::TITLE_MAX]);
        }
        if (mb_strlen($body) > self::BODY_MAX) {
            $errors['body'] = t('plugin::prime-starter.validation.body_max', ['max' => self::BODY_MAX]);
        }

        return [[
            'title' => $title,
            'body' => $body,
            'color' => $color,
            'pinned' => self::truthy($input['pinned'] ?? false),
            'public' => self::truthy($input['public'] ?? false),
        ], $errors];
    }

    /**
     * @param NoteInput $values
     */
    public function create(array $values, ?int $authorId): int
    {
        $now = self::now();

        return $this->db->table(self::TABLE)->insertGetId([
            'title' => $values['title'],
            'body' => $values['body'],
            'color' => $values['color'],
            'pinned' => $values['pinned'] ? 1 : 0,
            'public' => $values['public'] ? 1 : 0,
            'author_id' => $authorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param NoteInput $values
     */
    public function update(int $id, array $values): void
    {
        $this->db->table(self::TABLE)->where('id', '=', $id)->update([
            'title' => $values['title'],
            'body' => $values['body'],
            'color' => $values['color'],
            'pinned' => $values['pinned'] ? 1 : 0,
            'public' => $values['public'] ? 1 : 0,
            'updated_at' => self::now(),
        ]);
    }

    public function delete(int $id): bool
    {
        return $this->db->table(self::TABLE)->where('id', '=', $id)->delete() > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return Note
     */
    public static function hydrate(array $row): array
    {
        $authorId = $row['author_id'] ?? null;
        $color = $row['color'] ?? null;

        return [
            'id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            'title' => is_string($row['title'] ?? null) ? $row['title'] : '',
            'body' => is_string($row['body'] ?? null) ? $row['body'] : '',
            'color' => is_string($color) && in_array($color, self::COLORS, true) ? $color : self::COLORS[0],
            'pinned' => self::truthy($row['pinned'] ?? false),
            'public' => self::truthy($row['public'] ?? false),
            'author_id' => is_numeric($authorId) ? (int) $authorId : null,
            'created_at' => is_string($row['created_at'] ?? null) ? $row['created_at'] : '',
            'updated_at' => is_string($row['updated_at'] ?? null) ? $row['updated_at'] : '',
        ];
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'on' || $value === 'true';
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
