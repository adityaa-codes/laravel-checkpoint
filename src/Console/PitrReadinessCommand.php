<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Actions\BuildPitrReadinessReportAction;
use AdityaaCodes\LaravelCheckpoint\Console\Concerns\UsesLaravelPrompts;
use AdityaaCodes\LaravelCheckpoint\Services\CommandJsonContract;
use AdityaaCodes\LaravelCheckpoint\Services\ConfigValidator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

use function Laravel\Prompts\note;

final class PitrReadinessCommand extends Command
{
    use UsesLaravelPrompts;

    protected $signature = 'checkpoint:pitr-readiness {target? : PITR target datetime (defaults to now).} {--format=table : Output format: table or json.} {--agent : Emit compact AI-agent friendly JSON output.}';

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
        $agentMode = (bool) $this->option('agent');

        if ($this->enhancedInteractiveMode() && ! $agentMode) {
            note('What: evaluate whether PITR prerequisites are currently satisfied.');
            note('When: before relying on point-in-time recovery in real incidents.');
            note('Next: remediate failing checks, then rerun checkpoint:pitr-readiness.');
        }

        $format = $this->stringOption('format') ?? 'table';
        $target = $this->argument('target');
        $targetInput = is_string($target) ? $target : null;

        if (! $agentMode && ! in_array($format, ['table', 'json'], true)) {
            $this->promptError('The --format option must be table or json.');

            return self::FAILURE;
        }

        try {
            $this->validator->validate();
            $payload = $this->buildPitrReadinessReport->execute($targetInput);
            $exitCode = $payload['readiness'] === 'ready' ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $exception) {
            report($exception);

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
                'result' => $payload['readiness'] === 'ready' ? 'passed' : 'failed',
                'code' => $payload['readiness'] === 'ready'
                    ? 'pitr.readiness.ready'
                    : 'pitr.readiness.not_ready',
                'summary' => sprintf(
                    'PITR readiness: %s (%d pass, %d fail).',
                    $payload['readiness'],
                    $payload['summary']['pass'],
                    $payload['summary']['fail'],
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

        $this->promptTable(['Field', 'Value'], [
            ['Driver', (string) $this->config->get('checkpoint.driver')],
            ['Target', $payload['target'] ?? '-'],
            ['Readiness', $payload['readiness']],
            ['Pass checks', (string) $payload['summary']['pass']],
            ['Fail checks', (string) $payload['summary']['fail']],
        ]);

        $checks = $payload['checks'];
        $this->promptTable(
            ['Check', 'Status', 'Message'],
            array_map(
                static fn (array $check): array => [
                    $check['code'],
                    $check['status'],
                    $check['message'],
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
