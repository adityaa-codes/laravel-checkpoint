<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

final class StatusCommand extends Command
{
    protected $signature = 'db-ops:status {--limit=10} {--summary : Show an operator-facing summary instead of recent runs.} {--format=table : Output format: table or json.}';

    protected $description = 'Show recent checkpoint command runs.';

    public function handle(): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['table', 'json'], true)) {
            $this->error('The --format option must be table or json.');

            return self::FAILURE;
        }

        if ((bool) $this->option('summary')) {
            $this->renderSummary($format);

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));

        $runs = CommandRun::query()
            ->latest('id')
            ->limit($limit)
            ->get();

        if ($format === 'json') {
            $this->line(json_encode([
                'mode' => 'runs',
                'limit' => $limit,
                'runs' => $runs->map(fn (CommandRun $run): array => $this->runPayload($run))->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->table([
            'ID', 'Operation', 'Status', 'Exit', 'Backup', 'Verify', 'Last Good', 'Started', 'Finished',
        ], $runs->map(fn (CommandRun $run): array => [
            (string) $run->getKey(),
            $run->operation,
            $this->coloredStatus((string) $run->status->value),
            $run->exit_code !== null ? (string) $run->exit_code : '-',
            $this->backupSummary($run),
            $run->verification_state ?? '-',
            $run->last_known_good_at?->format('Y-m-d H:i:s') ?? '-',
            $run->started_at?->format('Y-m-d H:i:s') ?? '-',
            $run->finished_at?->format('Y-m-d H:i:s') ?? '-',
        ])->all());

        return self::SUCCESS;
    }

    private function renderSummary(string $format): void
    {
        $restoreOperations = ['logical_restore_file', 'logical_restore_latest', 'pitr_restore', 'pgbackrest_restore'];

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

        $summary = [
            'pending_runs' => CommandRun::query()->pending()->count(),
            'running_runs' => CommandRun::query()->running()->count(),
            'failed_runs_24h' => CommandRun::query()->failed()->where('created_at', '>=', now()->subDay())->count(),
            'last_known_good_backup' => $this->summarySignalPayload($lastKnownGood, 'last_known_good_at'),
            'latest_verified_backup' => $this->summarySignalPayload($latestVerified, 'verified_at'),
            'latest_restore_run' => $this->restoreRunPayload($latestRestoreRun),
            'latest_restore_failure' => $this->restoreFailurePayload($latestRestoreFailure),
        ];

        if ($format === 'json') {
            $this->line(json_encode([
                'mode' => 'summary',
                'summary' => $summary,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return;
        }

        $this->table(['Signal', 'Value'], [
            ['Pending runs', (string) $summary['pending_runs']],
            ['Running runs', (string) $summary['running_runs']],
            ['Failed runs (24h)', (string) $summary['failed_runs_24h']],
            ['Last known good backup', $this->summaryRunValue($lastKnownGood, 'last_known_good_at')],
            ['Latest verified backup', $this->summaryRunValue($latestVerified, 'verified_at')],
            ['Latest restore run', $this->restoreRunSummary($latestRestoreRun)],
            ['Latest restore failure', $this->restoreFailureSummary($latestRestoreFailure)],
        ]);
    }

    private function coloredStatus(string $status): string
    {
        $label = $this->statusLabel($status);

        return match ($status) {
            'pending' => sprintf('<comment>%s</comment>', $label),
            'running' => sprintf('<info>%s</info>', $label),
            'succeeded' => sprintf('<fg=green>%s</>', $label),
            'failed' => sprintf('<error>%s</error>', $label),
            'cancelled' => sprintf('<fg=gray>%s</>', $label),
            default => $label,
        };
    }

    private function statusLabel(string $status): string
    {
        $label = __('messages.status.'.$status);

        if ($label !== 'messages.status.'.$status) {
            return (string) $label;
        }

        return str($status)->title()->toString();
    }

    private function backupSummary(CommandRun $run): string
    {
        $parts = array_filter([
            $run->backup_type,
            $run->backup_label,
        ]);

        if ($parts === []) {
            return '-';
        }

        return implode(':', $parts);
    }

    private function summaryRunValue(?CommandRun $run, string $timestampField): string
    {
        if (! $run instanceof CommandRun) {
            return '-';
        }

        /** @var Carbon|null $timestamp */
        $timestamp = $run->{$timestampField};

        $summary = $this->backupSummary($run);

        if ($summary === '-') {
            $summary = $run->operation;
        }

        if (! $timestamp instanceof Carbon) {
            return $summary;
        }

        return sprintf('%s at %s', $summary, $timestamp->format('Y-m-d H:i:s'));
    }

    private function restoreFailureSummary(?CommandRun $run): string
    {
        if (! $run instanceof CommandRun) {
            return '-';
        }

        $target = $run->restore_target ?? $run->argument_text;
        $summary = $run->operation;

        if (is_string($target) && $target !== '') {
            $summary .= sprintf(' (%s)', $target);
        }

        if (! $run->finished_at instanceof Carbon) {
            return $summary;
        }

        return sprintf('%s at %s', $summary, $run->finished_at->format('Y-m-d H:i:s'));
    }

    private function restoreRunSummary(?CommandRun $run): string
    {
        if (! $run instanceof CommandRun) {
            return '-';
        }

        $target = $run->restore_target ?? $run->argument_text;
        $summary = sprintf('%s [%s]', $run->operation, (string) $run->status->value);

        if (is_string($target) && $target !== '') {
            $summary .= sprintf(' (%s)', $target);
        }

        $metadata = is_array($run->metadata) ? $run->metadata : [];
        $audit = is_array($metadata['restore_audit'] ?? null) ? $metadata['restore_audit'] : [];
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

            $summary .= ' {'.implode(', ', $parts).'}';
        }

        $timestamp = $run->finished_at ?? $run->started_at;

        if (! $timestamp instanceof Carbon) {
            return $summary;
        }

        return sprintf('%s at %s', $summary, $timestamp->format('Y-m-d H:i:s'));
    }

    /**
     * @return array<string, mixed>
     */
    private function runPayload(CommandRun $run): array
    {
        return [
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
        ];
    }

    /**
     * @return array{label:string,timestamp:string|null,operation:string|null}
     */
    private function summarySignalPayload(?CommandRun $run, string $timestampField): array
    {
        if (! $run instanceof CommandRun) {
            return [
                'label' => '-',
                'timestamp' => null,
                'operation' => null,
            ];
        }

        /** @var Carbon|null $timestamp */
        $timestamp = $run->{$timestampField};

        return [
            'label' => $this->summaryRunValue($run, $timestampField),
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
            return [
                'label' => '-',
                'timestamp' => null,
                'operation' => null,
                'target' => null,
            ];
        }

        $target = $run->restore_target ?? $run->argument_text;

        return [
            'label' => $this->restoreFailureSummary($run),
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
            return [
                'label' => '-',
                'timestamp' => null,
                'operation' => null,
                'status' => null,
                'target' => null,
                'audit' => null,
            ];
        }

        $timestamp = $run->finished_at ?? $run->started_at;

        return [
            'label' => $this->restoreRunSummary($run),
            'timestamp' => $timestamp?->format('Y-m-d H:i:s'),
            'operation' => $run->operation,
            'status' => (string) $run->status->value,
            'target' => $run->restore_target ?? $run->argument_text,
            'audit' => $this->restoreAuditPayload($run),
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
}
