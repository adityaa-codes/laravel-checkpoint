<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console\Concerns;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

trait UsesLaravelPrompts
{
    protected function enhancedInteractiveMode(): bool
    {
        if ($this->input === null) {
            return false;
        }

        if ($this->input->hasOption('no-interaction') && $this->input->getOption('no-interaction')) {
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
        if (! $this->enhancedInteractiveMode()) {
            $this->table($headers, $rows);

            return;
        }

        table(headers: $headers, rows: $rows);
    }

    protected function promptInfo(string $message): void
    {
        if (! $this->enhancedInteractiveMode()) {
            $this->info($message);

            return;
        }

        info($message);
    }

    protected function promptWarning(string $message): void
    {
        if (! $this->enhancedInteractiveMode()) {
            $this->warn($message);

            return;
        }

        warning($message);
    }

    protected function promptError(string $message): void
    {
        if (! $this->enhancedInteractiveMode()) {
            $this->error($message);

            return;
        }

        error($message);
    }

    protected function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) ? $value : null;
    }

    protected function policyProfileOverride(): ?string
    {
        $override = trim((string) ($this->stringOption('policy-profile') ?? ''));

        return $override !== '' ? $override : null;
    }

    /**
     * @param  array<string, mixed>  $gateDecision
     * @return array{profile:string,profile_source:string,verdict:string,failed_gate:string,exit_code:int}
     */
    protected function machineGateDecision(array $gateDecision): array
    {
        return [
            'profile' => (string) ($gateDecision['profile'] ?? 'unknown'),
            'profile_source' => (string) ($gateDecision['profile_source'] ?? 'default'),
            'verdict' => (string) ($gateDecision['verdict'] ?? 'fail'),
            'failed_gate' => (string) ($gateDecision['failed_gate'] ?? 'policy'),
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

        return collect($checks)
            ->sort(fn (array $left, array $right): int => ($rank[(string) ($left['status'] ?? 'pass')] ?? 3) <=> ($rank[(string) ($right['status'] ?? 'pass')] ?? 3)
                ?: ((string) ($left['check'] ?? '') <=> (string) ($right['check'] ?? '')))
            ->values()
            ->all();
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
        $configuredCap = max(1, (int) $this->config->get('checkpoint.reporting.max_recent_runs', 100));

        return [
            'requested' => $requestedLimit,
            'effective' => min($requestedLimit, $configuredCap),
        ];
    }

    protected function translatedOr(string $key, string $default, array $replace = []): string
    {
        $value = (string) __($key, $replace);

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
