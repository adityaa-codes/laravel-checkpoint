<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** @internal */
final readonly class RunPayloadFormatter
{
    /**
     * @return array{label:string,timestamp:string|null,operation:string|null,status:string|null,exit_code:int|null,failure_reason:string|null,next_action:string|null}
     */
    public function latestFailedRun(?CommandRun $run): array
    {
        if (! $run instanceof CommandRun) {
            return [
                'label' => '-', 'timestamp' => null, 'operation' => null, 'status' => null,
                'exit_code' => null, 'failure_reason' => null, 'next_action' => null,
            ];
        }

        $timestamp = $run->finished_at ?? $run->started_at;
        $reason = $this->resolveFailureReason($run);
        $nextAction = $this->nextActionForFailure($run, $reason);

        $exitLabel = $run->exit_code !== null ? (string) $run->exit_code : '-';
        $atLabel = $timestamp instanceof Carbon ? ' at '.$timestamp->format('Y-m-d H:i:s') : '';

        return [
            'label' => "{$run->operation} [failed] (exit: {$exitLabel}){$atLabel}",
            'timestamp' => $timestamp?->format('Y-m-d H:i:s'),
            'operation' => $run->operation,
            'status' => $run->status->value,
            'exit_code' => $run->exit_code,
            'failure_reason' => $reason,
            'next_action' => $nextAction,
        ];
    }

    /**
     * @return array{label:string,timestamp:string|null,operation:string|null,target:string|null}
     */
    public function restoreFailure(?CommandRun $run): array
    {
        if (! $run instanceof CommandRun) {
            return ['label' => '-', 'timestamp' => null, 'operation' => null, 'target' => null];
        }

        $target = $run->restore_target ?? $run->argument_text;
        $label = $run->operation;

        if ($target !== null && $target !== '') {
            $label .= " ({$target})";
        }

        if ($run->finished_at instanceof Carbon) {
            $label .= " at {$run->finished_at->format('Y-m-d H:i:s')}";
        }

        return [
            'label' => $label,
            'timestamp' => $run->finished_at?->format('Y-m-d H:i:s'),
            'operation' => $run->operation,
            'target' => $target,
        ];
    }

    /**
     * @return array{label:string,timestamp:string|null,operation:string|null}
     */
    public function summarySignal(?CommandRun $run, string $timestampField): array
    {
        if (! $run instanceof CommandRun) {
            return ['label' => '-', 'timestamp' => null, 'operation' => null];
        }

        /** @var Carbon|null $timestamp */
        $timestamp = $run->{$timestampField};
        $summary = $this->backupSummary($run);
        $summary = $summary === '-' ? (string) $run->operation : $summary;

        return [
            'label' => $timestamp instanceof Carbon ? "{$summary} at {$timestamp->format('Y-m-d H:i:s')}" : $summary,
            'timestamp' => $timestamp?->format('Y-m-d H:i:s'),
            'operation' => $run->operation,
        ];
    }

    public function backupSummary(CommandRun $run): string
    {
        $parts = collect([$run->backup_type, $run->backup_label])->filter()->all();

        return $parts === [] ? '-' : implode(':', $parts);
    }

    private function resolveFailureReason(CommandRun $run): ?string
    {
        if ($run->command_output !== null && str($run->command_output)->trim()->isNotEmpty()) {
            $line = str(strtok($run->command_output, "\n") ?: '')->trim()->value();

            if ($line !== '') {
                return Str::substr($line, 0, 240);
            }
        }

        if ($run->exit_code !== null) {
            return "Command exited with code {$run->exit_code}.";
        }

        return null;
    }

    private function nextActionForFailure(CommandRun $run, ?string $reason): string
    {
        if (
            $run->operation === 'logical_backup'
            && $reason !== null
            && str($reason)->contains('No shell command configured')
        ) {
            return 'Set CP_CMD_LOGICAL_BACKUP, then run php artisan checkpoint:backup.';
        }

        return 'Run php artisan checkpoint:status --full --limit=10 --format=json for full failure context.';
    }
}
