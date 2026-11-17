<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Mail;

use PDO;
use PHPUnit\Framework\TestCase;
use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\Mail\MailLogRepository;
use Prime\Mail\Message;
use Prime\Tests\Support\MysqlEmulatingPdo;

/**
 * The log the administrator reads when mail is broken: one row per attempt, no message body, and a
 * retention rule that actually shrinks the table.
 */
final class MailLogRepositoryTest extends TestCase
{
    private PDO $pdo;

    private Hooks $hooks;

    private MailLogRepository $logs;

    protected function setUp(): void
    {
        $this->pdo = new MysqlEmulatingPdo('id');
        $this->pdo->exec(
            'CREATE TABLE `pc_mail_log` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `recipient` TEXT NOT NULL,'
            . ' `subject` TEXT NOT NULL, `status` TEXT NOT NULL DEFAULT \'queued\', `transport` TEXT NOT NULL DEFAULT \'log\','
            . ' `template` TEXT NULL, `metadata_json` TEXT NULL, `error_message` TEXT NULL, `attempts` INTEGER NOT NULL DEFAULT 0,'
            . ' `job_id` INTEGER NULL, `sent_at` TEXT NULL, `created_at` TEXT NOT NULL, `updated_at` TEXT NOT NULL)',
        );

        $this->hooks = new Hooks();
        $this->logs = new MailLogRepository(new Connection(['prefix' => 'pc_'], hooks: $this->hooks, debug: false, pdo: $this->pdo), $this->hooks);
    }

    public function testASentMessageIsRecordedWithoutItsBody(): void
    {
        $fired = [];
        $this->hooks->on('mail.log.recorded', static function (int $id, string $status) use (&$fired): void {
            $fired[] = [$id, $status];
        });

        $id = $this->logs->record(
            new Message(to: 'alice@example.fr', subject: 'Réinitialisation', html: '<a href="https://x.fr/t?token=secret">lien</a>', text: 'token=secret'),
            MailLogRepository::STATUS_SENT,
            'smtp',
            ['data' => ['resetUrl' => 'https://x.fr/t?token=secret']],
            null,
            'password-reset',
            42,
        );

        $row = $this->logs->find($id);

        $this->assertIsArray($row);
        $this->assertSame('alice@example.fr', $row['recipient']);
        $this->assertSame('sent', $row['status']);
        $this->assertSame('password-reset', $row['template']);
        $this->assertSame(42, $row['job_id']);
        $this->assertNotNull($row['sent_at']);
        $this->assertNull($row['error_message']);
        $this->assertSame(['data' => ['resetUrl' => 'https://x.fr/t?token=secret']], $row['metadata']);
        $this->assertSame([[1, 'sent']], $fired);

        // The body is the leak: a reset token stored in a row readable from the admin is a live link.
        $serialized = (string) json_encode($row);
        $this->assertStringNotContainsString('token=secret', (string) $row['subject']);
        $this->assertStringNotContainsString('<a href', $serialized);
        $this->assertStringNotContainsString('metadata_json', $serialized);
    }

    public function testAFailureKeepsTheServerMessageAndNoSentAt(): void
    {
        $id = $this->logs->record(
            new Message(to: 'bob@example.fr', subject: 'Facture', text: 'x'),
            MailLogRepository::STATUS_FAILED,
            'smtp',
            [],
            '550 5.1.1 Recipient address rejected',
        );

        $row = $this->logs->find($id);

        $this->assertIsArray($row);
        $this->assertNull($row['sent_at']);
        $this->assertSame('550 5.1.1 Recipient address rejected', $row['error_message']);
        $this->assertSame([], $row['metadata']);
    }

    public function testALongSubjectIsCutInsteadOfBreakingTheInsert(): void
    {
        $id = $this->logs->record(new Message(to: 'a@b.fr', subject: str_repeat('sujet ', 60), text: 'x'), MailLogRepository::STATUS_SENT, 'log');

        $row = $this->logs->find($id);
        $this->assertIsArray($row);
        $this->assertLessThanOrEqual(255, strlen((string) $row['subject']));
        $this->assertStringEndsWith('...', (string) $row['subject']);
    }

    public function testMultipleRecipientsAreStoredAsOneLine(): void
    {
        $id = $this->logs->record(new Message(to: 'a@b.fr, c@d.fr', subject: 's', text: 'x'), MailLogRepository::STATUS_SENT, 'smtp');
        $row = $this->logs->find($id);

        $this->assertSame('a@b.fr, c@d.fr', $row['recipient'] ?? null);
    }

