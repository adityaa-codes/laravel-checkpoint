<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Services\ConfigValidator;
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
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        try {
            $this->validator->validate();
            $checks = $this->reportBuilder->healthChecks();
            $health = [
                'ok' => $this->reportBuilder->healthOk($checks),
                'checks' => $checks,
            ];
            $exitCode = self::SUCCESS;
        } catch (\Throwable $exception) {
            $health = [
                'ok' => false,
                'checks' => [[
                    'check' => 'Config validation',
                    'status' => 'fail',
                    'notes' => $exception->getMessage(),
                ]],
            ];
            $exitCode = self::FAILURE;
        }

        $this->line(json_encode([
            'version' => 1,
            'generated_at' => now()->toIso8601String(),
            'driver' => (string) $this->config->get('checkpoint.driver'),
            'recent_runs' => $this->reportBuilder->recentRuns($limit),
            'summary' => $this->reportBuilder->summary(),
            'health' => $health,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $exitCode;
    }
}
