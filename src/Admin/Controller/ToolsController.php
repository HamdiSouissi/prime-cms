<?php

declare(strict_types=1);

namespace Prime\Admin\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Prime\Admin\AdminContext;
use Prime\Admin\SystemStatus;
use Prime\Admin\TranslatesAdminCopy;
use Prime\Content\LabelTranslator;
use Prime\Http\Exception\NotFoundException;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Jobs\Job;
use Prime\Jobs\JobRepository;
use Prime\Scheduler\Schedule;
use Prime\Scheduler\SchedulerRunner;
use Prime\Settings\SettingsFormProcessor;
use Prime\Settings\SettingsRegistry;
use Prime\View\View;
use Throwable;

/**
 * « Outils » : scheduled tasks, the job queue and the system status report.
 */
final class ToolsController
{
    use TranslatesAdminCopy;

    public function __construct(
        private readonly View $view,
        private readonly AdminContext $context,
        private readonly SystemStatus $status,
        private readonly ?Schedule $schedule = null,
        private readonly ?SchedulerRunner $runner = null,
        private readonly ?JobRepository $jobs = null,
        private readonly ?SettingsRegistry $registry = null,
        private readonly ?SettingsFormProcessor $processor = null,
    ) {
    }

    public function landing(Request $request): Response
    {
        return Response::redirect('/admin/tools/scheduler');
    }

    /**
     * `GET /admin/tools/scheduler`.
     */
    public function scheduler(Request $request): Response
    {
        $now = time();
        $lastRuns = $this->runner?->lastRuns() ?? [];
        $errors = $this->runner?->lastErrors() ?? [];
        $tasks = [];

        foreach ($this->schedule?->tasks() ?? [] as $task) {
            $last = isset($lastRuns[$task->name])
                ? DateTimeImmutable::createFromFormat(SchedulerRunner::TICK_FORMAT, $lastRuns[$task->name], new DateTimeZone('UTC'))
                : null;
            $next = $task->nextRun((new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('UTC')));

            $tasks[] = [
                'name' => $task->name,
                'description' => $this->taskDescription($task->name, $task->description),
                'expression' => $task->expression(),
                'owner' => $task->owner,
                'last_run' => $last instanceof DateTimeImmutable ? $last->getTimestamp() : null,
                'next_run' => $next?->getTimestamp(),
                'error' => $errors[$task->name] ?? null,
            ];
        }

        $heartbeat = $this->runner?->lastHeartbeat();

        return $this->render($request, 'scheduler', [
            'tasks' => $tasks,
            'heartbeat' => $heartbeat,
            'cronStale' => $heartbeat === null || ($now - $heartbeat) > SystemStatus::HEARTBEAT_STALE_SECONDS,
            'now' => $now,
            'schedulerFields' => $this->registry?->fields('scheduler') ?? [],
            'basePath' => $this->status->basePath(),
        ]);
    }

    /**
     * `POST /admin/tools/scheduler/{name}/run`.
     */
    public function runTask(Request $request, string $name): Response
    {
        $result = $this->runner?->runOne($name);

        if ($result === null) {
            throw new NotFoundException(t('admin.tools.scheduler.task_missing'));
        }

        $this->context->flashToast(
            match ($result['status']) {
                'ran' => t('admin.tools.scheduler.ran', ['name' => $name, 'ms' => $result['duration_ms']]),
                'skipped' => t('admin.tools.scheduler.skipped', ['name' => $name]),
                default => t('admin.tools.scheduler.failed', ['name' => $name, 'error' => (string) $result['error']]),
            },
            $result['status'] === 'ran' ? 'success' : ($result['status'] === 'skipped' ? 'warning' : 'danger'),
        );

        return Response::redirect('/admin/tools/scheduler');
    }

    /**
     * `POST /admin/tools/scheduler/settings`.
     */
    public function saveSchedulerSettings(Request $request): Response
    {
        if ($this->processor === null) {
            return Response::redirect('/admin/tools/scheduler');
        }

        $raw = $request->body('settings', []);
        /** @var array<string, mixed> $input */
        $input = is_array($raw) ? $raw : [];
        $result = $this->processor->process('scheduler', $input);

        if ($result['errors'] !== []) {
            $this->context->flashToast(implode(' ', $result['errors']), 'danger');
        } else {
            $this->context->flashToast($result['saved'] !== [] ? t('admin.settings.saved') : t('admin.settings.nothing_changed'), $result['saved'] !== [] ? 'success' : 'info');
        }

        return Response::redirect('/admin/tools/scheduler');
    }

