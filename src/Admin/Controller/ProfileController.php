<?php

declare(strict_types=1);

namespace Prime\Admin\Controller;

use Prime\Admin\AdminContext;
use Prime\Auth\Auth;
use Prime\Auth\PasswordResetService;
use Prime\Auth\TwoFactorService;
use Prime\Auth\User;
use Prime\Auth\UserRepository;
use Prime\Database\Connection;
use Prime\Http\Exception\ForbiddenException;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Http\Session;
use Prime\Kernel\Application;
use Prime\Security\Hasher;
use Prime\Security\PasswordPolicy;
use Prime\Settings\Settings;
use Prime\Validation\Validator;
use Prime\View\View;
use Throwable;

/**
 * Self-service account & security controller (`/admin/profile`), accessible to any authenticated user.
 */
final class ProfileController
{
    private const SESSION_2FA_PENDING_SECRET = '_profile.2fa_pending_secret';

    private const FLASH_RECOVERY_CODES = '_profile.recovery_codes_once';

    /**
     * @var array<string, string>
     */
    private const LOCALE_OPTIONS = [
        'fr' => 'Français (fr)',
        'en' => 'English (en)',
        'it' => 'Italiano (it)',
    ];

    public function __construct(
        private readonly Application $app,
        private readonly View $view,
        private readonly AdminContext $context,
        private readonly Auth $auth,
        private readonly UserRepository $users,
        private readonly TwoFactorService $twoFactor,
        private readonly PasswordResetService $passwordResets,
        private readonly Session $session,
        private readonly Connection $connection,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly Hasher $hasher,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->requireUser($request);
        $activeTab = $request->query('tab') === 'security' ? 'security' : (string) $this->session->getFlash('_profile.tab', 'info');

        return Response::html($this->renderProfile($request, $user, $activeTab));
    }

    public function updateInfo(Request $request): Response
    {
        $user = $this->requireUser($request);
        $displayName = trim((string) $request->body('display_name', ''));
        $email = UserRepository::normalizeEmail((string) $request->body('email', ''));
        $locale = trim((string) $request->body('locale', $user->locale));
        $currentPassword = (string) $request->body('current_password_email', '');

        $validator = Validator::make(
            data: [
                'display_name' => $displayName,
                'email' => $email,
                'locale' => $locale,
            ],
            rules: [
                'display_name' => 'required|string|between:2,100',
                'email' => 'required|email|unique:users,email,' . $user->id,
                'locale' => 'required|in:fr,en,it',
            ],
            labels: [
                'display_name' => 'Nom affiché',
                'email' => 'Adresse e-mail',
                'locale' => 'Langue de l\'interface',
            ],
            connection: $this->connection,
        );

        $errors = $validator->errors();

        $emailChanged = $email !== $user->email;
        if ($emailChanged && !$this->verifyCurrentPassword($user->id, $currentPassword)) {
            $errors['current_password_email'] = 'Saisissez votre mot de passe actuel pour confirmer le changement d\'adresse e-mail.';
        }

        if ($errors !== []) {
            return Response::html($this->renderProfile($request, $user, 'info', infoErrors: $errors, oldInfo: [
                'display_name' => $displayName,
                'email' => $email,
                'locale' => $locale,
            ]), 422);
        }

        $this->users->updateFields($user->id, [
            'display_name' => $displayName,
            'email' => $email,
            'locale' => $locale,
        ]);

        $this->auth->resetCache();
        $this->context->flashToast('Vos informations personnelles ont été enregistrées.', 'success');

        return Response::redirect('/admin/profile', 302);
    }

