<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Mail;

use PDO;
use PHPUnit\Framework\TestCase;
use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\Jobs\Job;
use Prime\Jobs\JobHandlerRegistry;
use Prime\Jobs\JobQueue;
use Prime\Jobs\JobRepository;
use Prime\Jobs\UnrecoverableJobException;
use Prime\Mail\LogMailer;
use Prime\Mail\MailConfig;
use Prime\Mail\Mailer;
use Prime\Mail\MailLogRepository;
use Prime\Mail\Message;
use Prime\Mail\NativeMailer;
use Prime\Mail\SendMailJob;
use Prime\Mail\SmtpException;
use Prime\Mail\SmtpTransport;
use Prime\Tests\Support\MysqlEmulatingPdo;
use Prime\View\View;
use RuntimeException;

/**
 * What the mailer decides: whether a message goes now or through the queue, what the filters may
 * change, and what the log has to say when the server says no.
 */
final class MailerTest extends TestCase
{
    private RecordingTransport $transport;

    private Hooks $hooks;

    private PDO $pdo;

    private MailLogRepository $logs;

    protected function setUp(): void
    {
        $this->transport = new RecordingTransport();
        $this->hooks = new Hooks();
        $this->pdo = new MysqlEmulatingPdo('id');
        $this->pdo->exec(
            'CREATE TABLE `pc_mail_log` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `recipient` TEXT NOT NULL,'
            . ' `subject` TEXT NOT NULL, `status` TEXT NOT NULL DEFAULT \'queued\', `transport` TEXT NOT NULL DEFAULT \'log\','
            . ' `template` TEXT NULL, `metadata_json` TEXT NULL, `error_message` TEXT NULL, `attempts` INTEGER NOT NULL DEFAULT 0,'
            . ' `job_id` INTEGER NULL, `sent_at` TEXT NULL, `created_at` TEXT NOT NULL, `updated_at` TEXT NOT NULL)',
        );
        $this->logs = new MailLogRepository($this->connection(), $this->hooks);
    }

