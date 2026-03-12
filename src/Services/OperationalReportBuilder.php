<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillFreshnessAlarmTriggered;
use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillPassRateAlarmTriggered;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFreshnessAlarmTriggered;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\ExecutableFinder;

/** @internal */
final readonly class OperationalReportBuilder
{
    public function __construct(
        private Repository $config,
        private DatabaseManager $database,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function recentRuns(int $limit): array
    {
        return CommandRun::query()
            ->latest('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (CommandRun $run): array => [
                'id' => (int) $run->getKey(),
                'operation' => $run->operation,
                'status' => (string) $run->status->value,
                'exit_code' => $run->exit_code,
                'backup' => $this->backupSummary($run),
                'verification_state' => $run->verification_state,
                'restore_target' => $run->restore_target,
                'restore_audit' => $this->restoreAuditPayload($run),
                'last_known_good_at' => $run->last_known_good_at?->format('Y-m-d H:i:s'),
                'started_at' => $run->started_at?->format('Y-m-d H:i:s'),
                'finished_at' => $run->finished_at?->format('Y-m-d H:i:s'),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $restoreOperations = ['logical_restore_file', 'logical_restore_latest', 'pitr_restore', 'pgbackrest_restore'];
        $drillWindowDays = $this->backupDrillWindowDays();

        $lastKnownGood = CommandRun::query()
            ->whereNotNull('last_known_good_at')
            ->latest('last_known_good_at')
            ->first();
        $latestVerified = CommandRun::query()
            ->where('verification_state', 'verified')
            ->latest('verified_at')
            ->latest('id')
            ->first();
        $latestRestoreFailure = CommandRun::query()
            ->where('status', 'failed')
            ->whereIn('operation', $restoreOperations)
            ->latest('finished_at')
            ->latest('id')
            ->first();
        $latestRestoreRun = CommandRun::query()
            ->whereIn('operation', $restoreOperations)
            ->latest('finished_at')
            ->latest('started_at')
            ->latest('id')
            ->first();
        $latestDrillRun = BackupDrillRun::query()->recent()->first();
        $latestFailedDrillRun = BackupDrillRun::query()->where('overall_result', 'fail')->recent()->first();
        $drillWindowStart = now()->subDays($drillWindowDays);
        $recentDrillCount = BackupDrillRun::query()->where('executed_at', '>=', $drillWindowStart)->count();
        $passingDrillCount = BackupDrillRun::query()->where('executed_at', '>=', $drillWindowStart)->where('overall_result', 'pass')->count();

        $summary = [
            'pending_runs' => CommandRun::query()->pending()->count(),
            'running_runs' => CommandRun::query()->running()->count(),
            'failed_runs_24h' => CommandRun::query()->failed()->where('created_at', '>=', now()->subDay())->count(),
            'last_known_good_backup' => $this->summarySignalPayload($lastKnownGood, 'last_known_good_at'),
            'latest_verified_backup' => $this->summarySignalPayload($latestVerified, 'verified_at'),
            'latest_backup_drill' => $this->drillPayload($latestDrillRun),
            'latest_failed_backup_drill' => $this->drillPayload($latestFailedDrillRun),
            'backup_drill_pass_rate' => $this->drillPassRatePayload($recentDrillCount, $passingDrillCount, $drillWindowDays),
            'latest_restore_run' => $this->restoreRunPayload($latestRestoreRun),
            'latest_restore_failure' => $this->restoreFailurePayload($latestRestoreFailure),
        ];

        // Keep the legacy status/report key as a compatibility alias for automation consumers.
        $summary['backup_drill_pass_rate_30d'] = $summary['backup_drill_pass_rate'];

        return $summary;
    }

    /**
     * @return list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>
     */
    public function healthChecks(): array
    {
        $rows = [
            $this->checkRow('config.driver', 'Config: driver', 'pass', (string) $this->config->get('checkpoint.driver'), [
                'driver' => (string) $this->config->get('checkpoint.driver'),
            ]),
            $this->checkRow('config.queue_name', 'Config: queue.name', 'pass', (string) $this->config->get('checkpoint.queue.name', 'db-ops'), [
                'queue_name' => (string) $this->config->get('checkpoint.queue.name', 'db-ops'),
            ]),
            $this->checkRow('config.log_channel', 'Config: log_channel', 'pass', (string) $this->config->get('checkpoint.log_channel', 'stack'), [
                'log_channel' => (string) $this->config->get('checkpoint.log_channel', 'stack'),
            ]),
            $this->checkRow('config.pgbackrest_stanza', 'Config: pgbackrest.stanza', 'pass', (string) $this->config->get('checkpoint.drivers.pgbackrest.stanza', 'main'), [
                'stanza' => (string) $this->config->get('checkpoint.drivers.pgbackrest.stanza', 'main'),
            ]),
            $this->checkRow('config.pgbackrest_repo', 'Config: pgbackrest.repo', 'pass', (string) $this->config->get('checkpoint.drivers.pgbackrest.repo', 1), [
                'repository' => (int) $this->config->get('checkpoint.drivers.pgbackrest.repo', 1),
            ]),
            $this->checkRow('config.pgbackrest_repositories', 'Config: pgbackrest.repositories', 'pass', (string) count($this->pgBackRestRepositories()), [
                'repository_count' => count($this->pgBackRestRepositories()),
            ]),
            $this->checkRow('config.pgbackrest_process_max', 'Config: pgbackrest.process_max', 'pass', (string) $this->config->get('checkpoint.drivers.pgbackrest.process_max', 1), [
                'process_max' => (int) $this->config->get('checkpoint.drivers.pgbackrest.process_max', 1),
            ]),
            $this->selectedPgBackRestRepositoryRow(),
            $this->selectedPgBackRestTargetRow(),
            $this->selectedPgBackRestTlsRow(),
            $this->selectedPgBackRestEncryptionRow(),
            $this->configuredBinaryRow('pg_dump', 'pg_dump', false),
            $this->configuredBinaryRow(
                'pgBackRest',
                (string) $this->config->get('checkpoint.drivers.pgbackrest.binary', 'pgbackrest'),
                (string) $this->config->get('checkpoint.driver', '') === 'pgbackrest',
            ),
            $this->configuredBinaryRow('gzip', 'gzip', false),
            $this->tableRow('command_runs', (new CommandRun)->getTable()),
            $this->tableRow('backup_drill_runs', (new BackupDrillRun)->getTable()),
            $this->checkRow(
                'queue.worker_visibility',
                'Queue: '.$this->config->get('checkpoint.queue.name', 'db-ops'),
                'warn',
                'Cannot verify queue without running worker',
                ['queue_name' => (string) $this->config->get('checkpoint.queue.name', 'db-ops')]
            ),
        ];

        $orphanedRunsCount = $this->orphanedRunsCount();
        $rows[] = $this->checkRow(
            'queue.orphaned_runs',
            'Orphaned runs',
            $orphanedRunsCount > 0 ? 'warn' : 'pass',
            sprintf('%d pending runs beyond threshold', $orphanedRunsCount),
            [
                'orphaned_run_count' => $orphanedRunsCount,
                'threshold_minutes' => max(1, (int) $this->config->get('checkpoint.queue.orphan_threshold', 10)),
            ],
        );
        $rows[] = $this->lastKnownGoodCheck();
        $rows[] = $this->backupDurationAnomalyCheck();
        $rows[] = $this->backupDrillFreshnessCheck();
        $rows[] = $this->backupDrillPassRateCheck();

        return $rows;
    }

    /**
     * @param  list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>  $checks
     */
    public function healthOk(array $checks): bool
    {
        foreach ($checks as $check) {
            if (in_array($check['status'], ['warn', 'fail'], true)) {
                return false;
            }
        }

        return true;
    }

    private function backupDrillWindowDays(): int
    {
        return max(1, (int) $this->config->get('checkpoint.observability.backup_drill_pass_rate_window_days', 30));
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
     * @return array<string, mixed>
     */
    private function restoreRunPayload(?CommandRun $run): array
    {
        if (! $run instanceof CommandRun) {
            return ['label' => '-', 'timestamp' => null, 'operation' => null, 'status' => null, 'target' => null, 'audit' => null];
        }

        $target = $run->restore_target ?? $run->argument_text;
        $label = sprintf('%s [%s]', $run->operation, (string) $run->status->value);

        if (is_string($target) && $target !== '') {
            $label .= sprintf(' (%s)', $target);
        }

        $audit = $this->restoreAuditPayload($run);
        $confirmation = is_string($audit['confirmation_satisfied_via'] ?? null) ? $audit['confirmation_satisfied_via'] : null;
        $verifiedRunId = $audit['verified_signal_run_id'] ?? null;

        if ($confirmation !== null || is_int($verifiedRunId)) {
            $parts = [];

            if ($confirmation !== null) {
                $parts[] = 'confirm='.$confirmation;
            }

            if (is_int($verifiedRunId)) {
                $parts[] = 'verified_run='.$verifiedRunId;
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
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function restoreAuditPayload(CommandRun $run): ?array
    {
        $metadata = is_array($run->metadata) ? $run->metadata : [];

        return is_array($metadata['restore_audit'] ?? null) ? $metadata['restore_audit'] : null;
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

    private function orphanedRunsCount(): int
    {
        $thresholdMinutes = max(1, (int) $this->config->get('checkpoint.queue.orphan_threshold', 10));

        return CommandRun::query()
            ->pending()
            ->where('updated_at', '<', now()->subMinutes($thresholdMinutes))
            ->count();
    }

    /**
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function lastKnownGoodCheck(): array
    {
        $maxAgeHours = max(1, (int) $this->config->get('checkpoint.observability.max_last_known_good_age_hours', 24));
        $latest = CommandRun::query()
            ->whereNotNull('last_known_good_at')
            ->latest('last_known_good_at')
            ->first();

        if (! $latest instanceof CommandRun || $latest->last_known_good_at === null) {
            event(new BackupFreshnessAlarmTriggered(null, 'missing', null, $maxAgeHours));

            return $this->checkRow('backup.last_known_good', 'Backups: last known good', 'warn', 'No last-known-good backup recorded', [
                'age_hours' => null,
                'threshold_hours' => $maxAgeHours,
                'reason' => 'missing',
            ]);
        }

        $ageHours = max(0, (int) ceil($latest->last_known_good_at->diffInMinutes(now()) / 60));
        $isStale = $latest->last_known_good_at->lt(now()->subHours($maxAgeHours));

        if ($isStale) {
            event(new BackupFreshnessAlarmTriggered($latest, 'stale', $ageHours, $maxAgeHours));
        }

        return $this->checkRow(
            'backup.last_known_good',
            'Backups: last known good',
            $isStale ? 'warn' : 'pass',
            sprintf('%d hours old (threshold: %d)', $ageHours, $maxAgeHours),
            [
                'age_hours' => $ageHours,
                'threshold_hours' => $maxAgeHours,
                'reason' => $isStale ? 'stale' : 'fresh',
            ],
        );
    }

    /**
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function backupDurationAnomalyCheck(): array
    {
        $minSamples = max(2, (int) $this->config->get('checkpoint.observability.backup_duration_min_samples', 3));
        $factor = max(1.1, (float) $this->config->get('checkpoint.observability.backup_duration_anomaly_factor', 2.0));
        $runs = CommandRun::query()
            ->whereNotNull('backup_type')
            ->whereNotNull('duration_seconds')
            ->where('status', 'succeeded')
            ->latest('id')
            ->limit($minSamples)
            ->get();

        if ($runs->count() < $minSamples) {
            return $this->checkRow('backup.duration_anomaly', 'Backups: duration anomaly', 'warn', 'Not enough successful backup samples', [
                'factor' => $factor,
                'min_samples' => $minSamples,
                'sample_count' => $runs->count(),
                'reason' => 'insufficient_samples',
            ]);
        }

        $latest = $runs->first();
        $baseline = $runs->slice(1)->pluck('duration_seconds')->filter()->sort()->values();

        if (! $latest instanceof CommandRun || $baseline->isEmpty()) {
            return $this->checkRow('backup.duration_anomaly', 'Backups: duration anomaly', 'warn', 'Not enough successful backup samples', [
                'factor' => $factor,
                'min_samples' => $minSamples,
                'sample_count' => $runs->count(),
                'reason' => 'insufficient_samples',
            ]);
        }

        $medianSeconds = (int) $baseline->get((int) floor(($baseline->count() - 1) / 2));
        $latestSeconds = (int) $latest->duration_seconds;

        $isAnomalous = $latestSeconds > (int) ceil($medianSeconds * $factor);

        return $this->checkRow(
            'backup.duration_anomaly',
            'Backups: duration anomaly',
            $isAnomalous ? 'warn' : 'pass',
            sprintf('latest %ds vs median %ds (factor: %.1f)', $latestSeconds, $medianSeconds, $factor),
            [
                'latest_seconds' => $latestSeconds,
                'median_seconds' => $medianSeconds,
                'factor' => $factor,
                'min_samples' => $minSamples,
                'sample_count' => $runs->count(),
                'reason' => $isAnomalous ? 'anomalous' : 'normal',
            ],
        );
    }

    /**
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function backupDrillFreshnessCheck(): array
    {
        $thresholdDays = max(1, (int) $this->config->get('checkpoint.observability.max_backup_drill_age_days', 30));
        $latest = BackupDrillRun::query()->recent()->first();

        if (! $latest instanceof BackupDrillRun) {
            event(new BackupDrillFreshnessAlarmTriggered(null, 'missing', null, $thresholdDays));

            return $this->checkRow('backup_drill.latest_run', 'Backup drills: latest run', 'warn', 'No backup drill recorded', [
                'run_uuid' => null,
                'overall_result' => null,
                'age_days' => null,
                'threshold_days' => $thresholdDays,
                'reason' => 'missing',
            ]);
        }

        $ageDays = max(0, (int) ceil($latest->executed_at->diffInHours(now()) / 24));
        $isStale = $latest->executed_at->lt(now()->subDays($thresholdDays));

        if ($isStale) {
            event(new BackupDrillFreshnessAlarmTriggered($latest, 'stale', $ageDays, $thresholdDays));
        }

        return $this->checkRow(
            'backup_drill.latest_run',
            'Backup drills: latest run',
            $isStale ? 'warn' : 'pass',
            sprintf('%s %d days old (%s)', strtoupper((string) $latest->overall_result), $ageDays, $latest->run_uuid),
            [
                'run_uuid' => $latest->run_uuid,
                'overall_result' => $latest->overall_result,
                'age_days' => $ageDays,
                'threshold_days' => $thresholdDays,
                'reason' => $isStale ? 'stale' : 'fresh',
            ],
        );
    }

    /**
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function backupDrillPassRateCheck(): array
    {
        $windowDays = $this->backupDrillWindowDays();
        $thresholdPercent = max(0.0, min(100.0, (float) $this->config->get('checkpoint.observability.backup_drill_min_pass_rate', 100.0)));
        $windowStart = now()->subDays($windowDays);
        $total = BackupDrillRun::query()
            ->where('executed_at', '>=', $windowStart)
            ->count();
        $latest = BackupDrillRun::query()->recent()->first();

        if ($total < 1) {
            event(new BackupDrillPassRateAlarmTriggered($windowDays, 0, 0, 0.0, $thresholdPercent, $latest));

            return $this->checkRow('backup_drill.pass_rate', 'Backup drills: pass rate', 'warn', sprintf('No backup drills in the last %d days', $windowDays), [
                'window_days' => $windowDays,
                'total' => 0,
                'passing' => 0,
                'pass_rate_percent' => 0.0,
                'threshold_percent' => $thresholdPercent,
                'latest_run_uuid' => $latest?->run_uuid,
                'reason' => 'missing',
            ]);
        }

        $passing = BackupDrillRun::query()
            ->where('executed_at', '>=', $windowStart)
            ->where('overall_result', 'pass')
            ->count();
        $percent = round(($passing / $total) * 100, 1);
        $isBelowThreshold = $percent < $thresholdPercent;

        if ($isBelowThreshold) {
            event(new BackupDrillPassRateAlarmTriggered($windowDays, $passing, $total, $percent, $thresholdPercent, $latest));
        }

        return $this->checkRow(
            'backup_drill.pass_rate',
            'Backup drills: pass rate',
            $isBelowThreshold ? 'warn' : 'pass',
            sprintf('%d/%d passed in the last %d days (%.1f%%, threshold: %.1f%%)', $passing, $total, $windowDays, $percent, $thresholdPercent),
            [
                'window_days' => $windowDays,
                'total' => $total,
                'passing' => $passing,
                'pass_rate_percent' => $percent,
                'threshold_percent' => $thresholdPercent,
                'latest_run_uuid' => $latest?->run_uuid,
                'reason' => $isBelowThreshold ? 'below_threshold' : 'healthy',
            ],
        );
    }

    /**
     * @return array<int|string, mixed>
     */
    private function pgBackRestRepositories(): array
    {
        $repositories = $this->config->get('checkpoint.drivers.pgbackrest.repositories', []);

        return is_array($repositories) ? $repositories : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function selectedPgBackRestRepository(): array
    {
        $repoId = (int) $this->config->get('checkpoint.drivers.pgbackrest.repo', 1);
        $repositories = $this->pgBackRestRepositories();
        $repository = $repositories[$repoId] ?? [];

        return is_array($repository) ? $repository : [];
    }

    /**
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function selectedPgBackRestRepositoryRow(): array
    {
        $repoId = (int) $this->config->get('checkpoint.drivers.pgbackrest.repo', 1);
        $type = (string) ($this->selectedPgBackRestRepository()['type'] ?? 'unknown');

        return $this->checkRow('repo.pgbackrest_active', 'Repo: pgbackrest.active', 'pass', sprintf('repo%d (%s)', $repoId, $type), [
            'repository' => $repoId,
            'type' => $type,
        ]);
    }

    /**
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function selectedPgBackRestTargetRow(): array
    {
        $repository = $this->selectedPgBackRestRepository();
        $type = (string) ($repository['type'] ?? 'unknown');

        if ($type === 's3') {
            $s3 = is_array($repository['s3'] ?? null) ? $repository['s3'] : [];

            return $this->checkRow('repo.pgbackrest_target', 'Repo: pgbackrest.target', 'pass', sprintf('s3://%s via %s', (string) ($s3['bucket'] ?? '-'), (string) ($s3['endpoint'] ?? '-')), [
                'type' => 's3',
                'bucket' => (string) ($s3['bucket'] ?? '-'),
                'endpoint' => (string) ($s3['endpoint'] ?? '-'),
            ]);
        }

        return $this->checkRow('repo.pgbackrest_target', 'Repo: pgbackrest.target', 'pass', (string) ($repository['path'] ?? '-'), [
            'type' => $type,
            'path' => (string) ($repository['path'] ?? '-'),
        ]);
    }

    /**
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function selectedPgBackRestTlsRow(): array
    {
        $tls = $this->selectedPgBackRestRepository()['tls'] ?? [];
        $tls = is_array($tls) ? $tls : [];
        $verify = (bool) ($tls['verify'] ?? true);
        $caFile = $tls['ca_file'] ?? null;
        $notes = $verify ? 'verify enabled' : 'verify disabled';

        if (is_string($caFile) && trim($caFile) !== '') {
            $notes .= sprintf(' (ca: %s)', $caFile);
        }

        return $this->checkRow('repo.pgbackrest_tls', 'Repo: pgbackrest.tls', $verify ? 'pass' : 'warn', $notes, [
            'verify' => $verify,
            'ca_file' => is_string($caFile) && trim($caFile) !== '' ? $caFile : null,
        ]);
    }

    /**
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function selectedPgBackRestEncryptionRow(): array
    {
        $encryption = $this->selectedPgBackRestRepository()['encryption'] ?? [];
        $encryption = is_array($encryption) ? $encryption : [];
        $enabled = (bool) ($encryption['enabled'] ?? false);
        $cipherType = (string) ($encryption['cipher_type'] ?? 'unknown');

        return $this->checkRow('repo.pgbackrest_encryption', 'Repo: pgbackrest.encryption', $enabled ? 'pass' : 'warn', $enabled ? sprintf('enabled (%s)', $cipherType) : 'disabled', [
            'enabled' => $enabled,
            'cipher_type' => $cipherType,
        ]);
    }

    /**
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function configuredBinaryRow(string $label, string $binary, bool $required): array
    {
        $trimmedBinary = trim($binary);

        if ($trimmedBinary === '') {
            return $this->checkRow('binary.'.strtolower($label), 'Binary: '.$label, $required ? 'fail' : 'warn', 'Binary is empty', [
                'binary' => $trimmedBinary,
                'required' => $required,
                'found' => false,
                'path' => null,
                'reason' => 'empty',
            ]);
        }

        $path = (new ExecutableFinder)->find($trimmedBinary);

        if ($path === null) {
            return $this->checkRow('binary.'.strtolower($label), 'Binary: '.$label, $required ? 'fail' : 'warn', sprintf('%s not found on PATH', $trimmedBinary), [
                'binary' => $trimmedBinary,
                'required' => $required,
                'found' => false,
                'path' => null,
                'reason' => 'not_found',
            ]);
        }

        return $this->checkRow('binary.'.strtolower($label), 'Binary: '.$label, 'pass', $path, [
            'binary' => $trimmedBinary,
            'required' => $required,
            'found' => true,
            'path' => $path,
            'reason' => 'found',
        ]);
    }

    /**
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function tableRow(string $label, string $table): array
    {
        $connection = $this->database->connection();

        if (! $connection->getSchemaBuilder()->hasTable($table)) {
            return $this->checkRow('db.'.$label.'_table', 'DB: '.$label.' table', 'fail', 'Table not found', [
                'table' => $table,
                'exists' => false,
                'row_count' => null,
            ]);
        }

        try {
            $count = $connection->table($table)->count();
        } catch (QueryException) {
            $count = 0;
        }

        return $this->checkRow('db.'.$label.'_table', 'DB: '.$label.' table', 'pass', sprintf('%d rows', $count), [
            'table' => $table,
            'exists' => true,
            'row_count' => $count,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function checkRow(string $code, string $check, string $status, string $notes, array $data = []): array
    {
        return [
            'code' => $code,
            'check' => $check,
            'status' => $status,
            'notes' => $notes,
            'data' => $data,
        ];
    }
}
