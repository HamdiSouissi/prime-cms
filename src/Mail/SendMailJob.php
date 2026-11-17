<?php

declare(strict_types=1);

namespace Prime\Mail;

use Prime\Jobs\Job;
use Prime\Jobs\JobHandlerInterface;
use Prime\Jobs\UnrecoverableJobException;

/**
 * `mail.send`: deliver a message a request queued instead of making the visitor wait for a socket.
 *
 * The payload carries either a rendered message or a template plus its data. Rendering happens here,
 * at send time, on purpose: a queued password-reset that was rendered when it was pushed would leak
 * its token into `pc_jobs.payload` where anybody reading the database could use it.
 */
final class SendMailJob implements JobHandlerInterface
{
    public const NAME = 'mail.send';

    public function __construct(private readonly Mailer $mailer)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, Job $job): void
    {
        $template = isset($payload['template']) && is_string($payload['template']) ? $payload['template'] : null;
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];
        $message = Mailer::fromPayload($payload);

        if ($template !== null && $message->to !== '' && $this->mailer->hasTemplate($template)) {
            $message = $this->mailer->render(
                $template,
                $message->to,
                (string) ($payload['subject'] ?? ''),
                $data,
            );
        }

        if ($message->to === '') {
            throw new UnrecoverableJobException('E-mail en file sans destinataire : rien à envoyer.');
        }

        try {
            $this->mailer->deliver($message, $template, $data, $job->id);
        } catch (SmtpException $exception) {
            if (!$exception->isRetryable()) {
                throw new UnrecoverableJobException($exception->getMessage(), (int) $exception->getCode(), $exception);
            }

            throw $exception;
        }
    }
}