    private function connection(): Connection
    {
        return new Connection(['prefix' => 'pc_'], hooks: $this->hooks, debug: false, pdo: $this->pdo);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function mailer(array $config = [], ?JobQueue $queue = null, ?View $view = null): Mailer
    {
        return new Mailer(
            new MailConfig($config + ['driver' => 'log', 'from_address' => 'studio@prime.test', 'from_name' => 'Studio']),
            $this->transport,
            $this->logs,
            $this->hooks,
            $queue,
            $view,
        );
    }

    private function message(): Message
    {
        return new Message(to: 'alice@example.fr', subject: 'Bienvenue', html: '<p>Salut</p>', text: 'Salut');
    }

    public function testASentMessageReachesTheTransportOnceAndTheLogOnce(): void
    {
        $sent = null;
        $this->hooks->on('mail.sent', static function (Message $message, string $transport, ?int $logId) use (&$sent): void {
            $sent = [$message->to, $transport, $logId];
        });

        $this->mailer()->send($this->message());

        $this->assertCount(1, $this->transport->sent);
        $this->assertSame(['alice@example.fr', 'mail', 1], $sent);
        $this->assertSame(1, $this->logs->count(MailLogRepository::STATUS_SENT));
    }

    public function testARefusalIsLoggedAndRethrown(): void
    {
        $this->transport->fail = new SmtpException('550 Relay refused', 550, false, 'relay');
        $failed = 0;
        $this->hooks->on('mail.failed', static function () use (&$failed): void {
            $failed++;
        });

        try {
            $this->mailer()->send($this->message());
            $this->fail('un échec d’envoi doit remonter');
        } catch (SmtpException $exception) {
            $this->assertSame('550 Relay refused', $exception->getMessage());
        }

        $row = $this->logs->recent(1)[0];
        $this->assertSame('failed', $row['status']);
        $this->assertSame('550 Relay refused', $row['error_message']);
        $this->assertSame(1, $failed);
    }

    public function testTheMailMessageFilterOwnsTheMessageBeforeItLeaves(): void
    {
        $this->hooks->filter('mail.message', static function (array $payload): array {
            $payload['subject'] = '[Campagne] ' . $payload['subject'];
            $payload['cc'] = ['archive@example.fr'];

            return $payload;
        });

        $this->mailer()->send($this->message());

        $this->assertSame('[Campagne] Bienvenue', $this->transport->sent[0]->subject);
        $this->assertSame(['archive@example.fr'], $this->transport->sent[0]->cc);
    }

    public function testAFilterCannotVacuumTheRecipientListIntoAnInvisibleDrop(): void
    {
        $this->hooks->filter('mail.message', static fn (array $payload): array => $payload + ['to' => '']);

        $this->mailer()->send($this->message());

        $this->assertCount(1, $this->transport->sent);
        $this->assertSame('alice@example.fr', $this->transport->sent[0]->to);
    }

    public function testTheQueueDriverHandsTheWorkToAWorkerInsteadOfTheVisitor(): void
    {
        $queue = $this->queue();

        $this->mailer(['driver' => 'queue'], $queue)->send($this->message());

        $this->assertSame([], $this->transport->sent, 'la requête ne doit pas attendre un socket SMTP');
        $this->assertTrue($queue->isQueued(SendMailJob::NAME), 'la tâche attend le worker');

        $row = $this->logs->recent(1)[0];
        $this->assertSame('queued', $row['status']);
        $this->assertSame('queue', $row['transport']);
        $this->assertSame(1, (int) $row['job_id']);

        $result = $queue->runNext('worker-1');
        $this->assertIsArray($result);
        $this->assertSame('completed', $result['status']);
        $this->assertCount(1, $this->transport->sent);
        $this->assertSame('Bienvenue', $this->transport->sent[0]->subject);
        $this->assertSame('sent', $this->logs->recent(1)[0]['status']);
        $this->assertFalse($queue->isQueued(SendMailJob::NAME), 'la file est vide après traitement');
    }

    public function testAQueueDriverWithoutAQueueStillSends(): void
    {
        $this->mailer(['driver' => 'queue'])->send($this->message());

        $this->assertCount(1, $this->transport->sent);
        $this->assertSame(1, $this->logs->count(MailLogRepository::STATUS_SENT));
    }

    public function testALogThatCannotBeWrittenMustNotCostTheMessage(): void
    {
        $this->pdo->exec('DROP TABLE `pc_mail_log`');

        $this->mailer()->send($this->message());

        $this->assertCount(1, $this->transport->sent);
    }

    public function testLogDisabledBySettingSkipsTheTableEntirely(): void
    {
        $this->mailer(['log_enabled' => false])->send($this->message());

        $this->assertCount(1, $this->transport->sent);
        $this->assertSame(0, $this->logs->count());
    }

    public function testATestMailReportsSuccessThroughTheActiveTransport(): void
    {
        $report = $this->mailer()->test('boss@example.fr');

        $this->assertTrue($report['ok'], $report['message']);
        $this->assertStringContainsString('boss@example.fr', $report['message']);
        $this->assertCount(1, $this->transport->sent);
        $this->assertStringContainsString('test', strtolower($this->transport->sent[0]->subject));
        $this->assertSame(1, $this->logs->count(MailLogRepository::STATUS_SENT));
    }

    public function testATestMailTurnsAFailureIntoAnAnswer(): void
    {
        $this->transport->fail = new RuntimeException('connect to smtp.test:587 failed');

        $report = $this->mailer()->test('boss@example.fr');

        $this->assertFalse($report['ok']);
        $this->assertSame('connect to smtp.test:587 failed', $report['message']);
        $this->assertSame(1, $this->logs->count(MailLogRepository::STATUS_FAILED));
    }

    public function testDiagnoseNamesTheProblemAnAdministratorCanActOn(): void
    {
        $report = $this->mailer(['driver' => 'smtp'])->diagnose();

        $this->assertFalse($report['configured']);
        $this->assertStringContainsString('SMTP', (string) $report['problem']);
        $this->assertSame('mail', $report['transport'], 'le nom du transport actif, pas le pilote configuré');

        $fine = $this->mailer([
            'driver' => 'smtp',
            'host' => 'smtp.ovh.fr',
            'username' => 'u',
            'password' => 'p',
        ])->diagnose();

        $this->assertTrue($fine['configured']);
        $this->assertNull($fine['problem']);
        $this->assertTrue($fine['log_enabled']);
        $this->assertSame(0, $fine['stats']['total']);
    }

    public function testTheTransportNameFollowsTheActiveTransport(): void
    {
        $config = new MailConfig(['driver' => 'smtp', 'host' => 'smtp.test']);

        $this->assertSame('smtp', (new Mailer($config, new SmtpTransport($config)))->transportName());
        $this->assertSame('log', (new Mailer(new MailConfig(), new LogMailer(sys_get_temp_dir())))->transportName());
        $this->assertSame('native', (new Mailer(new MailConfig(), new NativeMailer()))->transportName());
        $this->assertSame('mail', (new Mailer(new MailConfig(), $this->transport))->transportName());
    }

    public function testTemplatesComeFromTheMailNamespaceAndAThemeCanTakePrecedence(): void
    {
        $base = sys_get_temp_dir() . '/prime_mail_' . bin2hex(random_bytes(6));
        mkdir($base . '/mail', 0777, true);
        mkdir($base . '/theme', 0777, true);
        file_put_contents($base . '/mail/contact.php', '<p>Par défaut pour <?= $site_name ?> : <?= $subject ?></p>');
        file_put_contents($base . '/theme/contact.php', '<p>Version du thème</p>');

        $view = new View($base);
        $view->addNamespace('mail', $base . '/mail');

        $message = $this->mailer(view: $view)->render('contact', 'a@b.fr', 'Contact', ['site_name' => 'Atelier']);

        $this->assertStringContainsString('Par défaut pour Atelier : Contact', $message->html);
        $this->assertSame('Par défaut pour Atelier : Contact', $message->text);

        $view->addNamespace('mail', $base . '/theme', true);
        $overridden = $this->mailer(view: $view)->render('contact', 'a@b.fr', 'Contact', ['site_name' => 'Atelier']);
        $this->assertStringContainsString('Version du thème', $overridden->html);

        $this->assertFalse($this->mailer(view: $view)->hasTemplate('inconnue'));
        $fallback = $this->mailer(view: $view)->render('inconnue', 'a@b.fr', 'Sujet', ['body' => 'Corps brut']);
        $this->assertSame('Corps brut', $fallback->text);

        array_map('unlink', glob($base . '/*/*.php') ?: []);
        array_map('rmdir', [$base . '/mail', $base . '/theme', $base]);
    }

    public function testThePayloadIsWhatTheWorkerNeedsAndNothingMore(): void
    {
        $message = new Message(
            to: 'alice@example.fr',
            subject: 'Facture',
            html: '<p>x</p>',
            text: 'x',
            replyTo: 'compta@example.fr',
            cc: ['boss@example.fr'],
            bcc: ['archive@example.fr'],
        );

        $payload = Mailer::payload($message, 'invoice', ['amount' => 12]);
        $rebuilt = Mailer::fromPayload($payload);

        $this->assertSame('alice@example.fr', $payload['to']);
        $this->assertSame(['amount' => 12], $payload['data']);
        $this->assertSame('invoice', $payload['template']);
        $this->assertSame('Facture', $rebuilt->subject);
        $this->assertSame('compta@example.fr', $rebuilt->replyTo);
        $this->assertSame(['boss@example.fr'], $rebuilt->cc);
        $this->assertSame(['archive@example.fr'], $rebuilt->bcc);
        $this->assertArrayNotHasKey('password', $payload);
    }

    public function testTheJobSendsAndTheLogLinksTheRowToTheJob(): void
    {
        $mailer = $this->mailer();
        $job = new Job(id: 9, queue: 'default', handler: SendMailJob::NAME, payload: Mailer::payload($this->message()), attempts: 1);

        (new SendMailJob($mailer))->handle(Mailer::payload($this->message()), $job);

        $this->assertCount(1, $this->transport->sent);
        $row = $this->logs->recent(1)[0];
        $this->assertSame('sent', $row['status']);
        $this->assertSame(9, (int) $row['job_id']);
    }

    public function testTheJobParksAPermanentRefusalInsteadOfRetryingIt(): void
    {
        $this->transport->fail = new SmtpException('550 no such user', 550, false, 'rcpt');

        $this->expectException(UnrecoverableJobException::class);
        $this->expectExceptionMessage('550 no such user');

        (new SendMailJob($this->mailer()))->handle(
            Mailer::payload($this->message()),
            new Job(id: 9, queue: 'default', handler: SendMailJob::NAME, attempts: 1),
        );
    }

    public function testTheJobLetsTheQueueBackOffOnATransientRefusal(): void
    {
        $this->transport->fail = new SmtpException('451 try again', 451, true, 'data');

        try {
            (new SendMailJob($this->mailer()))->handle(
                Mailer::payload($this->message()),
                new Job(id: 9, queue: 'default', handler: SendMailJob::NAME, attempts: 1),
            );
            $this->fail('un 451 doit être réessayé');
        } catch (UnrecoverableJobException) {
            $this->fail('une erreur temporaire ne doit pas être définitive');
        } catch (SmtpException $exception) {
            $this->assertTrue($exception->isRetryable());
        }

        $this->assertSame('failed', $this->logs->recent(1)[0]['status']);
    }

    public function testTheJobRefusesAMessageWithoutRecipient(): void
    {
        $this->expectException(UnrecoverableJobException::class);

        (new SendMailJob($this->mailer()))->handle(
            ['subject' => 'vide'],
            new Job(id: 10, queue: 'default', handler: SendMailJob::NAME, attempts: 1),
        );

        $this->assertSame([], $this->transport->sent);
    }

    private function queue(): JobQueue
    {
        $this->pdo->exec(
            'CREATE TABLE `pc_jobs` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `queue` TEXT NOT NULL DEFAULT \'default\','
            . ' `handler` TEXT NOT NULL, `payload` TEXT NOT NULL, `attempts` INTEGER NOT NULL DEFAULT 0,'
            . ' `max_attempts` INTEGER NOT NULL DEFAULT 3, `available_at` TEXT NOT NULL, `reserved_at` TEXT NULL,'
            . ' `failed_at` TEXT NULL, `last_error` TEXT NULL, `created_at` TEXT NOT NULL)',
        );

        $handlers = new JobHandlerRegistry();

        // What a real worker does: rebuild a mailer without a queue, so the job delivers and never
        // re-pushes itself.
        $handlers->register(SendMailJob::NAME, function (array $payload, Job $job): void {
            (new SendMailJob($this->mailer(['driver' => 'queue'])))->handle($payload, $job);
        });

        return new JobQueue(new JobRepository($this->connection()), $handlers, $this->hooks);
    }
}
