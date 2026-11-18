<?php

declare(strict_types=1);

namespace Prime\Api\Controller;

use Prime\Http\Request;
use Prime\Http\Response;

/**
 * `GET /api/v1/me` — Utilisateur actuellement authentifié via le jeton API.
 */
final class MeController extends ApiController
{
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
}
