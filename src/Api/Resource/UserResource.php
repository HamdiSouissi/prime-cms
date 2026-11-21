<?php

declare(strict_types=1);

namespace Prime\Api\Resource;

use Prime\Auth\Role;
use Prime\Auth\User;
use Prime\Site\UrlGenerator;

/**
 * Public representation of an account. Never carries a password hash, a 2FA secret, a
 * recovery code or a token; the e-mail is shown to the account itself and to `users.view`.
 */
final class UserResource
{
    public function __construct(
        private readonly UrlGenerator $urls,
        private readonly ResourceFilter $filter,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $user, ?User $viewer): array
    {
        $data = [
            'id' => $user->id,
            'username' => $user->username,
            'display_name' => $user->displayName,
            'locale' => $user->locale,
            'status' => $user->status,
            'roles' => array_map(static fn (Role $role): string => $role->slug, $user->roles),
            'avatar_media_id' => $user->avatarMediaId,
            'created_at' => $user->createdAt,
            '_links' => [
                'self' => $this->urls->absolute('/api/v1/users/' . $user->id),
                'collection' => $this->urls->absolute('/api/v1/users'),
            ],
        ];

        if ($viewer !== null && ($viewer->id === $user->id || $viewer->can('users.view'))) {
            $data['email'] = $user->email;
            $data['last_login_at'] = $user->lastLoginAt;
        }

        return $this->filter->apply('user', $data, $user, $viewer);
    }
}
