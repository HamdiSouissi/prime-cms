<?php

declare(strict_types=1);

namespace Prime\Api\Controller;

use Prime\Auth\UserRepository;
use Prime\Http\Request;
use Prime\Http\Response;

/**
 * `GET /api/v1/me` — Utilisateur actuellement authentifié via le jeton API.
 * `PATCH /api/v1/me` — Modifie le nom affiché et la langue.
 */
final class MeController extends ApiController
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function show(Request $request): Response
    {
        $authResult = $this->requireAuth($request);
        if ($authResult instanceof Response) {
            return $authResult;
        }

        $user = $this->currentUser($request);

        if ($user === null) {
            return $this->unauthorized('Utilisateur non authentifié.');
        }

        $data = [
            'id' => $user->id,
            'email' => $user->email,
            'display_name' => $user->displayName,
            'username' => $user->username,
            'status' => $user->status,
            'locale' => $user->locale,
            'roles' => array_map(
                static fn (object $role): string => $role->name,
                $user->roles,
            ),
            'capabilities' => $user->capabilities,
            'avatar_media_id' => $user->avatarMediaId,
            'last_login_at' => $user->lastLoginAt,
            'created_at' => $user->createdAt,
        ];

        return $this->json(['data' => $data]);
    }

    public function update(Request $request): Response
    {
        $authResult = $this->requireAuth($request);
        if ($authResult instanceof Response) {
            return $authResult;
        }

        $user = $this->currentUser($request);
        if ($user === null) {
            return $this->unauthorized('Utilisateur non authentifié.');
        }

        $locale = $user->locale;
        $body = $request->attribute('api.body');
        if (!is_array($body)) {
            return $this->error('invalid_request', [], 400, 'invalid_request', $locale);
        }

        $fields = [];

        if (isset($body['display_name'])) {
            $displayName = trim((string) $body['display_name']);
            if ($displayName === '') {
                return $this->validationFailed([
                    'display_name' => 'Le nom affiché est obligatoire.',
                ]);
            }
            if (mb_strlen($displayName) > 80) {
                return $this->validationFailed([
                    'display_name' => 'Le nom affiché ne peut pas dépasser 80 caractères.',
                ]);
            }
            $fields['display_name'] = $displayName;
        }

        if (isset($body['locale'])) {
            $newLocale = (string) $body['locale'];
            if (!in_array($newLocale, ['fr', 'en'], true)) {
                return $this->validationFailed([
                    'locale' => 'Langue non supportée.',
                ]);
            }
            $fields['locale'] = $newLocale;
        }

        if ($fields === []) {
            return $this->error('invalid_request', [], 400, 'invalid_request', $locale);
        }

        $this->users->updateFields($user->id, $fields);

        $updated = $this->currentUser($request);
        if ($updated === null) {
            return $this->unauthorized('Utilisateur non authentifié.');
        }

        return $this->json([
            'data' => [
                'id' => $updated->id,
                'display_name' => $updated->displayName,
                'locale' => $updated->locale,
            ],
        ]);
    }
}
