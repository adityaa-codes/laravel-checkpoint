<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Services\ConfigValidator;
use AdityaaCodes\LaravelCheckpoint\Services\CommandJsonContract;
use AdityaaCodes\LaravelCheckpoint\Services\OperationalReportBuilder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

final class DoctorCommand extends Command
{
    protected $signature = 'db-ops:doctor {--format=table} {--agent : Emit compact AI-agent friendly JSON output.}';

    protected $description = 'Show checkpoint package health checks.';

    public function __construct(
        private readonly ConfigValidator $validator,
        private readonly Repository $config,
        private readonly OperationalReportBuilder $reportBuilder,
        private readonly CommandJsonContract $jsonContract,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $format = (string) $this->option('format');
        $agentMode = (bool) $this->option('agent');
        $outputMode = $agentMode ? 'agent' : (in_array($format, ['table', 'json'], true) ? $format : 'table');

        try {
            $this->validator->validate();
            $checks = $this->reportBuilder->healthChecks();
        } catch (\Throwable $exception) {
            $checks = [[
                'code' => 'config.validation',
                'check' => 'Config validation',
                'status' => 'fail',
                'notes' => $exception->getMessage(),
                'data' => [
                    'exception' => $exception::class,
                ],
            ]];

            if ($outputMode === 'json') {
                $this->line($this->jsonReport($checks));
 
                return self::FAILURE;
            }
 
            if ($outputMode === 'agent') {
                $this->line($this->agentReport($checks));

                return self::FAILURE;
            }

            $this->table(['Check', 'Status', 'Notes'], array_map(
                fn (array $check): array => [$check['check'], $this->statusWord((string) $check['status']), $check['notes']],
                $checks,
            ));

            return self::FAILURE;
        }

        if ($outputMode === 'json') {
            $this->line($this->jsonReport($checks));
 
            return self::SUCCESS;
        }
 
        if ($outputMode === 'agent') {
            $this->line($this->agentReport($checks));

            return self::SUCCESS;
        }

        $this->table(['Check', 'Status', 'Notes'], array_map(
            fn (array $check): array => [$check['check'], $this->statusWord((string) $check['status']), $check['notes']],
            $checks,
        ));

        return self::SUCCESS;
    }

    private function statusWord(string $level): string
    {
        return match ($level) {
            'pass' => (string) __('messages.cli.doctor_pass'),
            'warn' => (string) __('messages.cli.doctor_warn'),
            default => (string) __('messages.cli.doctor_fail'),
        };
    }

    /**
     * @param  list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>  $checks
     */
    private function jsonReport(array $checks): string
    {
        $report = [
            'ok' => $this->reportBuilder->healthOk($checks),
            'driver' => (string) $this->config->get('checkpoint.driver'),
            'generated_at' => now()->toIso8601String(),
            'checks' => array_map(fn (array $check): array => [
                'code' => $check['code'],
                'check' => $check['check'],
                'status' => $check['status'],
                'notes' => $check['notes'],
                'data' => $check['data'],
            ], $checks),
        ];

        $report = $this->jsonContract->envelope('doctor', $report);

        return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>  $checks
     */
    private function agentReport(array $checks): string
    {
        $failedCount = count(array_filter($checks, static fn (array $check): bool => (string) $check['status'] === 'fail'));
        $warnCount = count(array_filter($checks, static fn (array $check): bool => (string) $check['status'] === 'warn'));
        $ok = $this->reportBuilder->healthOk($checks);

        $report = [
            'result' => $failedCount > 0 ? 'failed' : ($warnCount > 0 ? 'partial' : 'passed'),
            'code' => $failedCount > 0 ? 'doctor.health.failed' : ($warnCount > 0 ? 'doctor.health.warn' : 'doctor.health.ok'),
            'summary' => sprintf('%d fail, %d warn, %d pass checks.', $failedCount, $warnCount, count($checks) - $failedCount - $warnCount),
            'data' => [
                'ok' => $ok,
                'driver' => (string) $this->config->get('checkpoint.driver'),
                'generated_at' => now()->toIso8601String(),
                'checks' => array_map(fn (array $check): array => [
                    'code' => $check['code'],
                    'check' => $check['check'],
                    'status' => $check['status'],
                    'notes' => $check['notes'],
                    'data' => $check['data'],
                ], $checks),
            ],
            'suggestions' => $this->suggestionsFromChecks($checks),
        ];

        $report = $this->jsonContract->envelope('doctor', $report);

        return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>  $checks
     * @return list<string>
     */
    private function suggestionsFromChecks(array $checks): array
    {
        $suggestions = [];

        foreach ($checks as $check) {
            if ((string) $check['status'] === 'pass') {
                continue;
            }

            $code = (string) $check['code'];

            if ($code === 'config.validation') {
                $suggestions[] = 'Fix checkpoint config validation errors and rerun db-ops:doctor --agent.';
                continue;
            }

            if ($code === 'queue.orphaned_runs') {
                $suggestions[] = 'Run db-ops:recover-orphans and ensure queue workers emit regular heartbeats.';
                continue;
            }

            if (str_starts_with($code, 'backup_drill.')) {
                $suggestions[] = 'Record or run a backup drill and verify observability thresholds.';
                continue;
            }

            if ($code === 'backup.last_known_good') {
                $suggestions[] = 'Queue a fresh backup and verify last-known-good signals are updated.';
                continue;
            }
        }

        $suggestions = array_values(array_unique($suggestions));

        return array_slice($suggestions, 0, 5);
    }
}
