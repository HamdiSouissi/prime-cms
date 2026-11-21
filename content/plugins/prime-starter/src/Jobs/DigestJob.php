<?php

declare(strict_types=1);

namespace PrimeStarter\Jobs;

use DateTimeImmutable;
use DateTimeZone;
use Prime\Jobs\Job;
use Prime\Jobs\JobHandlerInterface;
use Prime\Mail\Mailer;
use Prime\Plugins\PluginContext;
use PrimeStarter\NoteRepository;

/**
 * `prime-starter.digest`: e-mails the notes changed during the last `days` days to the address
 * set in « Réglages › Général › Carnet ». No address, or nothing changed: nothing is sent.
 */
final class DigestJob implements JobHandlerInterface
{
    public function __construct(private readonly PluginContext $plugin)
    {
    }

    public function handle(array $payload, Job $job): void
    {
        $to = trim((string) $this->plugin->settings()->get('digest_email', ''));
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $days = is_numeric($payload['days'] ?? null) ? max(1, min(31, (int) $payload['days'])) : 7;
        $since = new DateTimeImmutable('-' . $days . ' days', new DateTimeZone('UTC'));
        $notes = $this->plugin->app()->get(NoteRepository::class)->changedSince($since);
        if ($notes === []) {
            return;
        }

        $base = rtrim((string) $this->plugin->app()->config()->get('app.url', ''), '/');
        $this->plugin->app()->get(Mailer::class)->sendTemplate(
            'prime-starter::mail/digest',
            $to,
            tc('plugin::prime-starter.mail.subject', count($notes), ['count' => count($notes)]),
            [
                'notes' => array_map(static fn (array $note): array => [
                    'title' => $note['title'],
                    'url' => $base . '/admin/prime-starter/notes/' . $note['id'] . '/edit',
                ], $notes),
                'days' => $days,
                'adminUrl' => $base . '/admin/prime-starter',
            ],
        );
    }
}
