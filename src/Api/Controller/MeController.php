<?php

declare(strict_types=1);

namespace Prime\Api\Controller;

use Prime\Auth\User;
use Prime\Auth\UserRepository;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\I18n\Translator;

/**
 * `GET /api/v1/me` describes the caller and the abilities its credentials carry;
 * `PATCH /api/v1/me` changes the display name and the interface language.
 */
final class MeController extends ApiController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly Translator $translator,
    ) {
    }

    public function show(Request $request): Response
    {
        $user = $this->currentUser($request);
        if ($user === null) {
            return $this->failure($request, 'unauthenticated', 401);
        }

        return $this->json(['data' => $this->payload($request, $user)]);
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request, User $user, array $overrides = []): array
    {
        $abilities = $request->attribute('api.abilities');

        return array_merge([
            'id' => $user->id,
            'email' => $user->email,
            'display_name' => $user->displayName,
            'username' => $user->username,
            'status' => $user->status,
            'locale' => $user->locale,
            'capabilities' => $user->capabilities,
            'abilities' => is_array($abilities) ? array_values($abilities) : [],
            'token_id' => $request->attribute('api.token_id'),
            'avatar_media_id' => $user->avatarMediaId,
            'last_login_at' => $user->lastLoginAt,
            'created_at' => $user->createdAt,
        ], $overrides);
    }

    public function update(Request $request): Response
    {
        $user = $this->currentUser($request);
        if ($user === null) {
            return $this->failure($request, 'unauthenticated', 401);
        }

        $body = $this->body($request);
        $fields = [];
        $errors = [];

        if (array_key_exists('display_name', $body)) {
            $name = is_string($body['display_name']) ? trim($body['display_name']) : '';
            if ($name === '' || mb_strlen($name) > 191) {
                $errors['display_name'] = t('api.validation.display_name');
            } else {
                $fields['display_name'] = $name;
            }
        }

        if (array_key_exists('locale', $body)) {
            $locale = is_string($body['locale']) ? $this->translator->normalize($body['locale']) : '';
            if ($locale === '' || !$this->translator->isSupported($locale)) {
                $errors['locale'] = t('api.validation.locale');
            } else {
                $fields['locale'] = $locale;
            }
        }

        if ($errors !== []) {
            return $this->validationFailed($request, $errors);
        }

        if ($fields !== []) {
            $this->users->updateFields($user->id, $fields);
        }

        return $this->json(['data' => $this->payload($request, $user, $fields)]);
    }
}
