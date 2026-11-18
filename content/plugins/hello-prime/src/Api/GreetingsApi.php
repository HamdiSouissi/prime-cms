<?php

declare(strict_types=1);

namespace HelloPrime\Api;

use DateTimeImmutable;
use DateTimeZone;
use Prime\Api\ApiError;
use Prime\Auth\User;
use Prime\Database\Connection;
use Prime\Http\Request;
use Prime\Http\Response;

/**
 * Reference REST endpoints of the Hello Prime plugin:
 * `GET /api/hello-prime/v1/greetings` (public) and `POST` (`hello_prime.manage`).
 */
final class GreetingsApi
{
    private const TABLE = 'hello_prime_greetings';

    public function __construct(private readonly Connection $db)
    {
    }

    public function index(Request $request): Response
    {
        $rows = $this->db->table(self::TABLE)->orderBy('id', 'DESC')->limit(50)->get();

        return Response::json(['data' => array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'author' => (string) $row['author'],
            'message' => (string) $row['message'],
            'created_at' => (string) $row['created_at'],
        ], $rows)]);
    }

    public function store(Request $request): Response
    {
        $body = $request->attribute('api.body');
        $body = is_array($body) ? $body : [];
        $message = is_string($body['message'] ?? null) ? trim($body['message']) : '';

        if ($message === '' || mb_strlen($message) > 280) {
            return ApiError::response('validation_failed', 422, $request, [
                'fields' => ['message' => t('plugin::hello-prime.ui.message_invalid')],
            ]);
        }

        $user = $request->attribute('api.user');
        $author = is_string($body['author'] ?? null) && trim($body['author']) !== ''
            ? trim($body['author'])
            : ($user instanceof User ? $user->displayName : 'API');

        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $id = $this->db->table(self::TABLE)->insertGetId([
            'author' => mb_substr($author, 0, 80),
            'message' => $message,
            'created_at' => $now,
        ]);

        return Response::json(['data' => [
            'id' => (int) $id,
            'author' => mb_substr($author, 0, 80),
            'message' => $message,
            'created_at' => $now,
        ]], 201);
    }
}