    public function testRecentOrdersNewestFirstAndFiltersByStatus(): void
    {
        $this->logs->record(new Message(to: 'a@b.fr', subject: 'un', text: 'x'), MailLogRepository::STATUS_SENT, 'log');
        $this->logs->record(new Message(to: 'b@b.fr', subject: 'deux', text: 'x'), MailLogRepository::STATUS_FAILED, 'log');
        $this->logs->record(new Message(to: 'c@b.fr', subject: 'trois', text: 'x'), MailLogRepository::STATUS_SENT, 'smtp');

        $recent = $this->logs->recent(10);
        $this->assertSame(['trois', 'deux', 'un'], array_map(static fn (array $r): string => (string) $r['subject'], $recent));

        $failed = $this->logs->recent(10, 0, MailLogRepository::STATUS_FAILED);
        $this->assertCount(1, $failed);
        $this->assertSame('deux', $failed[0]['subject']);

        $this->assertSame(['trois'], array_map(static fn (array $r): string => (string) $r['subject'], $this->logs->recent(1, 0)));
        $this->assertCount(1, $this->logs->recent(1), 'la limite est bornée');
        $this->assertSame(['un'], array_map(static fn (array $r): string => (string) $r['subject'], $this->logs->recent(10, 2)));
    }

    public function testStatsSplitByStatusAndSurfaceTheLastFailure(): void
    {
        $this->logs->record(new Message(to: 'a@b.fr', subject: 'ok', text: 'x'), MailLogRepository::STATUS_SENT, 'log');
        $this->logs->record(new Message(to: 'ghost@b.fr', subject: 'ko', text: 'x'), MailLogRepository::STATUS_FAILED, 'smtp', [], 'SMTP a dit non');
        $this->logs->record(new Message(to: 'c@b.fr', subject: 'file', text: 'x'), MailLogRepository::STATUS_QUEUED, 'queue');

        $stats = $this->logs->stats();

        $this->assertSame(3, $stats['total']);
        $this->assertSame(1, $stats['sent']);
        $this->assertSame(1, $stats['failed']);
        $this->assertSame(1, $stats['queued']);
        $this->assertSame('SMTP a dit non — ghost@b.fr', $stats['last_error']);
    }

    public function testAnEmptyLogHasNoErrorToReport(): void
    {
        $this->assertSame(['total' => 0, 'sent' => 0, 'failed' => 0, 'queued' => 0, 'last_error' => null], $this->logs->stats());
        $this->assertSame([], $this->logs->recent());
        $this->assertNull($this->logs->lastError());
    }

    public function testPruneOnlyAgesOutTheRowsPastTheRetention(): void
    {
        $fresh = $this->logs->record(new Message(to: 'a@b.fr', subject: 'fraîche', text: 'x'), MailLogRepository::STATUS_SENT, 'log');
        $stale = $this->logs->record(new Message(to: 'b@b.fr', subject: 'ancienne', text: 'x'), MailLogRepository::STATUS_SENT, 'log');

        $this->pdo->exec('UPDATE `pc_mail_log` SET `created_at` = \'2020-01-01 00:00:00\' WHERE `id` = ' . $stale);

        $purged = 0;
        $this->hooks->on('mail.log.purged', static function (int $deleted, int $days) use (&$purged): void {
            $purged = $deleted;
        });

        $this->assertSame(1, $this->logs->prune(30));
        $this->assertSame(1, $purged);
        $this->assertIsArray($this->logs->find($fresh));
        $this->assertNull($this->logs->find($stale));
        $this->assertSame(0, $this->logs->prune(3650), 'rien de plus ancien à supprimer');
        $this->assertSame(0, $this->logs->prune(1), 'une ligne du jour n’est pas encore ancienne');
    }

    public function testARetriedAttemptUpdatesTheRowItself(): void
    {
        $id = $this->logs->record(new Message(to: 'a@b.fr', subject: 's', text: 'x'), MailLogRepository::STATUS_FAILED, 'smtp', [], 'timeout');

        $this->logs->noteAttempt($id, MailLogRepository::STATUS_SENT);

        $row = $this->logs->find($id);
        $this->assertIsArray($row);
        $this->assertSame(2, (int) $row['attempts']);
        $this->assertSame('sent', $row['status']);
        $this->assertNull($row['error_message']);
        $this->assertNotNull($row['sent_at']);

        $this->logs->noteAttempt(9999, MailLogRepository::STATUS_SENT);
    }

    public function testAFindOnAMissingRowIsAnAnswerAndNotAnError(): void
    {
        $this->assertNull($this->logs->find(1234));
    }
}
