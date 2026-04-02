<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Actions\EvaluateRetentionPolicyAction;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Services\CommandJsonContract;
use AdityaaCodes\LaravelCheckpoint\Services\ConfigValidator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

final class RetentionPolicyCommand extends Command
{
    protected $signature = 'db-ops:retention-policy
        {--format=table : Output format: table or json.}
        {--limit=100 : Maximum number of candidate rows to evaluate.}
        {--dry-run : Preview retention decisions without deleting records.}
        {--apply : Apply retention decisions immediately.}';

    protected $description = 'Evaluate and optionally apply policy-based retention for checkpoint command runs.';

    public function __construct(
        private readonly ConfigValidator $validator,
        private readonly EvaluateRetentionPolicyAction $evaluateRetentionPolicy,
        private readonly CommandJsonContract $jsonContract,
        private readonly Repository $config,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['table', 'json'], true)) {
            $this->error('The --format option must be table or json.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');

        if ($dryRun && $apply) {
            $this->error('Use either --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));

        try {
            $this->validator->validate();
        } catch (\Throwable $exception) {
            if ($format === 'json') {
                $this->line(json_encode($this->jsonContract->envelope('retention_policy', [
                    'generated_at' => now()->toIso8601String(),
                    'driver' => (string) $this->config->get('checkpoint.driver'),
                    'mode' => $apply ? 'apply' : 'dry_run',
                    'limit' => $limit,
                    'retention_enabled' => false,
                    'totals' => [
                        'eligible' => 0,
                        'deleted' => 0,
                        'externalized_output' => 0,
                    ],
                    'by_policy' => [],
                    'sample' => [],
                    'error' => [
                        'message' => $exception->getMessage(),
                        'exception' => $exception::class,
                    ],
                ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        }

        $evaluation = $this->evaluateRetentionPolicy->execute($limit);
        $retentionEnabled = $this->evaluateRetentionPolicy->retentionEnabled();
        $deleted = 0;

        if ($apply && $retentionEnabled) {
            $deleted = (new CommandRun)->pruneAll();
        }

        $payload = $this->jsonContract->envelope('retention_policy', [
            'generated_at' => now()->toIso8601String(),
            'driver' => (string) $this->config->get('checkpoint.driver'),
            'mode' => $apply ? 'apply' : 'dry_run',
            'limit' => $limit,
            'retention_enabled' => $retentionEnabled,
            'totals' => [
                'eligible' => (int) ($evaluation['totals']['eligible'] ?? 0),
                'deleted' => $deleted,
                'externalized_output' => (int) ($evaluation['totals']['externalized_output'] ?? 0),
            ],
            'by_policy' => $evaluation['by_policy'] ?? [],
            'sample' => $evaluation['sample'] ?? [],
        ]);

        if ($format === 'json') {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->table(['Field', 'Value'], [
            ['Driver', (string) $payload['driver']],
            ['Mode', (string) $payload['mode']],
            ['Retention enabled', ((bool) $payload['retention_enabled']) ? 'yes' : 'no'],
            ['Limit', (string) $payload['limit']],
            ['Eligible', (string) ($payload['totals']['eligible'] ?? 0)],
            ['Deleted', (string) ($payload['totals']['deleted'] ?? 0)],
            ['Externalized output', (string) ($payload['totals']['externalized_output'] ?? 0)],
        ]);

        $byPolicy = is_array($payload['by_policy'] ?? null) ? $payload['by_policy'] : [];
        $this->table(
            ['Policy', 'Label', 'Keep days', 'Count'],
            array_map(
                static fn (string $policy, array $row): array => [
                    $policy,
                    (string) ($row['label'] ?? ''),
                    (string) ($row['keep_days'] ?? ''),
                    (string) ($row['count'] ?? 0),
                ],
                array_keys($byPolicy),
                array_values($byPolicy),
            ),
        );

        $sample = is_array($payload['sample'] ?? null) ? $payload['sample'] : [];
        $this->table(
            ['ID', 'Operation', 'Status', 'Age (days)', 'Policy', 'Keep days', 'Reason'],
            array_map(
                static fn (array $row): array => [
                    (string) ($row['id'] ?? ''),
                    (string) ($row['operation'] ?? ''),
                    (string) ($row['status'] ?? ''),
                    (string) ($row['age_days'] ?? ''),
                    (string) ($row['policy'] ?? ''),
                    (string) ($row['keep_days'] ?? ''),
                    (string) ($row['reason'] ?? ''),
                ],
                $sample,
            ),
        );

        return self::SUCCESS;
    }
}
