<?php

declare(strict_types=1);

namespace Prime\Admin\Controller;

use Prime\Admin\Notices;
use Prime\Auth\Auth;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Settings\Settings;
use Prime\Themes\ThemeManager;

/**
 * Handles dismissing persistent dashboard notices (`POST /admin/notices/{id}/dismiss`).
 */
final class NoticeController
{
    public function __construct(
        private readonly Notices $notices,
        private readonly Auth $auth,
        private readonly ?Settings $settings = null,
    ) {
    }

    public function dismiss(Request $request, string $id): Response
    {
        $user = $this->auth->user($request);
        if ($user === null) {
            return Response::json(['ok' => false, 'message' => 'Non authentifié.'], 401);
        }

        if ($id === ThemeManager::FALLBACK_NOTICE_ID) {
            $dismissed = $this->notices->remove($id);
            if ($this->settings !== null) {
                try {
                    $this->settings->delete(ThemeManager::SETTING_FALLBACK_ERROR);
                } catch (\Throwable) {
                    // Ignore storage error.
                }
            }
        } else {
            $dismissed = $this->notices->dismiss($id, $user->id);
        }

        if ($this->wantsJson($request)) {
            return Response::json(['ok' => $dismissed, 'id' => $id], $dismissed ? 200 : 400);
        }

        $referer = $request->header('referer', '/admin');
        $target = '/admin';
        if (is_string($referer) && $referer !== '') {
            $path = (string) parse_url($referer, PHP_URL_PATH);
            $query = (string) parse_url($referer, PHP_URL_QUERY);
            if (str_starts_with($path, '/admin')) {
                $target = $query !== '' ? $path . '?' . $query : $path;
            }
        }

        return Response::redirect($target);
    }

    private function wantsJson(Request $request): bool
    {
        $accept = strtolower((string) $request->header('accept', ''));
        $xhr = strtolower((string) $request->header('x-requested-with', ''));

        return str_contains($accept, 'application/json') || $xhr === 'xmlhttprequest';
    }
}