    public function updatePassword(Request $request): Response
    {
        $user = $this->requireUser($request);
        $currentPassword = (string) $request->body('current_password', '');
        $newPassword = (string) $request->body('password', '');
        $confirmation = (string) $request->body('password_confirmation', '');

        $errors = [];
        if (!$this->verifyCurrentPassword($user->id, $currentPassword)) {
            $errors['current_password'] = 'Votre mot de passe actuel est incorrect.';
        }

        $validator = Validator::make(
            data: [
                'password' => $newPassword,
                'password_confirmation' => $confirmation,
            ],
            rules: [
                'password' => 'required|confirmed|password',
            ],
            labels: [
                'password' => 'Nouveau mot de passe',
            ],
            passwordPolicy: $this->passwordPolicy,
            passwordContext: [
                'email' => $user->email,
                'display_name' => $user->displayName,
                'site_name' => $this->siteName(),
            ],
        );

        $errors = array_merge($validator->errors(), $errors);

        if ($errors !== []) {
            return Response::html($this->renderProfile($request, $user, 'security', passwordErrors: $errors), 422);
        }

        // Increment `session_version`, revoke all remember tokens, AND keep current session alive!
        $nextVersion = $this->users->updatePassword($user->id, $newPassword);
        $this->auth->syncCurrentSessionVersion($nextVersion);
        $this->passwordResets->sendPasswordChangedEmail($user, $this->siteName());

        $this->session->flash('_profile.tab', 'security');
        $this->context->flashToast('Votre mot de passe a été modifié et vos autres appareils ont été déconnectés.', 'success');

        return Response::redirect('/admin/profile?tab=security', 302);
    }

    public function startTwoFactor(Request $request): Response
    {
        $user = $this->requireUser($request);
        if ($user->hasTwoFactor()) {
            return Response::redirect('/admin/profile?tab=security', 302);
        }

        $setup = $this->twoFactor->generateSetupSecret($user, $this->siteName());
        $this->session->set(self::SESSION_2FA_PENDING_SECRET, $setup);
        $this->session->flash('_profile.tab', 'security');

        return Response::redirect('/admin/profile?tab=security', 302);
    }

    public function cancelTwoFactorSetup(Request $request): Response
    {
        $this->requireUser($request);
        $this->session->remove(self::SESSION_2FA_PENDING_SECRET);
        $this->session->flash('_profile.tab', 'security');

        return Response::redirect('/admin/profile?tab=security', 302);
    }

    public function confirmTwoFactor(Request $request): Response
    {
        $user = $this->requireUser($request);
        $pending = $this->session->get(self::SESSION_2FA_PENDING_SECRET);

        if (!is_array($pending) || !is_string($pending['secret'] ?? null)) {
            $this->context->flashToast('Aucune configuration 2FA en cours. Recommencez l\'activation.', 'warning');

            return Response::redirect('/admin/profile?tab=security', 302);
        }

        $code = trim((string) $request->body('code', ''));
        $recoveryCodes = $this->twoFactor->confirmAndEnable($user, $pending['secret'], $code);

        if ($recoveryCodes === null) {
            return Response::html($this->renderProfile(
                $request,
                $user,
                'security',
                twoFactorError: 'Code TOTP invalide. Vérifiez l\'heure de votre appareil et réessayez.',
            ), 422);
        }

        $this->session->remove(self::SESSION_2FA_PENDING_SECRET);
        $this->session->flash(self::FLASH_RECOVERY_CODES, $recoveryCodes);
        $this->session->flash('_profile.tab', 'security');
        $this->auth->resetCache();

        $this->context->flashToast('Authentification à deux facteurs activée ! Conservez vos codes de récupération.', 'success');

        return Response::redirect('/admin/profile?tab=security', 302);
    }

    public function regenerateRecoveryCodes(Request $request): Response
    {
        $user = $this->requireUser($request);
        if (!$user->hasTwoFactor()) {
            return Response::redirect('/admin/profile?tab=security', 302);
        }

        $currentPassword = (string) $request->body('current_password', '');
        if (!$this->verifyCurrentPassword($user->id, $currentPassword)) {
            return Response::html($this->renderProfile(
                $request,
                $user,
                'security',
                twoFactorError: 'Mot de passe actuel incorrect pour régénérer les codes de récupération.',
            ), 422);
        }

        $codes = $this->twoFactor->regenerateRecoveryCodes($user);
        $this->session->flash(self::FLASH_RECOVERY_CODES, $codes);
        $this->session->flash('_profile.tab', 'security');
        $this->context->flashToast('Nouveaux codes de récupération générés. Les anciens ne sont plus valides.', 'success');

        return Response::redirect('/admin/profile?tab=security', 302);
    }

