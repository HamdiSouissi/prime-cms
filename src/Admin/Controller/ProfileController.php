<?php

declare(strict_types=1);

namespace Prime\Admin\Controller;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Prime\Settings\Settings;
use Prime\Admin\AdminContext;
use Prime\Admin\TranslatesAdminCopy;
use Prime\Api\Token\ApiTokenService;
use Prime\Auth\Auth;
use Prime\Auth\Capabilities;
use Prime\Auth\PasswordResetService;
use Prime\Auth\TwoFactorPolicy;
use Prime\Auth\TwoFactorService;
use Prime\Auth\User;
use Prime\Auth\UserRepository;
use Prime\Database\Connection;
use Prime\Http\Exception\ForbiddenException;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Http\Session;
use Prime\Kernel\Application;
use Prime\Media\MediaRepository;
use Prime\Security\Hasher;
use Prime\Security\PasswordPolicy;
use Prime\Site\ReservedSlugValidator;
use Prime\Validation\Validator;
use Prime\View\View;
use Throwable;

/**
 * Self-service account & security controller (`/admin/profile`), accessible to any authenticated user.
 */
final class ProfileController
{
    use TranslatesAdminCopy;

    private const SESSION_2FA_PENDING_SECRET = '_profile.2fa_pending_secret';

    private const FLASH_RECOVERY_CODES = '_profile.recovery_codes_once';

