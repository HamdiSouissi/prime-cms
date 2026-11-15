<?php

declare(strict_types=1);

namespace Prime\Auth\Controller;

use Prime\Auth\Auth;
use Prime\Auth\Exception\PolicyException;
use Prime\Auth\Middleware\Authenticate;
use Prime\Auth\PasswordResetService;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Http\Session;
use Prime\Kernel\Application;
use Prime\Settings\Settings;
use Prime\View\View;
use Throwable;

/**
 * Handles login, 2FA verification challenge, logout, forgot-password, and reset-password flows.
 */
final class AuthController
{
    private const FORGOT_GENERIC_STATUS = 'Si un compte correspond à cette adresse, un e-mail vient d\'être envoyé.';

    public function __construct(
        private readonly Application $app,
        private readonly View $view,
        private readonly Auth $auth,
        private readonly PasswordResetService $passwordResets,
        private readonly Session $session,
    ) {
    }

    public function showLogin(Request $request): Response
    {
        if ($this->auth->check($request)) {
            return Response::redirect('/admin', 302);
        }

        if ($request->query('cancel_2fa') === '1') {
            $this->auth->clearPendingTwoFactor();
        }

        return Response::html($this->view->render('auth::login', [
            'siteName' => $this->siteName(),
            'email' => (string) $this->session->getFlash('auth_old_email', ''),
            'remember' => (bool) $this->session->getFlash('auth_old_remember', false),
            'error' => $this->session->getFlash('auth_error'),
            'status' => $this->session->getFlash('auth_status'),
        ]));
    }

    public function login(Request $request): Response
    {
        if ($this->auth->check($request)) {
            return Response::redirect('/admin', 302);
        }

        $email = trim((string) $request->body('email', ''));
        $password = (string) $request->body('password', '');
        $remember = in_array($request->body('remember'), ['1', 'true', 'on', true, 1], true);

        if ($email === '' || $password === '') {
            return Response::html($this->view->render('auth::login', [
                'siteName' => $this->siteName(),
                'email' => $email,
                'remember' => $remember,
                'error' => Auth::GENERIC_LOGIN_ERROR,
                'status' => null,
            ]), 422);
        }

        $result = $this->auth->attempt(
            email: $email,
            password: $password,
            ip: $request->ip(),
            remember: $remember,
            userAgent: $request->header('user-agent'),
        );

        if ($result->isLocked()) {
            $headers = $result->retryAfterSeconds > 0
                ? ['Retry-After' => (string) $result->retryAfterSeconds]
                : [];

            return Response::html($this->view->render('auth::login', [
                'siteName' => $this->siteName(),
                'email' => $email,
                'remember' => $remember,
                'error' => $result->message,
                'status' => null,
            ]), 429, $headers);
        }

        if ($result->requiresTwoFactor()) {
            return Response::redirect('/admin/login/two-factor', 302);
        }

        if ($result->isAuthenticated()) {
            return Response::redirect(Authenticate::pullIntendedUrl($this->session), 302);
        }

        return Response::html($this->view->render('auth::login', [
            'siteName' => $this->siteName(),
            'email' => $email,
            'remember' => $remember,
            'error' => $result->message ?? Auth::GENERIC_LOGIN_ERROR,
            'status' => null,
        ]), 422);
    }

    public function showTwoFactor(Request $request): Response
    {
        if ($this->auth->check($request)) {
            return Response::redirect('/admin', 302);
        }

        if (!$this->auth->hasPendingTwoFactor()) {
            $this->session->flash('auth_error', 'Votre session de vérification a expiré. Veuillez vous reconnecter.');

            return Response::redirect('/admin/login', 302);
        }

        return Response::html($this->view->render('auth::two-factor', [
            'siteName' => $this->siteName(),
            'error' => $this->session->getFlash('auth_2fa_error'),
        ]));
    }

    public function verifyTwoFactor(Request $request): Response
    {
        if ($this->auth->check($request)) {
            return Response::redirect('/admin', 302);
        }

        $code = trim((string) $request->body('code', ''));
        $result = $this->auth->completeTwoFactor($code, $request->ip());

        if ($result->isAuthenticated()) {
            return Response::redirect(Authenticate::pullIntendedUrl($this->session), 302);
        }

        if ($result->isTwoFactorExpired()) {
            $this->session->flash('auth_error', $result->message ?? 'Session de vérification expirée.');

            return Response::redirect('/admin/login', 302);
        }

        return Response::html($this->view->render('auth::two-factor', [
            'siteName' => $this->siteName(),
            'error' => $result->message ?? 'Code de vérification invalide.',
        ]), 422);
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout($request);
        $this->session->flash('auth_status', 'Vous êtes maintenant déconnecté.');

        return Response::redirect('/admin/login', 302);
    }

