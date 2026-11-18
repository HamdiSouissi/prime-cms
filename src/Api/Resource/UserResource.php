<?php

declare(strict_types=1);

namespace Prime\Api\Resource;

use Prime\Auth\User;
use Prime\Site\UrlGenerator;

final class UserResource
{
    public function __construct(private readonly UrlGenerator $urlGenerator)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $user): array
    {
        return [
            'id' => $user->id,
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
            '_links' => [
                'self' => $this->urlGenerator->absolute('/api/v1/users/' . $user->id),
                'collection' => $this->urlGenerator->absolute('/api/v1/users'),
            ],
        ];
    }
}