    public function disableTwoFactor(Request $request): Response
    {
        $user = $this->requireUser($request);
        if (!$user->hasTwoFactor()) {
            return Response::redirect('/admin/profile?tab=security', 302);
        }

        $currentPassword = (string) $request->body('current_password', '');
        $code = trim((string) $request->body('code', ''));

        if (!$this->verifyCurrentPassword($user->id, $currentPassword)) {
            return Response::html($this->renderProfile(
                $request,
                $user,
                'security',
                twoFactorError: 'Mot de passe actuel incorrect. La 2FA n\'a pas été désactivée.',
            ), 422);
        }

        if (!$this->twoFactor->verifyChallenge($user, $code)) {
            return Response::html($this->renderProfile(
                $request,
                $user,
                'security',
                twoFactorError: 'Code TOTP ou code de récupération invalide. La 2FA n\'a pas été désactivée.',
            ), 422);
        }

        $this->twoFactor->disable($user, $user->id);
        $this->auth->resetCache();
        $this->session->flash('_profile.tab', 'security');
        $this->context->flashToast('L\'authentification à deux facteurs a été désactivée.', 'warning');

        return Response::redirect('/admin/profile?tab=security', 302);
    }

    public function logoutOtherDevices(Request $request): Response
    {
        $user = $this->requireUser($request);
        $currentPassword = (string) $request->body('current_password', '');

        if (!$this->verifyCurrentPassword($user->id, $currentPassword)) {
            return Response::html($this->renderProfile(
                $request,
                $user,
                'security',
                devicesError: 'Mot de passe actuel incorrect.',
            ), 422);
        }

        $this->auth->logoutOtherDevices($user);
        $this->session->flash('_profile.tab', 'security');
        $this->context->flashToast('Tous vos autres appareils ont été déconnectés.', 'success');

        return Response::redirect('/admin/profile?tab=security', 302);
    }

    /**
     * @param array<string, string> $infoErrors
     * @param array<string, string> $passwordErrors
     * @param array<string, string>|null $oldInfo
     */
    private function renderProfile(
        Request $request,
        User $user,
        string $activeTab = 'info',
        array $infoErrors = [],
        array $passwordErrors = [],
        ?string $twoFactorError = null,
        ?string $devicesError = null,
        ?array $oldInfo = null,
    ): string {
        $pendingSetup = $this->session->get(self::SESSION_2FA_PENDING_SECRET);
        $recoveryOnce = $this->session->getFlash(self::FLASH_RECOVERY_CODES);

        $data = $this->context->build($request, 'Mon profil', [
            'profileUser' => $user,
            'activeTab' => $activeTab,
            'localeOptions' => self::LOCALE_OPTIONS,
            'oldInfo' => $oldInfo ?? [
                'display_name' => $user->displayName,
                'email' => $user->email,
                'locale' => $user->locale,
            ],
            'infoErrors' => $infoErrors,
            'passwordErrors' => $passwordErrors,
            'twoFactorError' => $twoFactorError,
            'devicesError' => $devicesError,
            'pendingTwoFactor' => is_array($pendingSetup) && !$user->hasTwoFactor() ? $pendingSetup : null,
            'recoveryCodesOnce' => is_array($recoveryOnce) ? $recoveryOnce : null,
            'remainingRecoveryCount' => $user->hasTwoFactor() ? $this->twoFactor->remainingRecoveryCodesCount($user->id) : 0,
            'breadcrumbs' => [
                ['label' => 'Tableau de bord', 'url' => '/admin'],
                ['label' => 'Mon profil', 'url' => null],
            ],
        ]);

        return $this->view->render('admin::profile/index', $data);
    }

    private function verifyCurrentPassword(int $userId, string $plainPassword): bool
    {
        if ($plainPassword === '') {
            return false;
        }

        $raw = $this->users->findRawById($userId);
        if ($raw === null || !is_string($raw['password_hash'] ?? null)) {
            return false;
        }

        return $this->hasher->verify($plainPassword, $raw['password_hash']);
    }

    private function requireUser(Request $request): User
    {
        $user = $this->auth->user($request);
        if ($user === null) {
            throw new ForbiddenException('Session expirée.');
        }

        return $user;
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
        }

        return (string) $this->app->config()->get('app.name', 'Prime CMS');
    }
}
