<?php

declare(strict_types=1);

namespace Prime\Install\Controller;

use Prime\Http\Exception\NotFoundException;
use Prime\Http\Middleware\RedirectIfNotInstalled;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Http\Session;
use Prime\Install\DatabaseTester;
use Prime\Install\EnvWriter;
use Prime\Install\Exception\InstallException;
use Prime\Install\InstallConfig;
use Prime\Install\Installer;
use Prime\Install\InstallerValidator;
use Prime\Install\RequirementsChecker;
use Prime\Kernel\Application;
use Prime\Kernel\Config;
use Prime\View\View;

/**
 * Thin web controller guiding users through the 7-step Prime CMS installation wizard.
 *
 * All inter-step state (including database and administrator passwords) lives exclusively
 * in the server-side PHP Session and is wiped immediately once installation completes.
 */
final class InstallController
{
    private const WIZARD_SESSION_KEY = 'install_wizard';

    /**
     * Step definitions shown in the accessible progress stepper.
     *
     * @var list<array{id: string, number: int, label: string, path: string}>
     */
    private const STEPS = [
        ['id' => 'welcome', 'number' => 1, 'label' => 'Bienvenue', 'path' => '/install'],
        ['id' => 'requirements', 'number' => 2, 'label' => 'Prérequis', 'path' => '/install/requirements'],
        ['id' => 'database', 'number' => 3, 'label' => 'Base de données', 'path' => '/install/database'],
        ['id' => 'site', 'number' => 4, 'label' => 'Site', 'path' => '/install/site'],
        ['id' => 'admin', 'number' => 5, 'label' => 'Administrateur', 'path' => '/install/admin'],
        ['id' => 'run', 'number' => 6, 'label' => 'Installation', 'path' => '/install/run'],
        ['id' => 'done', 'number' => 7, 'label' => 'Terminé', 'path' => '/install/done'],
    ];

    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
        private readonly View $view,
        private readonly Session $session,
        private readonly RequirementsChecker $requirementsChecker,
        private readonly InstallerValidator $validator,
        private readonly DatabaseTester $databaseTester,
        private readonly EnvWriter $envWriter,
        private readonly Installer $installer,
    ) {
    }

    /**
     * Step 1 — Welcome & wizard language selection (`GET /install`).
     */
    public function welcome(Request $request): Response
    {
        $wizard = $this->wizardState();

        return $this->renderStep('install::welcome', 'welcome', 'Bienvenue', [
            'wizardLocale' => (string) ($wizard['wizard_locale'] ?? 'fr'),
            'locales' => [
                'fr' => 'Français',
                'en' => 'English (interface FR en attendant la phase 9)',
                'it' => 'Italiano (interface FR en attendant la phase 9)',
            ],
        ]);
    }

    /**
     * Step 1 submit (`POST /install`).
     */
    public function saveWelcome(Request $request): Response
    {
        $locale = trim((string) $request->body('wizard_locale', 'fr'));
        if (!in_array($locale, InstallerValidator::SUPPORTED_LOCALES, true)) {
            $locale = 'fr';
        }

        $wizard = $this->wizardState();
        $wizard['wizard_locale'] = $locale;
        $this->saveWizardState($wizard);

        return Response::redirect('/install/requirements', 302);
    }

    /**
     * Step 2 — System requirements (`GET /install/requirements`).
     */
    public function requirements(Request $request): Response
    {
        $report = $this->requirementsChecker->check();

        $wizard = $this->wizardState();
        $wizard['requirements_passed'] = $report['passed'];
        $this->saveWizardState($wizard);

        return $this->renderStep('install::requirements', 'requirements', 'Prérequis serveur', [
            'report' => $report,
        ]);
    }

    /**
     * Step 3 — Database configuration (`GET /install/database`).
     */
    public function database(Request $request): Response
    {
        $wizard = $this->wizardState();
        $old = $this->session->pullOldInput();
        $errors = $this->pullErrors();
        $dbStatus = $this->session->getFlash('install_db_test');

        $savedDb = is_array($wizard['database'] ?? null) ? $wizard['database'] : [];

        $values = [
            'db_host' => (string) ($old['db_host'] ?? $savedDb['host'] ?? $this->config->get('database.host', '127.0.0.1')),
            'db_port' => (string) ($old['db_port'] ?? $savedDb['port'] ?? $this->config->get('database.port', 3306)),
            'db_database' => (string) ($old['db_database'] ?? $savedDb['database'] ?? $this->config->get('database.database', 'prime_cms')),
            'db_username' => (string) ($old['db_username'] ?? $savedDb['username'] ?? $this->config->get('database.username', 'prime')),
            'db_prefix' => (string) ($old['db_prefix'] ?? $savedDb['prefix'] ?? $this->config->get('database.prefix', 'pc_')),
        ];

        $hasStoredPassword = is_string($savedDb['password'] ?? null) && $savedDb['password'] !== '';

        return $this->renderStep('install::database', 'database', 'Base de données', [
            'values' => $values,
            'errors' => $errors,
            'dbStatus' => is_array($dbStatus) ? $dbStatus : null,
            'hasStoredPassword' => $hasStoredPassword,
        ]);
    }

    /**
     * Step 3 submit: test connection, create database, or continue (`POST /install/database`).
     */
    public function saveDatabase(Request $request): Response
    {
        $wizard = $this->wizardState();
        $savedDb = is_array($wizard['database'] ?? null) ? $wizard['database'] : [];

        $intent = trim((string) $request->body('intent', 'continue'));
        $submittedPassword = (string) $request->body('db_password', '');

        // Keep previously saved session password only if field left blank after a prior save.
        $password = $submittedPassword !== ''
            ? $submittedPassword
            : (is_string($savedDb['password'] ?? null) ? $savedDb['password'] : '');

        $input = [
            'db_host' => trim((string) $request->body('db_host', '')),
            'db_port' => trim((string) $request->body('db_port', '3306')),
            'db_database' => trim((string) $request->body('db_database', '')),
            'db_username' => trim((string) $request->body('db_username', '')),
            'db_prefix' => trim((string) $request->body('db_prefix', 'pc_')),
        ];

        $errors = $this->validator->validateDatabase($input);
        if ($errors !== []) {
            $this->session->flashInput($input);
            $this->session->flash('install_errors', $errors);

            return Response::redirect('/install/database', 302);
        }

        $dbConfig = [
            'host' => $input['db_host'],
            'port' => (int) $input['db_port'],
            'database' => $input['db_database'],
            'username' => $input['db_username'],
            'password' => $password,
            'prefix' => $input['db_prefix'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ];

        // Persist sanitized input + secret password strictly in server-side session.
        $wizard['database'] = $dbConfig;
        $wizard['database_verified'] = false;

        if ($intent === 'create_db') {
            try {
                $this->databaseTester->createDatabase($dbConfig);
            } catch (\Throwable) {
                $this->saveWizardState($wizard);
                $this->session->flash('install_db_test', [
                    'ok' => false,
                    'status' => 'create_failed',
                    'message' => 'Impossible de créer automatiquement la base de données « ' . $dbConfig['database'] . ' ». Créez-la manuellement puis testez à nouveau.',
                    'can_create_database' => false,
                    'existing_tables' => [],
                ]);

                return Response::redirect('/install/database', 302);
            }
        }

        $test = $this->databaseTester->test($dbConfig);

        if (!$test['ok'] || $intent === 'test' || $intent === 'create_db') {
            $wizard['database_verified'] = $test['ok'];
            $this->saveWizardState($wizard);
            $this->session->flash('install_db_test', $test);

            return Response::redirect('/install/database', 302);
        }

        $wizard['database_verified'] = true;
        $this->saveWizardState($wizard);

        return Response::redirect('/install/site', 302);
    }

    /**
     * Step 4 — Site configuration (`GET /install/site`).
     */
    public function site(Request $request): Response
    {
        $wizard = $this->wizardState();
        if (($wizard['database_verified'] ?? false) !== true) {
            return Response::redirect('/install/database', 302);
        }

        $old = $this->session->pullOldInput();
        $errors = $this->pullErrors();
        $savedSite = is_array($wizard['site'] ?? null) ? $wizard['site'] : [];

        $defaultLocale = (string) ($wizard['wizard_locale'] ?? 'fr');
        $values = [
            'site_name' => (string) ($old['site_name'] ?? $savedSite['name'] ?? 'Mon site Prime CMS'),
            'site_url' => (string) ($old['site_url'] ?? $savedSite['url'] ?? $this->detectSiteUrl($request)),
            'site_locale' => (string) ($old['site_locale'] ?? $savedSite['locale'] ?? $defaultLocale),
            'timezone' => (string) ($old['timezone'] ?? $savedSite['timezone'] ?? 'Europe/Paris'),
        ];

        return $this->renderStep('install::site', 'site', 'Informations du site', [
            'values' => $values,
            'errors' => $errors,
            'locales' => [
                'fr' => 'Français (fr)',
                'en' => 'English (en)',
                'it' => 'Italiano (it)',
            ],
            'timezones' => InstallerValidator::timezoneOptions(),
        ]);
    }

    /**
     * Step 4 submit (`POST /install/site`).
     */
    public function saveSite(Request $request): Response
    {
        $wizard = $this->wizardState();
        if (($wizard['database_verified'] ?? false) !== true) {
            return Response::redirect('/install/database', 302);
        }

        $input = [
            'site_name' => trim((string) $request->body('site_name', '')),
            'site_url' => rtrim(trim((string) $request->body('site_url', '')), '/'),
            'site_locale' => trim((string) $request->body('site_locale', 'fr')),
            'timezone' => trim((string) $request->body('timezone', 'Europe/Paris')),
        ];

        $errors = $this->validator->validateSite($input);
        if ($errors !== []) {
            $this->session->flashInput($input);
            $this->session->flash('install_errors', $errors);

            return Response::redirect('/install/site', 302);
        }

        $wizard['site'] = [
            'name' => $input['site_name'],
            'url' => $input['site_url'],
            'locale' => $input['site_locale'],
            'timezone' => $input['timezone'],
        ];
        $this->saveWizardState($wizard);

        return Response::redirect('/install/admin', 302);
    }

    /**
     * Step 5 — Administrator account (`GET /install/admin`).
     */
    public function admin(Request $request): Response
    {
        $wizard = $this->wizardState();
        if (!is_array($wizard['site'] ?? null)) {
            return Response::redirect('/install/site', 302);
        }

        $old = $this->session->pullOldInput();
        $errors = $this->pullErrors();
        $savedAdmin = is_array($wizard['admin'] ?? null) ? $wizard['admin'] : [];

        $values = [
            'display_name' => (string) ($old['display_name'] ?? $savedAdmin['display_name'] ?? ''),
            'email' => (string) ($old['email'] ?? $savedAdmin['email'] ?? ''),
        ];

        return $this->renderStep('install::admin', 'admin', 'Compte administrateur', [
            'values' => $values,
            'errors' => $errors,
            'siteName' => (string) ($wizard['site']['name'] ?? ''),
        ]);
    }

    /**
     * Step 5 submit (`POST /install/admin`).
     */
    public function saveAdmin(Request $request): Response
    {
        $wizard = $this->wizardState();
        if (!is_array($wizard['site'] ?? null)) {
            return Response::redirect('/install/site', 302);
        }

        $input = [
            'display_name' => trim((string) $request->body('display_name', '')),
            'email' => trim((string) $request->body('email', '')),
            'password' => (string) $request->body('password', ''),
            'password_confirmation' => (string) $request->body('password_confirmation', ''),
        ];

        $siteName = (string) ($wizard['site']['name'] ?? '');
        $errors = $this->validator->validateAdmin($input, $siteName);
        if ($errors !== []) {
            $this->session->flashInput([
                'display_name' => $input['display_name'],
                'email' => $input['email'],
            ]);
            $this->session->flash('install_errors', $errors);

            return Response::redirect('/install/admin', 302);
        }

        $wizard['admin'] = [
            'display_name' => $input['display_name'],
            'email' => mb_strtolower($input['email'], 'UTF-8'),
            'password' => $input['password'],
        ];
        $this->saveWizardState($wizard);

        return Response::redirect('/install/run', 302);
    }

    /**
     * Step 6 — Summary & installation trigger (`GET /install/run`).
     */
    public function summary(Request $request): Response
    {
        $wizard = $this->wizardState();
        if (!is_array($wizard['admin'] ?? null) || !is_array($wizard['site'] ?? null) || !is_array($wizard['database'] ?? null)) {
            return Response::redirect('/install/admin', 302);
        }

        $installError = $this->session->getFlash('install_run_error');
        $manualEnvContent = $this->session->getFlash('install_manual_env');
        $envPath = $this->app->basePath('.env');
        $envWritable = $this->envWriter->isWritable($envPath);

        return $this->renderStep('install::run', 'run', 'Lancer l\'installation', [
            'summary' => [
                'database' => [
                    'host' => (string) $wizard['database']['host'],
                    'port' => (int) $wizard['database']['port'],
                    'database' => (string) $wizard['database']['database'],
                    'username' => (string) $wizard['database']['username'],
                    'prefix' => (string) $wizard['database']['prefix'],
                ],
                'site' => [
                    'name' => (string) $wizard['site']['name'],
                    'url' => (string) $wizard['site']['url'],
                    'locale' => (string) $wizard['site']['locale'],
                    'timezone' => (string) $wizard['site']['timezone'],
                ],
                'admin' => [
                    'display_name' => (string) $wizard['admin']['display_name'],
                    'email' => (string) $wizard['admin']['email'],
                ],
            ],
            'envWritable' => $envWritable,
            'manualEnvContent' => is_string($manualEnvContent) ? $manualEnvContent : null,
            'installError' => is_array($installError) ? $installError : null,
        ]);
    }

    /**
     * Step 6 submit — execute installation via the unified `Installer` service (`POST /install/run`).
     */
    public function execute(Request $request): Response
    {
        $wizard = $this->wizardState();
        if (!is_array($wizard['admin'] ?? null) || !is_array($wizard['site'] ?? null) || !is_array($wizard['database'] ?? null)) {
            return Response::redirect('/install/admin', 302);
        }

        $config = new InstallConfig(
            dbHost: (string) $wizard['database']['host'],
            dbPort: (int) $wizard['database']['port'],
            dbDatabase: (string) $wizard['database']['database'],
            dbUsername: (string) $wizard['database']['username'],
            dbPassword: (string) $wizard['database']['password'],
            dbPrefix: (string) $wizard['database']['prefix'],
            siteName: (string) $wizard['site']['name'],
            siteUrl: (string) $wizard['site']['url'],
            siteLocale: (string) $wizard['site']['locale'],
            timezone: (string) $wizard['site']['timezone'],
            adminDisplayName: (string) $wizard['admin']['display_name'],
            adminEmail: (string) $wizard['admin']['email'],
            adminPassword: (string) $wizard['admin']['password'],
            appEnv: 'production',
            appDebug: false,
            createDatabase: false,
            skipEnvWrite: false,
            allowManualEnv: true,
            appKey: is_string($wizard['app_key'] ?? null) ? $wizard['app_key'] : null,
        );

        try {
            $result = $this->installer->install($config);
        } catch (InstallException $exception) {
            $this->session->flash('install_run_error', [
                'step' => $this->stepLabel($exception->step()),
                'message' => $exception->getMessage(),
            ]);

            return Response::redirect('/install/run', 302);
        }

        if ($result->manualEnvRequired) {
            $wizard['app_key'] = $result->appKey;
            $this->saveWizardState($wizard);
            $this->session->flash('install_manual_env', $result->envContent);
            $this->session->flash('install_run_error', [
                'step' => 'Configuration (.env)',
                'message' => 'Le fichier .env n\'est pas accessible en écriture. Copiez le contenu exact ci-dessous dans un fichier .env à la racine du projet, puis cliquez sur « J\'ai créé le fichier, continuer ».',
            ]);

            return Response::redirect('/install/run', 302);
        }

        // Wipe ALL wizard session data (including passwords and app_key) immediately!
        $siteName = (string) $wizard['site']['name'];
        $siteUrl = (string) $wizard['site']['url'];
        $this->session->remove(self::WIZARD_SESSION_KEY);
        $this->session->regenerate();

        $this->session->flash(RedirectIfNotInstalled::COMPLETION_FLASH_KEY, [
            'site_name' => $siteName,
            'site_url' => $siteUrl,
            'env_written' => $result->envWritten,
            'migrations_count' => count($result->migrations),
        ]);

        return Response::redirect('/install/done', 302);
    }

    /**
     * Step 7 — One-time installation completion page (`GET /install/done`).
     */
    public function done(Request $request): Response
    {
        $completion = $this->session->getFlash(RedirectIfNotInstalled::COMPLETION_FLASH_KEY);
        if (!is_array($completion)) {
            throw new NotFoundException('Page introuvable.');
        }

        return $this->renderStep('install::done', 'done', 'Installation terminée', [
            'completion' => $completion,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderStep(string $template, string $stepId, string $pageTitle, array $data = []): Response
    {
        $currentIndex = 0;
        foreach (self::STEPS as $index => $step) {
            if ($step['id'] === $stepId) {
                $currentIndex = $index;
                break;
            }
        }

        $steps = [];
        foreach (self::STEPS as $index => $step) {
            $state = 'upcoming';
            if ($index < $currentIndex) {
                $state = 'completed';
            } elseif ($index === $currentIndex) {
                $state = 'current';
            }

            $steps[] = array_merge($step, ['state' => $state]);
        }

        $html = $this->view->render($template, array_merge($data, [
            'pageTitle' => $pageTitle . ' — Installation · Prime CMS',
            'currentStep' => $stepId,
            'currentStepNumber' => $currentIndex + 1,
            'totalSteps' => count(self::STEPS),
            'steps' => $steps,
        ]));

        return Response::html($html);
    }

    /**
     * @return array<string, mixed>
     */
    private function wizardState(): array
    {
        $raw = $this->session->get(self::WIZARD_SESSION_KEY, []);

        return is_array($raw) ? $raw : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function saveWizardState(array $state): void
    {
        $this->session->set(self::WIZARD_SESSION_KEY, $state);
    }

    /**
     * @return array<string, string>
     */
    private function pullErrors(): array
    {
        $raw = $this->session->getFlash('install_errors', []);
        if (!is_array($raw)) {
            return [];
        }

        $errors = [];
        foreach ($raw as $key => $message) {
            if (is_string($key) && is_string($message)) {
                $errors[$key] = $message;
            }
        }

        return $errors;
    }

    private function detectSiteUrl(Request $request): string
    {
        $host = $request->header('host');
        if ($host !== null && $host !== '' && preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host) === 1) {
            $https = (string) $request->server('HTTPS', '');
            $forwardedProto = strtolower((string) ($request->header('x-forwarded-proto') ?? ''));
            $isHttps = ($https !== '' && strtolower($https) !== 'off') || $forwardedProto === 'https';

            return ($isHttps ? 'https://' : 'http://') . $host;
        }

        return rtrim((string) $this->config->get('app.url', 'http://localhost:8080'), '/');
    }

    private function stepLabel(string $step): string
    {
        return match ($step) {
            'validation' => 'Validation des paramètres',
            'database' => 'Connexion à la base de données',
            'env' => 'Écriture de la configuration (.env)',
            'migrations' => 'Création des tables (migrations)',
            'admin' => 'Création du compte administrateur',
            'settings' => 'Enregistrement des réglages',
            'lock' => 'Verrouillage de l\'installation',
            default => 'Installation',
        };
    }
}
