<?php

declare(strict_types=1);

namespace PrimeStarter\Api;

use Prime\Api\ApiError;
use Prime\Auth\User;
use Prime\Http\Request;
use Prime\Http\Response;
use PrimeStarter\Markdown;
use PrimeStarter\NoteRepository;
use PrimeStarter\Plugin;

/**
 * `/api/prime-starter/v1/notes`: anyone reads the public notes; a caller holding
 * `prime_starter.manage` also sees the private ones and may create, change and delete.
 *
 * @phpstan-import-type Note from NoteRepository
 */
final class NotesApi
{
    public function __construct(private readonly NoteRepository $notes)
    {
    }

    public function index(Request $request): Response
    {
        $notes = $this->notes->all(publicOnly: !$this->canManage($request));

        return Response::json(['data' => array_map(static fn (array $note): array => self::resource($note), $notes)]);
    }

    public function show(Request $request, string $id): Response
    {
        $note = $this->notes->find((int) $id);
        if ($note === null || (!$note['public'] && !$this->canManage($request))) {
            return ApiError::response('not_found', 404, $request);
        }

        return Response::json(['data' => self::resource($note)]);
    }

    public function store(Request $request): Response
    {
        [$values, $errors] = NoteRepository::validate($this->body($request));
        if ($errors !== []) {
            return ApiError::response('validation_failed', 422, $request, ['fields' => $errors]);
        }

        $user = $request->attribute('api.user');
        $id = $this->notes->create($values, $user instanceof User ? $user->id : null);
        $note = $this->notes->find($id);

        return Response::json(['data' => $note !== null ? self::resource($note) : ['id' => $id]], 201, [
            'Location' => '/api/prime-starter/v1/notes/' . $id,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $note = $this->notes->find((int) $id);
        if ($note === null) {
            return ApiError::response('not_found', 404, $request);
        }

        // PATCH: what is not sent keeps its value.
        [$values, $errors] = NoteRepository::validate($this->body($request) + $note);
        if ($errors !== []) {
            return ApiError::response('validation_failed', 422, $request, ['fields' => $errors]);
        }

        $this->notes->update($note['id'], $values);

        return Response::json(['data' => self::resource($this->notes->find($note['id']) ?? $note)]);
    }

    public function destroy(Request $request, string $id): Response
    {
        if (!$this->notes->delete((int) $id)) {
            return ApiError::response('not_found', 404, $request);
        }

        return new Response('', 204);
    }

    /**
     * @param Note $note
     * @return array<string, mixed>
     */
    public static function resource(array $note): array
    {
        return [
            'id' => $note['id'],
            'title' => $note['title'],
            'body' => $note['body'],
            'html' => Markdown::toHtml($note['body']),
            'color' => $note['color'],
            'pinned' => $note['pinned'],
            'public' => $note['public'],
            'updated_at' => $note['updated_at'] !== '' ? str_replace(' ', 'T', $note['updated_at']) . 'Z' : null,
        ];
    }

    private function canManage(Request $request): bool
    {
        $user = $request->attribute('api.user');

        return $user instanceof User && $user->can(Plugin::CAPABILITY);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        $body = $request->attribute('api.body');

        return is_array($body) ? $body : [];
    }
}
