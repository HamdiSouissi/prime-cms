<?php

declare(strict_types=1);

namespace Prime\I18n\Http;

use Prime\Auth\User;
use Prime\Auth\UserRepository;
use Prime\Hooks\Hooks;
use Prime\Http\Middleware\MiddlewareInterface;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Http\Session;
use Prime\I18n\LocaleFormatter;
use Prime\I18n\Translator;
use Prime\Settings\Settings;
use Throwable;

/**
 * Resolves the response locale before rendering and the page cache run.
 *
 * Priority: authenticated user preference (`pc_users.locale`) for `/admin/*` → the matching
 * language setting (`language.admin` / `language.site`) → `language.fallback` → `app.locale`.
 * The resolved locale is exposed to the rest of the pipeline through the `locale` request
 * attribute (used by the page cache key) and the request scope is restored afterwards.
 *
 * The middleware only depends on lightweight services: it reads the session identifier
 * directly instead of resolving the authentication guard, because the guard eagerly builds
 * the 2FA encrypter (which requires `APP_KEY`) and the rate limiter. Building those on every
 * public page — or on a fresh install where `.env` has no key yet — would make the whole
 * site fail just to choose a language.
 */
final class LocaleMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'locale';

    /** Session key written by the authentication guard at login (mirrors `Auth::SESSION_USER_ID`). */
    private const SESSION_USER_ID = '_auth.user_id';

    public function __construct(
        private readonly Translator $translator,
        private readonly LocaleFormatter $formatter,
        private readonly ?Settings $settings = null,
        private readonly ?Session $session = null,
        private readonly ?UserRepository $users = null,
        private readonly ?Hooks $hooks = null,
    ) {
    }

    /**
     * @param callable(Request): Response $next
     */
    public function process(Request $request, callable $next): Response
    {
        $previousLocale = $this->translator->locale();
        $previousTimezone = $this->formatter->timezone();

        $this->translator->setLocale($this->resolveLocale($request));

        $timezone = $this->setting('general.timezone');
        if (is_string($timezone) && trim($timezone) !== '') {
            $this->formatter->setTimezone(trim($timezone));
        }

        $locale = $this->translator->locale();
        $this->hooks?->emit('locale.resolved', $locale, $request);

        try {
            return $next($request->withAttributes([self::ATTRIBUTE => $locale]));
        } finally {
            $this->translator->setLocale($previousLocale);
            $this->formatter->setTimezone($previousTimezone);
        }
    }

    /**
     * Resolve the locale that should render a given request.
     */
    public function resolveLocale(Request $request): string
    {
        $path = '/' . trim($request->path(), '/');
        $isAdmin = $path === '/admin' || str_starts_with($path, '/admin/');

        if ($isAdmin) {
            $preferred = $this->userLocale();
            if ($preferred !== null) {
                return $preferred;
            }
        }

        $configured = $this->setting($isAdmin ? 'language.admin' : 'language.site');
        if ($this->isUsable($configured)) {
            return (string) $configured;
        }

        $fallback = $this->setting('language.fallback');
        if ($this->isUsable($fallback)) {
            return (string) $fallback;
        }

        return $this->translator->locale();
    }

    /**
     * Locale explicitly chosen by the signed-in visitor (profile preference).
     */
    private function userLocale(): ?string
    {
        if ($this->session === null || $this->users === null) {
            return null;
        }

        try {
            $userId = $this->session->get(self::SESSION_USER_ID);
            if (!is_int($userId) && !(is_string($userId) && ctype_digit($userId))) {
                return null;
            }

            $user = $this->users->find((int) $userId);
            if (!$user instanceof User || $user->status !== 'active') {
                return null;
            }

            return $this->isUsable($user->locale) ? $user->locale : null;
        } catch (Throwable) {
            // Session or database unavailable: fall back to the configured language.
            return null;
        }
    }

    /**
     * Only languages that actually ship a catalogue may drive rendering, so a stale or typoed
     * setting never produces an interface made of raw keys.
     */
    private function isUsable(mixed $locale): bool
    {
        return is_string($locale)
            && $this->translator->normalize($locale) !== ''
            && $this->translator->isSupported($locale);
    }

    private function setting(string $name): mixed
    {
        if ($this->settings === null) {
            return null;
        }

        try {
            return $this->settings->get($name);
        } catch (Throwable) {
            return null;
        }
    }
}
