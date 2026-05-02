<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Support;

use AdityaaCodes\LaravelCheckpoint\Events\BackupCompleted;
use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillCompleted;
use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillFreshnessAlarmTriggered;
use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillPassRateAlarmTriggered;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFreshnessAlarmTriggered;
use AdityaaCodes\LaravelCheckpoint\Events\BackupQueued;
use AdityaaCodes\LaravelCheckpoint\Events\BackupStarted;
use AdityaaCodes\LaravelCheckpoint\Events\OrphanRunRedispatched;
use AdityaaCodes\LaravelCheckpoint\Events\QueueLagDetected;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;

final class NotificationEventPayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(object $event, string $eventKey): array
    {
        return match (true) {
            $event instanceof BackupQueued => $this->withRun($event->run),
            $event instanceof BackupStarted => $this->withRun($event->run),
            $event instanceof BackupCompleted => $this->withRun($event->run) + [
                'exit_code' => $event->exitCode,
                'output' => $event->output,
            ],
            $event instanceof BackupFailed => $this->withRun($event->run) + [
                'exit_code' => $event->exitCode,
                'output' => $event->output,
                'exception' => $event->exception?->getMessage(),
                'version' => $event->version,
            ],
            $event instanceof BackupFreshnessAlarmTriggered => [
                'run' => $event->run instanceof CommandRun ? $this->runPayload($event->run) : null,
                'reason' => $event->reason,
                'age_hours' => $event->ageHours,
                'threshold_hours' => $event->thresholdHours,
                'version' => $event->version,
            ],
            $event instanceof BackupDrillCompleted => [
                'run' => $this->drillRunPayload($event->run),
            ],
            $event instanceof BackupDrillFreshnessAlarmTriggered => [
                'run' => $event->run instanceof BackupDrillRun ? $this->drillRunPayload($event->run) : null,
                'reason' => $event->reason,
                'age_days' => $event->ageDays,
                'threshold_days' => $event->thresholdDays,
                'remediation' => $this->freshnessAlarmRemediationPayload($event),
                'version' => $event->version,
            ],
            $event instanceof BackupDrillPassRateAlarmTriggered => [
                'window_days' => $event->windowDays,
                'passing' => $event->passing,
                'total' => $event->total,
                'pass_rate_percent' => $event->passRatePercent,
                'threshold_percent' => $event->thresholdPercent,
                'latest_run' => $event->latestRun instanceof BackupDrillRun ? $this->drillRunPayload($event->latestRun) : null,
                'remediation' => $this->passRateAlarmRemediationPayload($event),
                'version' => $event->version,
            ],
            $event instanceof QueueLagDetected => [
                'queue' => $event->queue,
                'stale_run_count' => $event->staleRunCount,
                'threshold_minutes' => $event->thresholdMinutes,
                'oldest_stale_age_minutes' => $event->oldestStaleAgeMinutes,
                'stale_run_ids' => $event->staleRunIds,
                'stale_run_ids_truncated' => $event->staleRunIdsTruncated,
                'version' => $event->version,
            ],
            $event instanceof OrphanRunRedispatched => $this->withRun($event->run) + [
                'queue' => $event->queue,
                'threshold_minutes' => $event->thresholdMinutes,
                'stale_age_minutes' => $event->staleAgeMinutes,
                'version' => $event->version,
            ],
            default => [
                'event_key' => $eventKey,
                'event_class' => $event::class,
            ],
        };
    }

    /**
     * @return array{run: array<string, mixed>}
     */
    private function withRun(CommandRun $run): array
    {
        return ['run' => $this->runPayload($run)];
    }

    /**
     * @return array<string, mixed>
     */
    private function runPayload(CommandRun $run): array
    {
        return [
            'id' => $run->id,
            'operation' => $run->operation,
            'status' => $run->status->value,
            'driver' => $run->driver_name,
            'repository' => $run->repository,
            'stanza' => $run->stanza,
            'backup_type' => $run->backup_type,
            'backup_label' => $run->backup_label,
            'verification_state' => $run->verification_state,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function drillRunPayload(BackupDrillRun $run): array
    {
        return [
            'id' => $run->id,
            'run_uuid' => $run->run_uuid,
            'overall_result' => $run->overall_result,
            'executed_at' => $run->executed_at->toIso8601String(),
            'marker_result' => $run->marker_result,
            'rto_result' => $run->rto_result,
            'rpo_result' => $run->rpo_result,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function freshnessAlarmRemediationPayload(BackupDrillFreshnessAlarmTriggered $event): array
    {
        $signature = $event->reason === 'missing' ? 'drill.missing_run' : 'drill.stale_run';
        $title = $event->reason === 'missing'
            ? 'No backup drill evidence available'
            : 'Backup drill evidence is stale';
        $summary = $event->reason === 'missing'
            ? 'No backup drill run is recorded. Schedule and record a drill run before relying on restore readiness.'
            : 'Latest backup drill evidence is stale. Run a fresh drill and verify health checks.';

        return [
            'signature' => $signature,
            'severity' => 'critical',
            'title' => $title,
            'summary' => $summary,
            'recommended_commands' => [
                'php artisan checkpoint:enqueue-drill',
                'php artisan checkpoint:doctor --format=json',
            ],
            'evidence' => [
                'reason' => $event->reason,
                'age_days' => $event->ageDays,
                'threshold_days' => $event->thresholdDays,
                'run_uuid' => $event->run?->run_uuid,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function passRateAlarmRemediationPayload(BackupDrillPassRateAlarmTriggered $event): array
    {
        return [
            'signature' => $event->total < 1 ? 'drill.missing_run' : 'drill.pass_rate_below_threshold',
            'severity' => $event->total < 1 ? 'critical' : 'warn',
            'title' => $event->total < 1
                ? 'No backup drill evidence available'
                : 'Backup drill pass rate is below threshold',
            'summary' => $event->total < 1
                ? sprintf('No backup drills found in the last %d day(s).', $event->windowDays)
                : sprintf(
                    'Drill pass rate is %.1f%% in the last %d day(s), below the configured %.1f%% threshold.',
                    $event->passRatePercent,
                    $event->windowDays,
                    $event->thresholdPercent,
                ),
            'recommended_commands' => [
                'php artisan checkpoint:enqueue-drill',
                'php artisan checkpoint:status --summary --format=json',
                'php artisan checkpoint:report --format=json',
            ],
            'evidence' => [
                'window_days' => $event->windowDays,
                'passing' => $event->passing,
                'total' => $event->total,
                'pass_rate_percent' => $event->passRatePercent,
                'threshold_percent' => $event->thresholdPercent,
                'latest_run_uuid' => $event->latestRun?->run_uuid,
            ],
        ];
    }
}
