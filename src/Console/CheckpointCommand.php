<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Console\Command;

abstract class CheckpointCommand extends Command
{
    protected function enhancedInteractiveMode(): bool
    {
        if ($this->input === null) {
            return false;
        }

        return $this->input->isInteractive() && ! app()->runningUnitTests();
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<int, mixed>>  $rows
     */
    protected function promptTable(array $headers, array $rows): void
    {
        $this->table($headers, $rows);
    }

    protected function promptInfo(string $message): void
    {
        $this->info($message);
    }

    protected function promptWarning(string $message): void
    {
        $this->warn($message);
    }

    protected function promptError(string $message): void
    {
        $this->error($message);
    }

    protected function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) ? $value : null;
    }

    protected function policyProfileOverride(): ?string
    {
        $override = trim($this->stringOption('policy-profile') ?? '');

        return $override !== '' ? $override : null;
    }

    /**
     * @param  array<string, mixed>  $gateDecision
     * @return array{profile:string,profile_source:string,verdict:string,failed_gate:string,exit_code:int}
     */
    protected function machineGateDecision(array $gateDecision): array
    {
        return [
            'profile' => $gateDecision['profile'] ?? 'unknown',
            'profile_source' => $gateDecision['profile_source'] ?? 'default',
            'verdict' => $gateDecision['verdict'] ?? 'fail',
            'failed_gate' => $gateDecision['failed_gate'] ?? 'policy',
            'exit_code' => (int) ($gateDecision['exit_code'] ?? 12),
        ];
    }

    protected function shouldCollapsePassingChecks(): bool
    {
        return ! $this->getOutput()->isVerbose();
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @return list<array<string,mixed>>
     */
    protected function orderedChecksForDisplay(array $checks): array
    {
        $rank = [
            'fail' => 0,
            'warn' => 1,
            'pass' => 2,
        ];

        return array_values(collect($checks)
            ->sort(fn (array $left, array $right): int => ($rank[$left['status'] ?? 'pass'] ?? 3) <=> ($rank[$right['status'] ?? 'pass'] ?? 3)
                ?: (($left['check'] ?? '') <=> ($right['check'] ?? '')))
            ->values()
            ->all());
    }

    /**
     * @param  list<array{name:string,target:int|float,current:int|float,status:string,unit:string}>  $indicators
     */
    protected function overallSloStatus(array $indicators): string
    {
        $indicators = collect($indicators);

        if ($indicators->contains('status', 'fail')) {
            return 'fail';
        }

        if ($indicators->contains('status', 'warn')) {
            return 'warn';
        }

        return 'pass';
    }

    /**
     * @return array{requested:int,effective:int}
     */
    protected function recentRunLimits(): array
    {
        $requestedLimit = max(1, (int) $this->option('limit'));
        $configuredCap = max(1, (int) config('checkpoint.reporting.max_recent_runs', 100));

        return [
            'requested' => $requestedLimit,
            'effective' => min($requestedLimit, $configuredCap),
        ];
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    protected function translatedOr(string $key, string $default, array $replace = []): string
    {
        $value = __($key, $replace);

        return $value !== $key ? $value : $default;
    }

    protected function resolveOutputMode(string $format, bool $agentMode): string
    {
        if ($agentMode) {
            return 'agent';
        }

        return in_array($format, ['table', 'json', 'compact-json'], true) ? $format : 'table';
    }

    protected function priorityLabel(string $status): string
    {
        return match ($status) {
            'fail' => 'P0',
            'warn' => 'P1',
            default => 'P3',
        };
    }
}
