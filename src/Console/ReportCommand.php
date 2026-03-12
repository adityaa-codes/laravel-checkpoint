<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Services\ConfigValidator;
use AdityaaCodes\LaravelCheckpoint\Services\CommandJsonContract;
use AdityaaCodes\LaravelCheckpoint\Services\OperationalReportBuilder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

final class ReportCommand extends Command
{
    protected $signature = 'db-ops:report {--limit=10 : Number of recent runs to include.}';

    protected $description = 'Emit a machine-readable operational report for checkpoint status and health.';

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
        ['requested' => $requestedLimit, 'effective' => $effectiveLimit] = $this->recentRunLimits();

        try {
            $this->validator->validate();
            $reportPayload = $this->reportBuilder->reportPayload($effectiveLimit);
            $exitCode = self::SUCCESS;
        } catch (\Throwable $exception) {
            $reportPayload = [
                'recent_runs' => [],
                'summary' => $this->emptySummary(),
                'health' => [
                    'ok' => false,
                    'checks' => [[
                    'code' => 'config.validation',
                    'check' => 'Config validation',
                    'status' => 'fail',
                    'notes' => $exception->getMessage(),
                    'data' => [
                        'exception' => $exception::class,
                    ],
                    ]],
                ],
            ];
            $exitCode = self::FAILURE;
        }

        $this->line(json_encode($this->jsonContract->envelope('report', [
            'generated_at' => now()->toIso8601String(),
            'driver' => (string) $this->config->get('checkpoint.driver'),
            'limit_requested' => $requestedLimit,
            'limit' => $effectiveLimit,
            'recent_runs' => $reportPayload['recent_runs'],
            'summary' => $reportPayload['summary'],
            'health' => $reportPayload['health'],
        ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $exitCode;
    }

    /**
     * @return array{requested:int,effective:int}
     */
    private function recentRunLimits(): array
    {
        $requestedLimit = max(1, (int) $this->option('limit'));
        $configuredCap = max(1, (int) $this->config->get('checkpoint.reporting.max_recent_runs', 100));

        return [
            'requested' => $requestedLimit,
            'effective' => min($requestedLimit, $configuredCap),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(): array
    {
        $windowDays = max(1, (int) $this->config->get('checkpoint.observability.backup_drill_pass_rate_window_days', 30));
        $drillPassRate = [
            'label' => '-',
            'window_days' => $windowDays,
            'total' => 0,
            'passing' => 0,
            'pass_rate_percent' => null,
        ];

        return [
            'pending_runs' => 0,
            'running_runs' => 0,
            'failed_runs_24h' => 0,
            'last_known_good_backup' => ['label' => '-', 'timestamp' => null, 'operation' => null],
            'latest_verified_backup' => ['label' => '-', 'timestamp' => null, 'operation' => null],
            'latest_backup_drill' => ['label' => '-', 'timestamp' => null, 'run_uuid' => null, 'overall_result' => null, 'executed_by' => null],
            'latest_failed_backup_drill' => ['label' => '-', 'timestamp' => null, 'run_uuid' => null, 'overall_result' => null, 'executed_by' => null],
            'backup_drill_pass_rate' => $drillPassRate,
            'backup_drill_pass_rate_30d' => $drillPassRate,
            'latest_restore_run' => ['label' => '-', 'timestamp' => null, 'operation' => null, 'status' => null, 'target' => null, 'audit' => null],
            'latest_restore_failure' => ['label' => '-', 'timestamp' => null, 'operation' => null, 'target' => null],
        ];
    }
}
