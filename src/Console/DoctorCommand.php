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
    protected $signature = 'db-ops:doctor {--format=table}';

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
        $outputMode = in_array($format, ['table', 'json'], true) ? $format : 'table';

        try {
            $this->validator->validate();
            $checks = $this->reportBuilder->healthChecks();
        } catch (\Throwable $exception) {
            $checks = [[
                'check' => 'Config validation',
                'status' => 'fail',
                'notes' => $exception->getMessage(),
            ]];

            if ($outputMode === 'json') {
                $this->line($this->jsonReport($checks));

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

    private function statusLevel(string $statusWord): string
    {
        return match ($statusWord) {
            (string) __('messages.cli.doctor_pass'), 'messages.cli.doctor_pass' => 'pass',
            (string) __('messages.cli.doctor_warn'), 'messages.cli.doctor_warn' => 'warn',
            default => 'fail',
        };
    }

    /**
     * @param  list<array{check:string,status:string,notes:string}>  $checks
     */
    private function jsonReport(array $checks): string
    {
        $report = [
            'ok' => $this->reportBuilder->healthOk($checks),
            'driver' => (string) $this->config->get('checkpoint.driver'),
            'generated_at' => now()->toIso8601String(),
            'checks' => array_map(fn (array $check): array => [
                'check' => $check['check'],
                'status' => $this->statusLevel($this->statusWord((string) $check['status'])),
                'notes' => $check['notes'],
            ], $checks),
        ];

        $report = $this->jsonContract->envelope('doctor', $report);

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '{"ok":false,"checks":[]}';
    }
}
