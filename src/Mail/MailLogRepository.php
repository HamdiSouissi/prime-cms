<?php

declare(strict_types=1);

namespace Prime\Mail;

use Prime\Database\Connection;
use Prime\Hooks\Hooks;

/**
 * `pc_mail_log`: one row per delivery attempt, written by {@see Mailer}.
 *
 * The body is deliberately not stored — a password-reset link inside a log row readable from the
 * admin would leak a live token. What is kept is enough to diagnose and, for template-driven
 * notifications, enough to resend: recipient, subject, template name, and the render data.
 */
final class MailLogRepository
{
    public const TABLE = 'mail_log';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly Connection $db,
        private readonly ?Hooks $hooks = null,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function record(
        Message $message,
        string $status,
        string $transport,
        array $metadata = [],
        ?string $error = null,
        ?string $template = null,
        ?int $jobId = null,
    ): int {
        $now = gmdate('Y-m-d H:i:s');
        $subject = $message->subject;

        if (strlen($subject) > 255) {
            $subject = substr($subject, 0, 252) . '...';
        }

        $id = $this->db->table(self::TABLE)->insertGetId([
            'recipient' => mb_substr(implode(', ', MimeBuilder::splitAddresses($message->to)), 0, 255),
            'subject' => $subject,
            'status' => $status,
            'transport' => $transport,
            'template' => $template,
            'metadata_json' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error_message' => $error,
            'attempts' => 1,
            'job_id' => $jobId,
            'sent_at' => $status === self::STATUS_SENT ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->hooks?->emit('mail.log.recorded', $id, $status, $template);

        return $id;
    }

    /**
     * Bump the attempt counter of an existing row instead of duplicating it (queue retries).
     */
    public function noteAttempt(int $id, string $status, ?string $error = null): void
    {
        $row = $this->db->table(self::TABLE)->find($id);

        if ($row === null) {
            return;
        }

        $this->db->table(self::TABLE)
            ->where('id', '=', $id)
            ->update([
                'status' => $status,
                'attempts' => (int) $row['attempts'] + 1,
                'error_message' => $error,
                'sent_at' => $status === self::STATUS_SENT ? gmdate('Y-m-d H:i:s') : null,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 25, int $offset = 0, ?string $status = null): array
    {
        $query = $this->db->table(self::TABLE)
            ->orderBy('id', 'DESC')
            ->limit(max(1, min(200, $limit)))
            ->offset(max(0, $offset));

        if ($status !== null && $status !== '') {
            $query->where('status', '=', $status);
        }

        return array_map([$this, 'hydrate'], $query->get());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->table(self::TABLE)->find($id);

        return $row === null ? null : $this->hydrate($row);
    }

    public function count(?string $status = null): int
    {
        $query = $this->db->table(self::TABLE);

        if ($status !== null && $status !== '') {
            $query->where('status', '=', $status);
        }

        return $query->count();
    }

    /**
     * @return array{total: int, sent: int, failed: int, queued: int, last_error: ?string}
     */
    public function stats(): array
    {
        // One grouped count instead of four (this feeds the dashboard and the system status).
        $byStatus = [];
        foreach ($this->db->select(sprintf('SELECT `status`, COUNT(*) AS `cnt` FROM `%s` GROUP BY `status`', $this->db->prefixed(self::TABLE))) as $row) {
            $byStatus[(string) ($row['status'] ?? '')] = (int) ($row['cnt'] ?? 0);
        }

        return [
            'total' => array_sum($byStatus),
            'sent' => $byStatus[self::STATUS_SENT] ?? 0,
            'failed' => $byStatus[self::STATUS_FAILED] ?? 0,
            'queued' => $byStatus[self::STATUS_QUEUED] ?? 0,
            'last_error' => $this->lastError(),
        ];
    }

    /**
     * Age out everything older than the retention the administrator set. The counter is what makes
     * `mail.log_days` trustworthy: a prune that quietly protected rows would let the table grow forever.
     */
    public function prune(int $days): int
    {
        $days = max(1, min(365, $days));
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);

        $deleted = $this->db->table(self::TABLE)->where('created_at', '<', $cutoff)->delete();
        $this->hooks?->emit('mail.log.purged', $deleted, $days);

        return $deleted;
    }

    /**
     * The message an administrator should see on the health notice, if anything failed recently.
     */
    public function lastError(): ?string
    {
        $row = $this->db->table(self::TABLE)
            ->where('status', '=', self::STATUS_FAILED)
            ->orderBy('id', 'DESC')
            ->first();

        if ($row === null || !isset($row['error_message']) || !is_string($row['error_message']) || $row['error_message'] === '') {
            return null;
        }

        return sprintf('%s — %s', $row['error_message'], (string) ($row['recipient'] ?? ''));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $metadata = null;

        if (isset($row['metadata_json']) && is_string($row['metadata_json']) && $row['metadata_json'] !== '') {
            $decoded = json_decode($row['metadata_json'], true);
            $metadata = is_array($decoded) ? $decoded : null;
        }

        $row['metadata'] = $metadata ?? [];
        unset($row['metadata_json']);

        return $row;
    }
}
