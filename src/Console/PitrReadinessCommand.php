<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Actions\BuildPitrReadinessReportAction;
use AdityaaCodes\LaravelCheckpoint\Services\CommandJsonContract;
use AdityaaCodes\LaravelCheckpoint\Services\ConfigValidator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

final class PitrReadinessCommand extends Command
{
    protected $signature = 'db-ops:pitr-readiness {target? : PITR target datetime (defaults to now).} {--format=table : Output format: table or json.} {--agent : Emit compact AI-agent friendly JSON output.}';

    protected $description = 'Evaluate PITR readiness for a target timestamp.';

    public function __construct(
        private readonly ConfigValidator $validator,
        private readonly BuildPitrReadinessReportAction $buildPitrReadinessReport,
        private readonly CommandJsonContract $jsonContract,
        private readonly Repository $config,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $format = (string) $this->option('format');
        $agentMode = (bool) $this->option('agent');

        if (! $agentMode && ! in_array($format, ['table', 'json'], true)) {
            $this->error('The --format option must be table or json.');

            return self::FAILURE;
        }

        try {
            $this->validator->validate();
            $payload = $this->buildPitrReadinessReport->execute(
                is_string($this->argument('target')) ? $this->argument('target') : null,
            );
            $exitCode = ($payload['readiness'] ?? 'not_ready') === 'ready' ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $exception) {
            $payload = [
                'generated_at' => now()->toIso8601String(),
                'target' => null,
                'readiness' => 'not_ready',
                'checks' => [[
                    'code' => 'config.validation',
                    'status' => 'fail',
                    'message' => $exception->getMessage(),
                    'data' => [
                        'exception' => $exception::class,
                    ],
                ]],
                'summary' => [
                    'pass' => 0,
                    'fail' => 1,
                ],
            ];
            $exitCode = self::FAILURE;
        }

        if ($agentMode) {
            $this->line(json_encode($this->jsonContract->envelope('pitr_readiness', [
                'result' => ($payload['readiness'] ?? 'not_ready') === 'ready' ? 'passed' : 'failed',
                'code' => ($payload['readiness'] ?? 'not_ready') === 'ready'
                    ? 'pitr.readiness.ready'
                    : 'pitr.readiness.not_ready',
                'summary' => sprintf(
                    'PITR readiness: %s (%d pass, %d fail).',
                    (string) ($payload['readiness'] ?? 'not_ready'),
                    (int) ($payload['summary']['pass'] ?? 0),
                    (int) ($payload['summary']['fail'] ?? 0),
                ),
                'data' => [
                    ...$payload,
                    'driver' => (string) $this->config->get('checkpoint.driver'),
                ],
                'suggestions' => $this->suggestions($payload),
            ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $exitCode;
        }

        if ($format === 'json') {
            $this->line(json_encode($this->jsonContract->envelope('pitr_readiness', [
                ...$payload,
                'driver' => (string) $this->config->get('checkpoint.driver'),
            ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $exitCode;
        }

        $this->table(['Field', 'Value'], [
            ['Driver', (string) $this->config->get('checkpoint.driver')],
            ['Target', (string) ($payload['target'] ?? '-')],
            ['Readiness', (string) ($payload['readiness'] ?? 'not_ready')],
            ['Pass checks', (string) ($payload['summary']['pass'] ?? 0)],
            ['Fail checks', (string) ($payload['summary']['fail'] ?? 0)],
        ]);

        $checks = is_array($payload['checks'] ?? null) ? $payload['checks'] : [];
        $this->table(
            ['Check', 'Status', 'Message'],
            array_map(
                static fn (array $check): array => [
                    (string) ($check['code'] ?? ''),
                    (string) ($check['status'] ?? ''),
                    (string) ($check['message'] ?? ''),
                ],
                $checks,
            ),
        );

        return $exitCode;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function suggestions(array $payload): array
    {
        if (($payload['readiness'] ?? 'not_ready') === 'ready') {
            return [];
        }

        $checks = is_array($payload['checks'] ?? null) ? $payload['checks'] : [];
        $suggestions = [];

        foreach ($checks as $check) {
            if (($check['status'] ?? null) !== 'fail') {
                continue;
            }

            $code = (string) ($check['code'] ?? '');

            if ($code === 'baseline.last_known_good') {
                $suggestions[] = 'Run a successful logical backup to establish a last-known-good PITR baseline.';
            } elseif ($code === 'baseline.artifact_exists') {
                $suggestions[] = 'Restore baseline artifact availability before PITR by fixing storage/path retention.';
            } elseif ($code === 'binlog.chain_configured') {
                $suggestions[] = 'Configure checkpoint.drivers.mysql.pitr.binlog_files with the active MySQL binlog chain.';
            } elseif ($code === 'binlog.chain_files_exist') {
                $suggestions[] = 'Ensure configured MySQL binlog files exist and are readable by workers.';
            } elseif ($code === 'target.not_future') {
                $suggestions[] = 'Use a PITR target timestamp that is not in the future.';
            } elseif ($code === 'target.after_baseline') {
                $suggestions[] = 'Choose a PITR target at or after the baseline last-known-good timestamp.';
            }
        }

        return array_values(array_unique($suggestions));
    }
}
