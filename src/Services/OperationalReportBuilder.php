<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Actions\BuildDrillRemediationPlaybookAction;
use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Models\VerificationRun;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;

/** @internal */
final readonly class OperationalReportBuilder
{
    public function __construct(
        private Repository $config,
        private DatabaseManager $database,
        private BuildDrillRemediationPlaybookAction $buildDrillRemediationPlaybook,
        private HealthCheckComposer $healthCheckComposer,
        private BreakdownAggregator $breakdownAggregator,
        private DrillTrendAnalyzer $drillTrendAnalyzer,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function recentRuns(int $limit): array
    {
        /** @var list<array<string, mixed>> $runs */
        $runs = CommandRun::query()
            ->select([
                'id',
                'operation',
                'status',
                'exit_code',
                'backup_type',
                'backup_label',
                'stanza',
                'repository',
                'verification_state',
                'restore_target',
                'argument_text',
                'restore_confirmation_satisfied_via',
                'restore_verified_signal_run_id',
                'restore_post_verification_result',
                'last_known_good_at',
                'started_at',
                'finished_at',
                'metadata',
            ])
            ->latest('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(function (CommandRun $run): array {
                $payload = [
                    'id' => (int) $run->getKey(),
                    'operation' => $run->operation,
                    'status' => (string) $run->status->value,
                    'exit_code' => $run->exit_code,
                    'backup' => $this->backupSummary($run),
                    'verification_state' => $run->verification_state,
                    'restore_target' => $run->restore_target,
                    'restore_audit' => $this->restoreAuditPayload($run),
                    'post_restore_verification' => $this->postRestoreVerificationPayload($run),
                    'last_known_good_at' => $run->last_known_good_at?->format('Y-m-d H:i:s'),
                    'started_at' => $run->started_at?->format('Y-m-d H:i:s'),
                    'finished_at' => $run->finished_at?->format('Y-m-d H:i:s'),
                ];

                $replication = $this->replicationPayload($run);

                if ($replication !== null) {
                    $payload['replication'] = $replication;
                }

                return $payload;
            })
            ->values()
            ->all();

        return $runs;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return $this->summaryFromSnapshot($this->summarySnapshot());
    }

    /**
     * @return array{recent_runs:list<array<string, mixed>>,summary:array<string, mixed>,breakdown:array<string,mixed>,verification:array<string,mixed>,health:array{ok:bool,checks:list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>}}
     */
    public function reportPayload(int $limit): array
    {
        $snapshot = $this->summarySnapshot();
        $drillTrend = $this->drillTrendAnalyzer->drillTrendPayload($snapshot['drill_window_days']);
        $verificationSummary = $this->verificationSummary();
        $checks = $this->healthCheckComposer->healthChecksFromSnapshot($snapshot, $drillTrend, $verificationSummary);

        return [
            'recent_runs' => $this->recentRuns($limit),
            'summary' => $this->summaryFromSnapshot($snapshot),
            'breakdown' => $this->breakdownAggregator->breakdown(),
            'verification' => $verificationSummary,
            'health' => [
                'ok' => $this->healthCheckComposer->healthOk($checks),
                'checks' => $checks,
            ],
        ];
    }

    /**
     * @return list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>
     */
    public function healthChecks(): array
    {
        $snapshot = $this->summarySnapshot();
        $drillTrend = $this->drillTrendAnalyzer->drillTrendPayload($snapshot['drill_window_days']);

        return $this->healthCheckComposer->healthChecksFromSnapshot($snapshot, $drillTrend, $this->verificationSummary());
    }

    /**
     * @param  list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>  $checks
     */
    public function healthOk(array $checks): bool
    {
        return $this->healthCheckComposer->healthOk($checks);
    }

    /**
     * @param  array{command_run_counts:array{pending_runs:int,running_runs:int,failed_runs_24h:int},drill_window_days:int,drill_summary:array{latest:?BackupDrillRun,latest_failed:?BackupDrillRun,total:int,passing:int},last_known_good:?CommandRun,latest_verified:?CommandRun,latest_restore_failure:?CommandRun,latest_restore_run:?CommandRun,latest_failed_run:?CommandRun}  $snapshot
     * @return array<string, mixed>
     */
    private function summaryFromSnapshot(array $snapshot): array
    {
        $commandRunCounts = $snapshot['command_run_counts'];
        $drillWindowDays = $snapshot['drill_window_days'];
        $drillSummary = $snapshot['drill_summary'];
        $drillTrend = $this->drillTrendAnalyzer->drillTrendPayload($drillWindowDays);

        $summary = [
            'pending_runs' => $commandRunCounts['pending_runs'],
            'running_runs' => $commandRunCounts['running_runs'],
            'failed_runs_24h' => $commandRunCounts['failed_runs_24h'],
            'latest_failed_run' => $this->latestFailedRunPayload($snapshot['latest_failed_run']),
            'last_known_good_backup' => $this->summarySignalPayload($snapshot['last_known_good'], 'last_known_good_at'),
            'latest_verified_backup' => $this->summarySignalPayload($snapshot['latest_verified'], 'verified_at'),
            'latest_backup_drill' => $this->drillPayload($drillSummary['latest']),
            'latest_failed_backup_drill' => $this->drillPayload($drillSummary['latest_failed']),
            'backup_drill_pass_rate' => $this->drillPassRatePayload($drillSummary['total'], $drillSummary['passing'], $drillWindowDays),
            'backup_drill_trend' => $drillTrend,
            'backup_drill_remediation_playbook' => $this->backupDrillRemediationPlaybook($drillSummary, $drillWindowDays, $drillTrend),
            'latest_restore_run' => $this->restoreRunPayload($snapshot['latest_restore_run']),
            'latest_restore_failure' => $this->restoreFailurePayload($snapshot['latest_restore_failure']),
        ];

        $summary['backup_drill_pass_rate_30d'] = $summary['backup_drill_pass_rate'];

        return $summary;
    }

    /**
     * @return array{pending_runs:int,running_runs:int,failed_runs_24h:int}
     */
    private function commandRunSummaryCounts(): array
    {
        return [
            'pending_runs' => CommandRun::query()->pending()->count(),
            'running_runs' => CommandRun::query()->running()->count(),
            'failed_runs_24h' => CommandRun::query()->failed()->where('created_at', '>=', now()->subDay())->count(),
        ];
    }

    /**
     * @return array{command_run_counts:array{pending_runs:int,running_runs:int,failed_runs_24h:int},drill_window_days:int,drill_summary:array{latest:?BackupDrillRun,latest_failed:?BackupDrillRun,total:int,passing:int},last_known_good:?CommandRun,latest_verified:?CommandRun,latest_restore_failure:?CommandRun,latest_restore_run:?CommandRun,latest_failed_run:?CommandRun}
     */
    private function summarySnapshot(): array
    {
        $restoreOperations = ['logical_restore_file', 'logical_restore_latest', 'pitr_restore', 'pgbackrest_restore'];
        $drillWindowDays = $this->backupDrillWindowDays();

        return [
            'command_run_counts' => $this->commandRunSummaryCounts(),
            'drill_window_days' => $drillWindowDays,
            'drill_summary' => $this->drillTrendAnalyzer->backupDrillSummary(now()->subDays($drillWindowDays)),
            'last_known_good' => CommandRun::query()
                ->select($this->summarySignalColumns())
                ->whereNotNull('last_known_good_at')
                ->latest('last_known_good_at')
                ->first(),
            'latest_verified' => CommandRun::query()
                ->select($this->summarySignalColumns())
                ->where('verification_state', 'verified')
                ->latest('verified_at')
                ->latest('id')
                ->first(),
            'latest_restore_failure' => CommandRun::query()
                ->select($this->restoreSummaryColumns())
                ->where('status', 'failed')
                ->whereIn('operation', $restoreOperations)
                ->latest('finished_at')
                ->latest('id')
                ->first(),
            'latest_restore_run' => CommandRun::query()
                ->select($this->restoreSummaryColumns())
                ->whereIn('operation', $restoreOperations)
                ->latest('finished_at')
                ->latest('started_at')
                ->latest('id')
                ->first(),
            'latest_failed_run' => CommandRun::query()
                ->select($this->failedRunSummaryColumns())
                ->where('status', CommandRunStatus::Failed)
                ->latest('finished_at')
                ->latest('id')
                ->first(),
        ];
    }

    private function backupDrillWindowDays(): int
    {
        return max(1, (int) $this->config->get('checkpoint.observability.backup_drill_pass_rate_window_days', 30));
    }

    /**
     * @param  array{latest:?BackupDrillRun,latest_failed:?BackupDrillRun,total:int,passing:int}  $summary
     * @param  array<string,mixed>  $trend
     * @return array{signature:string,severity:'info'|'warn'|'critical',title:string,summary:string,recommended_commands:list<string>,steps:list<string>,evidence:array<string,mixed>}
     */
    private function backupDrillRemediationPlaybook(array $summary, int $windowDays, array $trend): array
    {
        $minPassRate = max(0.0, min(100.0, (float) $this->config->get('checkpoint.observability.backup_drill_min_pass_rate', 100.0)));
        $maxAgeDays = max(1, (int) $this->config->get('checkpoint.observability.max_backup_drill_age_days', 30));

        return $this->buildDrillRemediationPlaybook->execute(
            latestRun: $summary['latest'],
            windowDays: $windowDays,
            total: $summary['total'],
            passing: $summary['passing'],
            minimumPassRatePercent: $minPassRate,
            maxAgeDays: $maxAgeDays,
            trend: $trend,
        );
    }

    private function backupSummary(CommandRun $run): string
    {
        $parts = array_filter([$run->backup_type, $run->backup_label]);

        return $parts === [] ? '-' : implode(':', $parts);
    }

    /**
     * @return array{label:string,timestamp:string|null,operation:string|null}
     */
    private function summarySignalPayload(?CommandRun $run, string $timestampField): array
    {
        if (! $run instanceof CommandRun) {
            return ['label' => '-', 'timestamp' => null, 'operation' => null];
        }

        /** @var Carbon|null $timestamp */
        $timestamp = $run->{$timestampField};
        $summary = $this->backupSummary($run);

        if ($summary === '-') {
            $summary = $run->operation;
        }

        return [
            'label' => $timestamp instanceof Carbon ? sprintf('%s at %s', $summary, $timestamp->format('Y-m-d H:i:s')) : $summary,
            'timestamp' => $timestamp?->format('Y-m-d H:i:s'),
            'operation' => $run->operation,
        ];
    }

    /**
     * @return array{label:string,timestamp:string|null,operation:string|null,status:string|null,exit_code:int|null,failure_reason:string|null,next_action:string|null}
     */
    private function latestFailedRunPayload(?CommandRun $run): array
    {
        if (! $run instanceof CommandRun) {
            return [
                'label' => '-',
                'timestamp' => null,
                'operation' => null,
                'status' => null,
                'exit_code' => null,
                'failure_reason' => null,
                'next_action' => null,
            ];
        }

        $timestamp = $run->finished_at ?? $run->started_at;
        $reason = $this->resolveFailureReason($run);
        $nextAction = $this->nextActionForFailure($run, $reason);

        return [
            'label' => sprintf(
                '%s [failed] (exit: %s)%s',
                $run->operation,
                $run->exit_code !== null ? (string) $run->exit_code : '-',
                $timestamp instanceof Carbon ? ' at '.$timestamp->format('Y-m-d H:i:s') : '',
            ),
            'timestamp' => $timestamp?->format('Y-m-d H:i:s'),
            'operation' => $run->operation,
            'status' => (string) $run->status->value,
            'exit_code' => $run->exit_code,
            'failure_reason' => $reason,
            'next_action' => $nextAction,
        ];
    }

    /**
     * @return array{label:string,timestamp:string|null,operation:string|null,target:string|null}
     */
    private function restoreFailurePayload(?CommandRun $run): array
    {
        if (! $run instanceof CommandRun) {
            return ['label' => '-', 'timestamp' => null, 'operation' => null, 'target' => null];
        }

        $target = $run->restore_target ?? $run->argument_text;
        $label = $run->operation;

        if (is_string($target) && $target !== '') {
            $label .= sprintf(' (%s)', $target);
        }

        if ($run->finished_at instanceof Carbon) {
            $label .= sprintf(' at %s', $run->finished_at->format('Y-m-d H:i:s'));
        }

        return [
            'label' => $label,
            'timestamp' => $run->finished_at?->format('Y-m-d H:i:s'),
            'operation' => $run->operation,
            'target' => $target,
        ];
    }

    /**
     * @return list<string>
     */
    private function failedRunSummaryColumns(): array
    {
        return [
            'id',
            'operation',
            'status',
            'exit_code',
            'command_output',
            'started_at',
            'finished_at',
            'metadata',
        ];
    }

    private function resolveFailureReason(CommandRun $run): ?string
    {
        if (is_string($run->command_output) && trim($run->command_output) !== '') {
            $line = trim(strtok($run->command_output, "\n") ?: '');

            if ($line !== '') {
                return mb_substr($line, 0, 240);
            }
        }

        if ($run->exit_code !== null) {
            return sprintf('Command exited with code %d.', $run->exit_code);
        }

        return null;
    }

    private function nextActionForFailure(CommandRun $run, ?string $reason): string
    {
        if (
            $run->operation === 'logical_backup'
            && is_string($reason)
            && str_contains($reason, 'No shell command configured')
        ) {
            return 'Set DB_OPS_CMD_LOGICAL_BACKUP, then run php artisan checkpoint:enqueue-backup.';
        }

        return 'Run php artisan checkpoint:report --limit=10 --format=json for full failure context.';
    }

    /**
     * @return array<string, mixed>
     */
    private function restoreRunPayload(?CommandRun $run): array
    {
        if (! $run instanceof CommandRun) {
            return [
                'label' => '-',
                'timestamp' => null,
                'operation' => null,
                'status' => null,
                'target' => null,
                'audit' => null,
                'post_restore_verification' => null,
            ];
        }

        $target = $run->restore_target ?? $run->argument_text;
        $label = sprintf('%s [%s]', $run->operation, (string) $run->status->value);

        if (is_string($target) && $target !== '') {
            $label .= sprintf(' (%s)', $target);
        }

        $audit = $this->restoreAuditPayload($run);
        $postRestoreVerification = $this->postRestoreVerificationPayload($run);
        $summary = $run->restoreAuditSummary();
        $postVerificationSummary = $run->restorePostVerificationSummary();
        $confirmation = $summary['confirmation_satisfied_via'];
        $verifiedRunId = $summary['verified_signal_run_id'];
        $postVerificationResult = $postVerificationSummary['aggregate_result'];

        if ($confirmation !== null || is_int($verifiedRunId) || is_string($postVerificationResult)) {
            $parts = [];

            if ($confirmation !== null) {
                $parts[] = 'confirm='.$confirmation;
            }

            if (is_int($verifiedRunId)) {
                $parts[] = 'verified_run='.$verifiedRunId;
            }

            if (is_string($postVerificationResult) && $postVerificationResult !== '') {
                $parts[] = 'post_verify='.$postVerificationResult;
            }

            $label .= ' {'.implode(', ', $parts).'}';
        }

        $timestamp = $run->finished_at ?? $run->started_at;

        if ($timestamp instanceof Carbon) {
            $label .= sprintf(' at %s', $timestamp->format('Y-m-d H:i:s'));
        }

        return [
            'label' => $label,
            'timestamp' => $timestamp?->format('Y-m-d H:i:s'),
            'operation' => $run->operation,
            'status' => (string) $run->status->value,
            'target' => $target,
            'audit' => $audit,
            'blast_radius' => is_array($audit['blast_radius'] ?? null) ? $audit['blast_radius'] : null,
            'post_restore_verification' => $postRestoreVerification,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function restoreAuditPayload(CommandRun $run): ?array
    {
        $metadata = is_array($run->metadata) ? $run->metadata : [];
        $restoreAudit = is_array($metadata['restore_audit'] ?? null) ? $metadata['restore_audit'] : [];
        $summary = $run->restoreAuditSummary();

        if ($summary['confirmation_satisfied_via'] !== null) {
            $restoreAudit['confirmation_satisfied_via'] = $summary['confirmation_satisfied_via'];
        }

        if ($summary['verified_signal_run_id'] !== null) {
            $restoreAudit['verified_signal_run_id'] = $summary['verified_signal_run_id'];
        }

        $postVerificationSummary = $run->restorePostVerificationSummary();

        if ($postVerificationSummary['aggregate_result'] !== null) {
            $postVerification = is_array($restoreAudit['post_restore_verification'] ?? null)
                ? $restoreAudit['post_restore_verification']
                : [];
            $postVerification['aggregate_result'] = $postVerificationSummary['aggregate_result'];
            $restoreAudit['post_restore_verification'] = $postVerification;
        }

        return $restoreAudit !== [] ? $restoreAudit : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function postRestoreVerificationPayload(CommandRun $run): ?array
    {
        $metadata = is_array($run->metadata) ? $run->metadata : [];
        $restoreAudit = is_array($metadata['restore_audit'] ?? null) ? $metadata['restore_audit'] : [];
        $postVerification = is_array($restoreAudit['post_restore_verification'] ?? null)
            ? $restoreAudit['post_restore_verification']
            : [];
        $summary = $run->restorePostVerificationSummary();

        if ($summary['aggregate_result'] !== null) {
            $postVerification['aggregate_result'] = $summary['aggregate_result'];
        }

        return $postVerification !== [] ? $postVerification : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function replicationPayload(CommandRun $run): ?array
    {
        $metadata = is_array($run->metadata) ? $run->metadata : [];
        $replication = is_array($metadata['replication'] ?? null) ? $metadata['replication'] : null;

        if ($replication === null) {
            return null;
        }

        return [
            'engine' => $replication['engine'] ?? null,
            'source' => is_array($replication['source'] ?? null) ? $replication['source'] : null,
            'destination' => is_array($replication['destination'] ?? null) ? $replication['destination'] : null,
            'queue_only' => $replication['queue_only'] ?? null,
            'dry_run_requested' => $replication['dry_run_requested'] ?? null,
            'apply_requested' => $replication['apply_requested'] ?? null,
            'force_requested' => $replication['force_requested'] ?? null,
            'overwrite_destination' => $replication['overwrite_destination'] ?? null,
            'governance_preflight' => is_array($replication['governance_preflight'] ?? null) ? $replication['governance_preflight'] : null,
            'result' => $replication['result'] ?? null,
            'sanity' => is_array($replication['sanity'] ?? null) ? $replication['sanity'] : null,
            'failure_analysis' => is_array($replication['failure_analysis'] ?? null) ? $replication['failure_analysis'] : null,
            'failure_context' => is_array($replication['failure_context'] ?? null) ? $replication['failure_context'] : null,
        ];
    }

    /**
     * @return list<string>
     */
    private function summarySignalColumns(): array
    {
        return [
            'id',
            'operation',
            'backup_type',
            'backup_label',
            'verified_at',
            'last_known_good_at',
        ];
    }

    /**
     * @return list<string>
     */
    private function restoreSummaryColumns(): array
    {
        return [
            'id',
            'operation',
            'status',
            'argument_text',
            'restore_target',
            'restore_confirmation_satisfied_via',
            'restore_verified_signal_run_id',
            'restore_post_verification_result',
            'started_at',
            'finished_at',
            'metadata',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function drillPayload(?BackupDrillRun $run): array
    {
        if (! $run instanceof BackupDrillRun) {
            return ['label' => '-', 'timestamp' => null, 'run_uuid' => null, 'overall_result' => null, 'executed_by' => null];
        }

        $label = sprintf('%s [%s]', $run->run_uuid, strtoupper((string) $run->overall_result));

        if (is_string($run->executed_by) && $run->executed_by !== '') {
            $label .= sprintf(' by %s', $run->executed_by);
        }

        $label .= sprintf(' at %s', $run->executed_at->format('Y-m-d H:i:s'));

        return [
            'label' => $label,
            'timestamp' => $run->executed_at->format('Y-m-d H:i:s'),
            'run_uuid' => $run->run_uuid,
            'overall_result' => $run->overall_result,
            'executed_by' => $run->executed_by,
        ];
    }

    /**
     * @return array{label:string,window_days:int,total:int,passing:int,pass_rate_percent:float|null}
     */
    private function drillPassRatePayload(int $total, int $passing, int $windowDays): array
    {
        if ($total < 1) {
            return ['label' => '-', 'window_days' => $windowDays, 'total' => 0, 'passing' => 0, 'pass_rate_percent' => null];
        }

        $percent = round(($passing / $total) * 100, 1);

        return [
            'label' => sprintf('%d/%d (%.1f%%)', $passing, $total, $percent),
            'window_days' => $windowDays,
            'total' => $total,
            'passing' => $passing,
            'pass_rate_percent' => $percent,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function verificationSummary(): array
    {
        $total = VerificationRun::query()->count();
        $verified = VerificationRun::query()->where('status', 'verified')->count();
        $failed = VerificationRun::query()->where('status', 'failed')->count();
        $latest = VerificationRun::query()->latest('verified_at')->latest('id')->first();

        if (! $latest instanceof VerificationRun) {
            return [
                'total_runs' => 0,
                'verified_runs' => 0,
                'failed_runs' => 0,
                'success_rate_percent' => null,
                'health_status' => 'warn',
                'latest' => [
                    'id' => null,
                    'command_run_id' => null,
                    'verification_type' => null,
                    'status' => null,
                    'verified_at' => null,
                ],
            ];
        }

        return [
            'total_runs' => $total,
            'verified_runs' => $verified,
            'failed_runs' => $failed,
            'success_rate_percent' => $total > 0 ? round(($verified / $total) * 100, 1) : null,
            'health_status' => $failed > 0 ? 'warn' : 'pass',
            'latest' => [
                'id' => (int) $latest->getKey(),
                'command_run_id' => (int) $latest->command_run_id,
                'verification_type' => $latest->verification_type,
                'status' => $latest->status,
                'verified_at' => $latest->verified_at?->format('Y-m-d H:i:s'),
            ],
        ];
    }
}