    /**
     * @var list<string>
     */
    private const LOCALE_OPTIONS = ['fr', 'en', 'it'];

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
        private readonly Settings $settings,
        private readonly ApiTokenService $apiTokens,
        private readonly Capabilities $capabilities,
        private readonly ?MediaRepository $media = null,
    ) {
    }

    /**
     * Locale choices offered in the account forms, labelled in the interface language.
     *
     * @return array<string, string>
     */
    private function localeOptions(): array
    {
        $options = [];
        foreach (self::LOCALE_OPTIONS as $code) {
            $options[$code] = \Prime\I18n\LocaleFormatter::fallback()->localeName($code);
        }

        return $options;
    }

    public function index(Request $request): Response
    {
        $user = $this->requireUser($request);

        // L'onglet dans l'URL l'emporte toujours, sur une liste blanche.
        $queryTab = (string) $request->query('tab', '');
        if (!in_array($queryTab, ['info', 'security', 'api-tokens'], true)) {
            $queryTab = '';
        }

        if ($queryTab === '' && $request->query('enroll') === 'two-factor') {
            $queryTab = 'security';
        }

        // Le flash ne sert que si l'URL ne précise rien.
        if ($queryTab === '') {
            $queryTab = (string) $this->session->getFlash('_profile.tab', 'info');
        }

        $activeTab = $queryTab;

        // Un seul chemin de rendu : charge les jetons et les groupes de capacités
        // dès que l'onglet actif est api-tokens, quelle que soit la façon dont il a été choisi.
        $apiTokens = null;
        $apiAbilityGroups = null;
        $apiNewToken = null;
        $apiTokenMaxDays = null;

        if ($activeTab === 'api-tokens') {
            $apiTokens = $this->apiTokens->listByUser($user->id);
            $apiAbilityGroups = $this->capabilities->grouped();
            $apiNewToken = $this->session->getFlash('_profile.api_token_once');
            $apiTokenMaxDays = (int) $this->settings->get('api.token_max_days', 365);
        }

        return Response::html($this->renderProfile(
            $request,
            $user,
            $activeTab,
            apiTokens: $apiTokens,
            apiAbilityGroups: $apiAbilityGroups,
            apiNewToken: $apiNewToken,
            apiTokenMaxDays: $apiTokenMaxDays,
        ));
    }

    public function updateInfo(Request $request): Response
    {
        $user = $this->requireUser($request);
        $displayName = trim((string) $request->body('display_name', ''));
        $email = UserRepository::normalizeEmail((string) $request->body('email', ''));
        $locale = trim((string) $request->body('locale', $user->locale));
        $currentPassword = (string) $request->body('current_password_email', '');
        $usernameProvided = $request->body('username') !== null;
        $username = $usernameProvided ? strtolower(trim((string) $request->body('username', ''))) : ($user->username ?? '');
        $bioProvided = $request->body('bio') !== null;
        $bio = $bioProvided ? trim((string) $request->body('bio', '')) : ($user->bio ?? '');

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
                'display_name' => $this->t('admin.ctrl_text_profile.nom_affiche'),
                'email' => 'Adresse e-mail',
                'locale' => $this->t('admin.ctrl_text_profile.langue_de_l_interface'),
            ],
            connection: $this->connection,
        );

        $errors = $validator->errors();

        if ($usernameProvided && $username !== '') {
            if (preg_match('/^[a-z0-9](?:[a-z0-9_-]{1,38}[a-z0-9])?$/', $username) !== 1) {
                $errors['username'] = $this->t('admin.ctrl_text_profile.l_identifiant_public_doit_contenir_3_a_40_caracteres_lettres');
            } elseif ((new ReservedSlugValidator())->isReserved($username)) {
                $errors['username'] = $this->t('admin.ctrl_text_profile.cet_identifiant_public_est_reserve_par_le_systeme');
            } else {
                $existing = $this->users->findByAuthorSlug($username);
                if ($existing !== null && $existing->id !== $user->id) {
                    $errors['username'] = $this->t('admin.ctrl_text_profile.cet_identifiant_public_est_deja_utilise_par_un_autre_auteur');
                }
            }
        }

        if ($bioProvided && mb_strlen($bio) > 1000) {
            $errors['bio'] = $this->t('admin.ctrl_text_profile.la_biographie_ne_doit_pas_depasser_1000_caracteres');
        }

        $emailChanged = $email !== $user->email;
        if ($emailChanged && !$this->verifyCurrentPassword($user->id, $currentPassword)) {
            $errors['current_password_email'] = $this->t('admin.ctrl_text_profile.saisissez_votre_mot_de_passe_actuel_pour_confirmer_le_change');
        }

        // Optional avatar selection from Media Library (`avatar_media_id`)
        $avatarProvided = $request->body('avatar_media_id') !== null;
        $avatarMediaId = $user->avatarMediaId;
        if ($avatarProvided) {
            $rawAvatar = trim((string) $request->body('avatar_media_id', ''));
            if ($rawAvatar === '' || $rawAvatar === '0') {
                $avatarMediaId = null;
            } elseif (ctype_digit($rawAvatar) && (int) $rawAvatar > 0) {
                $candidateId = (int) $rawAvatar;
                if ($this->media !== null) {
                    $avatarMedia = $this->media->find($candidateId);
                    if ($avatarMedia === null || !$avatarMedia->isImage()) {
                        $errors['avatar_media_id'] = $this->t('admin.ctrl_text_profile.l_avatar_selectionne_doit_etre_une_image_valide_de_la_mediat');
                    } else {
                        $avatarMediaId = $candidateId;
                    }
                } else {
                    $avatarMediaId = $candidateId;
                }
            } else {
                $errors['avatar_media_id'] = 'Identifiant d’avatar invalide.';
            }
        }

        if ($errors !== []) {
            return Response::html($this->renderProfile($request, $user, 'info', infoErrors: $errors, oldInfo: [
                'display_name' => $displayName,
                'email' => $email,
                'locale' => $locale,
                'username' => $username,
                'bio' => $bio,
            ]), 422);
        }

        $fieldsToUpdate = [
            'display_name' => $displayName,
            'email' => $email,
            'locale' => $locale,
        ];
        if ($usernameProvided) {
            $fieldsToUpdate['username'] = $username !== '' ? $username : null;
        }
        if ($bioProvided) {
            $fieldsToUpdate['bio'] = $bio !== '' ? $bio : null;
        }
        if ($avatarProvided) {
            $fieldsToUpdate['avatar_media_id'] = $avatarMediaId;
        }

        $this->users->updateFields($user->id, $fieldsToUpdate);

        $this->auth->resetCache();
        $this->context->flashToast($this->t('admin.ctrl_text_profile.vos_informations_personnelles_ont_ete_enregistrees'), 'success');

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
            $errors['current_password'] = $this->t('admin.ctrl_text_profile.votre_mot_de_passe_actuel_est_incorrect');
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
                'password' => $this->t('admin.ctrl_text_profile.nouveau_mot_de_passe'),
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
        $this->context->flashToast($this->t('admin.ctrl_text_profile.votre_mot_de_passe_a_ete_modifie_et_vos_autres_appareils_ont'), 'success');

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
            $this->context->flashToast($this->t('admin.ctrl_text_profile.aucune_configuration_2fa_en_cours_recommencez_l_activation'), 'warning');

            return Response::redirect('/admin/profile?tab=security', 302);
        }

        $code = trim((string) $request->body('code', ''));
        $recoveryCodes = $this->twoFactor->confirmAndEnable($user, $pending['secret'], $code);

        if ($recoveryCodes === null) {
            return Response::html($this->renderProfile(
                $request,
                $user,
                'security',
                twoFactorError: $this->t('admin.ctrl_text_profile.code_totp_invalide_verifiez_l_heure_de_votre_appareil_et_ree'),
            ), 422);
        }

        $this->session->remove(self::SESSION_2FA_PENDING_SECRET);
        $this->session->flash(self::FLASH_RECOVERY_CODES, $recoveryCodes);
        $this->session->flash('_profile.tab', 'security');
        $this->auth->resetCache();

        $this->context->flashToast($this->t('admin.ctrl_text_profile.authentification_a_deux_facteurs_activee_conservez_vos_codes'), 'success');

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
                twoFactorError: $this->t('admin.ctrl_text_profile.mot_de_passe_actuel_incorrect_pour_regenerer_les_codes_de_re'),
            ), 422);
        }

        $codes = $this->twoFactor->regenerateRecoveryCodes($user);
        $this->session->flash(self::FLASH_RECOVERY_CODES, $codes);
        $this->session->flash('_profile.tab', 'security');
        $this->context->flashToast($this->t('admin.ctrl_text_profile.nouveaux_codes_de_recuperation_generes_les_anciens_ne_sont_p'), 'success');

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
                twoFactorError: $this->t('admin.ctrl_text_profile.mot_de_passe_actuel_incorrect_la_2fa_n_a_pas_ete_desactivee'),
            ), 422);
        }

        if (!$this->twoFactor->verifyChallenge($user, $code)) {
            return Response::html($this->renderProfile(
                $request,
                $user,
                'security',
                twoFactorError: $this->t('admin.ctrl_text_profile.code_totp_ou_code_de_recuperation_invalide_la_2fa_n_a_pas_et'),
            ), 422);
        }

        $this->twoFactor->disable($user, $user->id);
        $this->auth->resetCache();
        $this->session->flash('_profile.tab', 'security');
        $this->context->flashToast($this->t('admin.ctrl_text_profile.l_authentification_a_deux_facteurs_a_ete_desactivee'), 'warning');

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
                devicesError: $this->t('admin.ctrl_text_profile.mot_de_passe_actuel_incorrect'),
            ), 422);
        }

        $this->auth->logoutOtherDevices($user);
        $this->session->flash('_profile.tab', 'security');
        $this->context->flashToast($this->t('admin.ctrl_text_profile.tous_vos_autres_appareils_ont_ete_deconnectes'), 'success');

        return Response::redirect('/admin/profile?tab=security', 302);
    }

    /**
     * Crée un nouveau jeton d'API depuis le profil.
     * Le jeton brut est flashé en session et affiché une seule fois.
     */
    public function createApiToken(Request $request): Response
    {
        $user = $this->requireUser($request);

        $name = trim((string) $request->body('name', ''));
        if ($name === '' || mb_strlen($name) > 100) {
            $this->session->flash('_profile.api_token_error', $this->t('admin.ctrl_text_profile.jeton_api_nom_obligatoire'));
            return Response::redirect('/admin/profile?tab=api-tokens', 302);
        }

        $rawAbilities = $request->body('abilities', []);
        $abilities = is_array($rawAbilities) ? array_values(array_filter($rawAbilities, 'is_string')) : [];

        $expiresDays = (string) $request->body('expires_days', '');
        $maxDays = (int) $this->settings->get('api.token_max_days', 365);
        if ($maxDays <= 0) {
            $maxDays = 3650;
        }

        $expiresAt = null;
        if ($expiresDays !== '') {
            $days = (int) $expiresDays;
            // « Jamais » (0) n'est proposé que quand il n'y a pas de durée maximale ;
            // sinon toute durée hors bornes (y compris 0) est ramenée au maximum autorisé.
            if ($days === 0) {
                $days = $maxDays;
            }
            if ($days > 0) {
                $days = max(1, min($maxDays, $days));
                $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->add(new DateInterval('P' . $days . 'D'));
            }
        }

        $result = $this->apiTokens->issue($user, $name, $abilities, $expiresAt);

        // Flasher le jeton brut pour affichage unique (disponible à la requête suivante seulement).
        $this->session->flash('_profile.api_token_once', [
            'token' => $result['token'],
            'name' => $name,
        ]);
        $this->session->flash('_profile.tab', 'api-tokens');

        $this->context->flashToast($this->t('admin.ctrl_text_profile.jeton_cree'), 'success');

        return Response::redirect('/admin/profile?tab=api-tokens', 302);
    }

    /**
     * Révoque un jeton d'API appartenant à l'utilisateur connecté.
     * Révoquer le jeton d'un autre utilisateur retourne 404.
     */
    public function revokeApiToken(Request $request, int $id): Response
    {
        $user = $this->requireUser($request);

        $token = $this->apiTokens->findById($id);
        if ($token === null || (int) ($token['user_id'] ?? 0) !== $user->id) {
            return Response::html($this->t('admin.ctrl_text_profile.jeton_api_introuvable'), 404);
        }

        $this->apiTokens->revoke($id);
        $this->session->flash('_profile.tab', 'api-tokens');
        $this->context->flashToast($this->t('admin.ctrl_text_profile.jeton_api_revoque'), 'success');

        return Response::redirect('/admin/profile?tab=api-tokens', 302);
    }

    /**
     * @param array<string, string> $infoErrors
     * @param array<string, string> $passwordErrors
     * @param array<string, string>|null $oldInfo
     * @param list<array<string, mixed>>|null $apiTokens
     * @param array<string, array<string, string>>|null $apiAbilityGroups
     * @param array{token: string, name: string}|null $apiNewToken
     * @param int|null $apiTokenMaxDays
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
        ?array $apiTokens = null,
        ?array $apiAbilityGroups = null,
        ?array $apiNewToken = null,
        ?int $apiTokenMaxDays = null,
    ): string {
        $pendingSetup = $this->session->get(self::SESSION_2FA_PENDING_SECRET);
        $recoveryOnce = $this->session->getFlash(self::FLASH_RECOVERY_CODES);

        $avatarInitialJson = '[]';
        if ($user->avatarMediaId !== null && $this->media !== null) {
            try {
                $avatarMedia = $this->media->find($user->avatarMediaId);
                if ($avatarMedia !== null) {
                    $encoded = json_encode([$avatarMedia->toApiArray()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
                    if (is_string($encoded)) {
                        $avatarInitialJson = $encoded;
                    }
                }
            } catch (Throwable) {
            }
        }

        $data = $this->context->build($request, $this->t('admin.ctrl_text_profile.mon_profil'), [
            'profileUser' => $user,
            'avatarInitialJson' => $avatarInitialJson,
            'canPickAvatar' => $user->can('media.view'),
            'activeTab' => $activeTab,
            'localeOptions' => $this->localeOptions(),
            'oldInfo' => $oldInfo ?? [
                'display_name' => $user->displayName,
                'email' => $user->email,
                'locale' => $user->locale,
                'username' => $user->username ?? '',
                'bio' => $user->bio ?? '',
            ],
            'infoErrors' => $infoErrors,
            'passwordErrors' => $passwordErrors,
            'twoFactorError' => $twoFactorError,
            'devicesError' => $devicesError,
            'twoFactorRequired' => $this->twoFactorRequired($user),
            'pendingTwoFactor' => is_array($pendingSetup) && !$user->hasTwoFactor() ? $pendingSetup : null,
            'recoveryCodesOnce' => is_array($recoveryOnce) ? $recoveryOnce : null,
            'remainingRecoveryCount' => $user->hasTwoFactor() ? $this->twoFactor->remainingRecoveryCodesCount($user->id) : 0,
            'apiTokens' => $apiTokens ?? [],
            'apiAbilityGroups' => $apiAbilityGroups ?? [],
            'apiNewToken' => $apiNewToken,
            'apiTokenMaxDays' => $apiTokenMaxDays,
            'breadcrumbs' => [
                ['label' => $this->t('admin.ctrl_text_profile.tableau_de_bord'), 'url' => '/admin'],
                ['label' => $this->t('admin.ctrl_text_profile.mon_profil'), 'url' => null],
            ],
        ]);

        return $this->view->render('admin::profile/index', $data);
    }

    /**
     * Whether the security policy forces this account to enroll before using the admin.
     */
    private function twoFactorRequired(User $user): bool
    {
        $container = $this->app->container();
        if (!$container->has(TwoFactorPolicy::class)) {
            return false;
        }

        $policy = $container->get(TwoFactorPolicy::class);

        return $policy instanceof TwoFactorPolicy && $policy->isRequired($user);
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
            throw new ForbiddenException($this->t('admin.ctrl_text_profile.session_expiree'));
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