    /**
     * `GET /admin/tools/queue`.
     */
    public function queue(Request $request): Response
    {
        $pending = [];
        $failed = [];
        $stats = ['pending' => 0, 'reserved' => 0, 'failed' => 0, 'delayed' => 0, 'oldest_pending' => null];
        $available = $this->jobs !== null;

        if ($this->jobs !== null) {
            try {
                $pending = $this->jobs->pending(50);
                $failed = $this->jobs->failed(50);
                $stats = $this->jobs->stats();
            } catch (Throwable) {
                $available = false;
            }
        }

        return $this->render($request, 'queue', [
            'pendingJobs' => array_map([$this, 'jobRow'], $pending),
            'failedJobs' => array_map([$this, 'jobRow'], $failed),
            'queueStats' => $stats,
            'queueAvailable' => $available,
        ]);
    }

    /**
     * `POST /admin/tools/queue/{id}/retry`.
     */
    public function retryJob(Request $request, string $id): Response
    {
        $ok = $this->jobs?->retry((int) $id) ?? false;
        $this->context->flashToast($ok ? t('admin.tools.queue.retried') : t('admin.tools.queue.job_missing'), $ok ? 'success' : 'danger');

        return Response::redirect('/admin/tools/queue');
    }

    /**
     * `POST /admin/tools/queue/retry-all`.
     */
    public function retryAll(Request $request): Response
    {
        $count = $this->jobs?->retryAll() ?? 0;
        $this->context->flashToast(t('admin.tools.queue.retried_all', ['count' => $count]), 'success');

        return Response::redirect('/admin/tools/queue');
    }

    /**
     * `POST /admin/tools/queue/{id}/delete`.
     */
    public function deleteJob(Request $request, string $id): Response
    {
        $ok = $this->jobs?->delete((int) $id) ?? false;
        $this->context->flashToast($ok ? t('admin.tools.queue.deleted') : t('admin.tools.queue.job_missing'), $ok ? 'success' : 'danger');

        return Response::redirect('/admin/tools/queue');
    }

    /**
     * `GET /admin/tools/system`.
     */
    public function system(Request $request): Response
    {
        $checks = $this->status->checks();
        $groups = [];
        foreach ($checks as $check) {
            $groups[$check['group']][] = $check;
        }

        return $this->render($request, 'system', [
            'checkGroups' => $groups,
            'overall' => SystemStatus::overall($checks),
            'report' => $this->status->report($checks),
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function render(Request $request, string $tab, array $extra): Response
    {
        $title = t('admin.tools.tabs.' . $tab);

        return Response::html($this->view->render('admin::tools/' . $tab, $this->context->build($request, $title, $extra + [
            'activeTab' => $tab,
            'breadcrumbs' => [
                ['label' => t('admin.nav.dashboard'), 'url' => '/admin'],
                ['label' => t('admin.tools.title'), 'url' => '/admin/tools'],
                ['label' => $title, 'url' => null],
            ],
        ])));
    }

    /**
     * @return array{id: int, queue: string, handler: string, attempts: int, max_attempts: int, available_at: string, reserved: bool, failed_at: ?string, error: ?string, created_at: string}
     */
    private function jobRow(Job $job): array
    {
        return [
            'id' => $job->id,
            'queue' => $job->queue,
            'handler' => $job->handler,
            'attempts' => $job->attempts,
            'max_attempts' => $job->maxAttempts,
            'available_at' => $job->availableAt,
            'reserved' => $job->reservedAt !== null,
            'failed_at' => $job->failedAt,
            'error' => $job->lastError,
            'created_at' => $job->createdAt,
        ];
    }

    private function taskDescription(string $name, ?string $fallback): string
    {
        $key = 'core.scheduler.tasks.' . str_replace('.', '_', $name);
        $translated = t($key);

        if ($translated !== $key) {
            return $translated;
        }

        return $fallback !== null ? LabelTranslator::resolve($fallback) : '';
    }
}
