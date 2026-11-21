<?php

declare(strict_types=1);

namespace Prime\Admin\Controller;

use Prime\Admin\AdminContext;
use Prime\Admin\TranslatesAdminCopy;
use Prime\Http\Exception\NotFoundException;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Http\Session;
use Prime\Mail\Mailer;
use Prime\Mail\MailLogRepository;
use Prime\Media\MediaRepository;
use Prime\Settings\Settings;
use Prime\Settings\SettingsFormProcessor;
use Prime\Settings\SettingsRegistry;
use Prime\View\View;
use Throwable;

/**
 * Registry-driven settings screens: « Général », « Sécurité » and « E-mails ».
 *
 * Each screen is a list of registry sections rendered as cards; every field declared in
 * `config/settings.php` (or by a plugin through `settings.register`) shows up without a view of
 * its own. Saving goes through {@see SettingsFormProcessor}, so validation, secret encryption and
 * the `settings.*` hooks behave exactly like the CLI.
 */
final class SettingsPageController
{
    use TranslatesAdminCopy;

    /**
     * Screen → registry sections shown on it, in order.
     *
     * @var array<string, list<string>>
     */
    public const PAGES = [
        'general' => ['general', 'languages', 'writing', 'advanced'],
        'security' => ['security'],
        'emails' => ['mail'],
    ];

    private const ERRORS_FLASH = '_settings.errors';

    private const OLD_FLASH = '_settings.old';

    private const LOG_PAGE_SIZE = 50;

    public function __construct(
        private readonly View $view,
        private readonly AdminContext $context,
        private readonly SettingsRegistry $registry,
        private readonly SettingsFormProcessor $processor,
        private readonly Settings $settings,
        private readonly Session $session,
        private readonly MediaRepository $mediaRepository,
        private readonly ?Mailer $mailer = null,
        private readonly ?MailLogRepository $mailLog = null,
        private readonly ?\Prime\Cache\CacheManager $cache = null,
    ) {
    }

    /**
     * `GET /admin/settings` lands on the first screen.
     */
    public function landing(Request $request): Response
    {
        return Response::redirect('/admin/settings/general');
    }

    /**
     * `GET /admin/settings/{page}`.
     */
    public function show(Request $request, string $page): Response
    {
        $sections = $this->sectionsFor($page);

        $errors = $this->session->getFlash(self::ERRORS_FLASH, []);
        $old = $this->session->getFlash(self::OLD_FLASH, []);

        $old = is_array($old) ? $old : [];
        $cards = [];
        foreach ($sections as $sectionId => $section) {
            $rows = [];
            foreach ($this->registry->fields($sectionId) as $name => $field) {
                if (!$field->isEditable()) {
                    continue;
                }

                $rows[] = [
                    'field' => $field,
                    'value' => array_key_exists($name, $old)
                        ? $old[$name]
                        : ($field->isSensitive() ? null : $this->settings->get($name, $field->default)),
                    'options' => $this->registry->options($field),
                    'media' => $field->type === 'media' ? $this->mediaInfo($this->settings->get($name, $field->default)) : null,
                ];
            }

            if ($rows !== []) {
                $cards[$sectionId] = ['section' => $section, 'fields' => $rows];
            }
        }

        $extra = [
            'page' => $page,
            'cards' => $cards,
            'errors' => is_array($errors) ? $errors : [],
            'secretsSet' => $this->secretsSet($sections),
            'breadcrumbs' => [
                ['label' => t('admin.nav.dashboard'), 'url' => '/admin'],
                ['label' => t('admin.nav.settings'), 'url' => '/admin/settings'],
                ['label' => t('admin.settings.pages.' . $page . '.title'), 'url' => null],
            ],
        ];

        if ($page === 'emails') {
            $extra += $this->mailData($request);
        }

        if ($page === 'security') {
            $extra['securityHeaders'] = \Prime\Http\Middleware\SecurityHeaders::defaults()
                + ['Cache-Control (admin)' => \Prime\Auth\Middleware\NoStoreHeaders::HEADERS['Cache-Control'],
                    'Set-Cookie (session)' => 'HttpOnly; SameSite=Lax' . ($request->isSecure() ? '; Secure' : '')];
        }

        $data = $this->context->build($request, t('admin.settings.pages.' . $page . '.title'), $extra);
        if ($page === 'emails' && ($data['authUser'] ?? null) instanceof \Prime\Auth\User) {
            $data['testRecipient'] = $data['authUser']->email;
        }

        return Response::html($this->view->render('admin::settings/registry', $data));
    }

    /**
     * `POST /admin/settings/{page}`.
     */
    public function save(Request $request, string $page): Response
    {
        $sections = $this->sectionsFor($page);
        $raw = $request->body('settings', []);
        /** @var array<string, mixed> $input */
        $input = is_array($raw) ? $raw : [];

        $allErrors = [];
        $saved = 0;

        foreach (array_keys($sections) as $section) {
            // Only what the form actually posted: toggles and choice lists always send a hidden
            // fallback, so an absent key means « not on this form », never « set it to empty ».
            $only = array_values(array_intersect(array_keys($this->registry->fields($section)), array_keys($input)));
            if ($only === []) {
                continue;
            }

            $result = $this->processor->process($section, $input, $only);
            $allErrors += $result['errors'];
            $saved += count($result['saved']);
        }

        if ($allErrors !== []) {
            $this->session->flash(self::ERRORS_FLASH, $allErrors);
            $this->session->flash(self::OLD_FLASH, $this->withoutSecrets($input));
            $this->context->flashToast(t('admin.settings.saved_with_errors', ['count' => count($allErrors)]), 'danger');

            return Response::redirect('/admin/settings/' . $page);
        }

        if ($saved > 0) {
            // Site name, maintenance, visibility, languages… all show up in cached public pages.
            try {
                $this->cache?->pages()->purgeAll();
            } catch (Throwable) {
            }
        }

        $this->context->flashToast(
            $saved > 0 ? t('admin.settings.saved') : t('admin.settings.nothing_changed'),
            $saved > 0 ? 'success' : 'info',
        );

        return Response::redirect('/admin/settings/' . $page);
    }