    public function showForgotPassword(Request $request): Response
    {
        if ($this->auth->check($request)) {
            return Response::redirect('/admin', 302);
        }

        return Response::html($this->view->render('auth::forgot-password', [
            'siteName' => $this->siteName(),
            'email' => '',
            'status' => $this->session->getFlash('auth_forgot_status'),
            'error' => $this->session->getFlash('auth_forgot_error'),
        ]));
    }

    public function sendResetLink(Request $request): Response
    {
        if ($this->auth->check($request)) {
            return Response::redirect('/admin', 302);
        }

        $email = trim((string) $request->body('email', ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            // Still avoid revealing whether an account exists, only ask for a valid email syntax.
            return Response::html($this->view->render('auth::forgot-password', [
                'siteName' => $this->siteName(),
                'email' => $email,
                'status' => null,
                'error' => 'Saisissez une adresse e-mail valide.',
            ]), 422);
        }

        $outcome = $this->passwordResets->requestPublicReset(
            email: $email,
            ip: $request->ip(),
            appUrl: $this->siteUrl(),
            siteName: $this->siteName(),
        );

        if ($outcome['rate_limited']) {
            $minutes = max(1, (int) ceil($outcome['retry_after'] / 60));
            $unit = $minutes > 1 ? 'minutes' : 'minute';

            return Response::html($this->view->render('auth::forgot-password', [
                'siteName' => $this->siteName(),
                'email' => $email,
                'status' => null,
                'error' => sprintf('Trop de demandes. Réessayez dans %d %s.', $minutes, $unit),
            ]), 429, $outcome['retry_after'] > 0 ? ['Retry-After' => (string) $outcome['retry_after']] : []);
        }

        return Response::html($this->view->render('auth::forgot-password', [
            'siteName' => $this->siteName(),
            'email' => '',
            'status' => self::FORGOT_GENERIC_STATUS,
            'error' => null,
        ]));
    }

    public function showResetPassword(Request $request, string $token): Response
    {
        if ($this->auth->check($request)) {
            return Response::redirect('/admin', 302);
        }

        $valid = $this->passwordResets->validateToken($token);
        if ($valid === null) {
            return Response::html($this->view->render('auth::reset-expired', [
                'siteName' => $this->siteName(),
            ]), 410);
        }

        return Response::html($this->view->render('auth::reset-password', [
            'siteName' => $this->siteName(),
            'token' => $token,
            'errors' => [],
        ]));
    }

    public function resetPassword(Request $request, string $token): Response
    {
        if ($this->auth->check($request)) {
            return Response::redirect('/admin', 302);
        }

        $valid = $this->passwordResets->validateToken($token);
        if ($valid === null) {
            return Response::html($this->view->render('auth::reset-expired', [
                'siteName' => $this->siteName(),
            ]), 410);
        }

        $password = (string) $request->body('password', '');
        $confirmation = (string) $request->body('password_confirmation', '');

        $errors = [];
        if ($password !== $confirmation) {
            $errors['password_confirmation'] = 'Les deux mots de passe ne correspondent pas.';
        }

        if ($errors === []) {
            try {
                $this->passwordResets->resetPassword($token, $password, $this->siteName());
                $this->session->flash('auth_status', 'Votre mot de passe a été modifié. Vous pouvez maintenant vous connecter.');

                return Response::redirect('/admin/login', 302);
            } catch (PolicyException $exception) {
                $errors['password'] = $exception->getMessage();
            }
        }

        return Response::html($this->view->render('auth::reset-password', [
            'siteName' => $this->siteName(),
            'token' => $token,
            'errors' => $errors,
        ]), 422);
    }

    private function siteName(): string
    {
        try {
            if ($this->app->container()->has(Settings::class)) {
                /** @var Settings $settings */
                $settings = $this->app->get(Settings::class);
                $name = $settings->get('site_name');
                if (is_string($name) && trim($name) !== '') {
                    return trim($name);
                }
            }
        } catch (Throwable) {
            // Fall back to config name when settings table isn't available yet.
        }

        return (string) $this->app->config()->get('app.name', 'Prime CMS');
    }

    private function siteUrl(): string
    {
        try {
            if ($this->app->container()->has(Settings::class)) {
                /** @var Settings $settings */
                $settings = $this->app->get(Settings::class);
                $url = $settings->get('site_url');
                if (is_string($url) && trim($url) !== '') {
                    return rtrim(trim($url), '/');
                }
            }
        } catch (Throwable) {
            // Fall back to APP_URL config.
        }

        return rtrim((string) $this->app->config()->get('app.url', 'http://localhost:8080'), '/');
    }
}
