<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Actions\EvaluateRetentionPolicyAction;
use AdityaaCodes\LaravelCheckpoint\Console\Concerns\UsesLaravelPrompts;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Services\CommandJsonContract;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Laravel\Prompts\Prompt;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\note;

final class RetentionPolicyCommand extends Command
{
    use UsesLaravelPrompts;

    protected $signature = 'checkpoint:retention-policy
        {--format=table : Output format: table or json.}
        {--limit=100 : Maximum number of candidate rows to evaluate.}
        {--dry-run : Preview retention decisions without deleting records.}
        {--apply : Apply retention decisions immediately.}
        {--force : Skip confirmation prompt when applying.}';

    protected $description = 'Evaluate and optionally apply policy-based retention for checkpoint command runs.';

    public function __construct(
        private readonly EvaluateRetentionPolicyAction $evaluateRetentionPolicy,
        private readonly CommandJsonContract $jsonContract,
        private readonly Repository $config,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        Prompt::interactive($this->enhancedInteractiveMode());

        if ($this->enhancedInteractiveMode()) {
            note('What: preview/apply policy-based retention windows per run category.');
            note('When: controlled cleanup with visibility before deletion.');
            note('Next: run checkpoint:report to review post-retention health.');
        }

        $format = $this->stringOption('format') ?? 'table';

        if (! in_array($format, ['table', 'json'], true)) {
            $this->promptError('The --format option must be table or json.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');

        if ($dryRun && $apply) {
            $this->promptError('Use either --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));

        $evaluation = $this->evaluateRetentionPolicy->execute($limit);
        $retentionEnabled = $this->evaluateRetentionPolicy->retentionEnabled();
        $deleted = 0;

        if ($apply && $retentionEnabled) {
            if ($this->enhancedInteractiveMode() && ! (bool) $this->option('force')) {
                $confirmed = confirm('Apply retention policy? This will delete eligible records permanently.');

                if (! $confirmed) {
                    $this->promptWarning('Retention apply cancelled.');

                    return self::SUCCESS;
                }
            }

            $deleted = app()->make(CommandRun::class)->pruneAll();
        }

        $payload = $this->jsonContract->envelope('retention_policy', [
            'generated_at' => now()->toIso8601String(),
            'driver' => (string) $this->config->get('checkpoint.driver'),
            'mode' => $apply ? 'apply' : 'dry_run',
            'limit' => $limit,
            'retention_enabled' => $retentionEnabled,
            'totals' => [
                'eligible' => $evaluation['totals']['eligible'],
                'deleted' => $deleted,
                'externalized_output' => $evaluation['totals']['externalized_output'],
            ],
            'by_policy' => $evaluation['by_policy'],
            'sample' => $evaluation['sample'],
        ]);

        if ($format === 'json') {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->promptTable(['Field', 'Value'], [
            ['Driver', (string) $payload['driver']],
            ['Mode', (string) $payload['mode']],
            ['Retention enabled', ((bool) $payload['retention_enabled']) ? 'yes' : 'no'],
            ['Limit', (string) $payload['limit']],
            ['Eligible', (string) $payload['totals']['eligible']],
            ['Deleted', (string) $payload['totals']['deleted']],
            ['Externalized output', (string) $payload['totals']['externalized_output']],
        ]);

        $byPolicy = $payload['by_policy'];
        $policyRows = [];

        foreach ($byPolicy as $policy => $row) {
            $policyRows[] = [
                $policy,
                $row['label'],
                (string) $row['keep_days'],
                (string) $row['count'],
            ];
        }

        $this->promptTable(['Policy', 'Label', 'Keep days', 'Count'], $policyRows);

        $sample = $payload['sample'];
        $sampleRows = array_values(array_map(
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
        ));
        $this->promptTable(
            ['ID', 'Operation', 'Status', 'Age (days)', 'Policy', 'Keep days', 'Reason'],
            $sampleRows,
        );

        return self::SUCCESS;
    }
}