    /**
     * `POST /admin/settings/emails/test` — deliver a test message through the saved transport.
     */
    public function sendTest(Request $request): Response
    {
        $to = trim((string) (is_scalar($request->body('to')) ? $request->body('to') : ''));

        if ($this->mailer === null) {
            $this->context->flashToast(t('admin.mail.unavailable'), 'danger');

            return Response::redirect('/admin/settings/emails');
        }

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->context->flashToast(t('admin.mail.invalid_recipient'), 'danger');

            return Response::redirect('/admin/settings/emails');
        }

        $result = $this->mailer->test($to);
        $this->context->flashToast($result['message'], $result['ok'] ? 'success' : 'danger');

        return Response::redirect('/admin/settings/emails#mail-log');
    }

    /**
     * `POST /admin/settings/emails/log/{id}/resend`.
     */
    public function resend(Request $request, string $id): Response
    {
        $row = $this->mailLog?->find((int) $id);

        if ($row === null || $this->mailer === null) {
            throw new NotFoundException(t('admin.mail.log_entry_missing'));
        }

        $result = $this->mailer->resend($row);
        $this->context->flashToast($result['message'], $result['ok'] ? 'success' : 'danger');

        return Response::redirect('/admin/settings/emails#mail-log');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function sectionsFor(string $page): array
    {
        if (!isset(self::PAGES[$page])) {
            throw new NotFoundException(t('admin.settings.page_missing'));
        }

        $declared = $this->registry->sections();
        $sections = [];

        foreach (self::PAGES[$page] as $id) {
            if (isset($declared[$id])) {
                $sections[$id] = $declared[$id];
            }
        }

        // Plugins may hang their own section on a core screen with `'page' => 'security'`, say.
        foreach ($this->registry->sections($page) as $id => $definition) {
            if (!isset($sections[$id]) && !$this->isCoreSection($id)) {
                $sections[$id] = $definition;
            }
        }

        return $sections;
    }

    private function isCoreSection(string $id): bool
    {
        foreach (self::PAGES as $ids) {
            if (in_array($id, $ids, true)) {
                return true;
            }
        }

        // Sections that already have a dedicated screen.
        return in_array($id, ['reading', 'permalinks', 'seo', 'performance', 'scheduler'], true);
    }

    /**
     * Which secret fields already hold a value (shown as « défini », never echoed back).
     *
     * @param array<string, array<string, mixed>> $sections
     *
     * @return array<string, bool>
     */
    private function secretsSet(array $sections): array
    {
        $set = [];

        foreach (array_keys($sections) as $section) {
            foreach ($this->registry->fields($section) as $name => $field) {
                if ($field->isSensitive()) {
                    try {
                        $set[$name] = ($this->settings->getSecret($name) ?? '') !== '';
                    } catch (Throwable) {
                        $set[$name] = false;
                    }
                }
            }
        }

        return $set;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function withoutSecrets(array $input): array
    {
        foreach ($this->registry->fields() as $name => $field) {
            if ($field->isSensitive()) {
                unset($input[$name]);
            }
        }

        return $input;
    }

    /**
     * Résout les données API d'un média pour l'affichage initial d'un champ « media ».
     *
     * @return array<string, mixed>|null
     */
    private function mediaInfo(mixed $value): ?array
    {
        $id = is_numeric($value) ? (int) $value : 0;
        if ($id <= 0) {
            return null;
        }

        $media = $this->mediaRepository->find($id);

        return $media !== null ? $media->toApiArray() : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function mailData(Request $request): array
    {
        $statusFilter = $request->query('status');
        $status = is_string($statusFilter) && in_array($statusFilter, [
            MailLogRepository::STATUS_SENT,
            MailLogRepository::STATUS_FAILED,
            MailLogRepository::STATUS_QUEUED,
        ], true) ? $statusFilter : null;

        $entries = [];
        $stats = ['total' => 0, 'sent' => 0, 'failed' => 0, 'queued' => 0, 'last_error' => null];
        $logAvailable = $this->mailLog !== null;

        if ($this->mailLog !== null) {
            try {
                $entries = $this->mailLog->recent(self::LOG_PAGE_SIZE, 0, $status);
                $stats = $this->mailLog->stats();
            } catch (Throwable) {
                $logAvailable = false;
            }
        }

        $diagnosis = null;
        if ($this->mailer !== null) {
            try {
                $diagnosis = $this->mailer->diagnose();
            } catch (Throwable) {
                $diagnosis = null;
            }
        }

        return [
            'mailEntries' => $entries,
            'mailStats' => $stats,
            'mailStatus' => $status,
            'mailLogAvailable' => $logAvailable,
            'mailDiagnosis' => $diagnosis,
            'testRecipient' => '',
        ];
    }
}
