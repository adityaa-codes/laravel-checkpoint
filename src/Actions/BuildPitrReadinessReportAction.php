<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Actions;

use AdityaaCodes\LaravelCheckpoint\Exceptions\InvalidArgumentException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;

final readonly class BuildPitrReadinessReportAction
{
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * @return array{
     *   generated_at:string,
     *   target:string,
     *   readiness:string,
     *   checks:list<array{code:string,status:string,message:string,data:array<string,mixed>}>,
     *   summary:array{pass:int,fail:int}
     * }
     */
    public function execute(?string $target): array
    {
        $resolvedTarget = $this->resolvedTarget($target);
        $baseline = $this->latestBaseline();
        $configuredBinlogs = $this->configuredBinlogFiles();
        $missingBinlogs = array_values(array_filter(
            $configuredBinlogs,
            static fn (string $path): bool => ! is_file($path),
        ));

        $checks = [
            [
                'code' => 'baseline.last_known_good',
                'status' => $baseline !== null ? 'pass' : 'fail',
                'message' => $baseline !== null
                    ? 'Found a last-known-good logical backup baseline.'
                    : 'Missing last-known-good logical backup baseline required for PITR.',
                'data' => [
                    'command_run_id' => $baseline?->getKey(),
                    'artifact_path' => $baseline?->artifact_path,
                    'last_known_good_at' => $baseline?->last_known_good_at?->format('Y-m-d H:i:s'),
                ],
            ],
            [
                'code' => 'baseline.artifact_exists',
                'status' => $baseline !== null && is_string($baseline->artifact_path) && $baseline->artifact_path !== '' && is_file($baseline->artifact_path) ? 'pass' : 'fail',
                'message' => $baseline !== null && is_string($baseline->artifact_path) && $baseline->artifact_path !== '' && is_file($baseline->artifact_path)
                    ? 'Baseline backup artifact exists on disk.'
                    : 'Baseline backup artifact is missing on disk.',
                'data' => [
                    'artifact_path' => $baseline?->artifact_path,
                ],
            ],
            [
                'code' => 'binlog.chain_configured',
                'status' => $configuredBinlogs !== [] ? 'pass' : 'fail',
                'message' => $configuredBinlogs !== []
                    ? 'MySQL binlog chain is configured.'
                    : 'MySQL PITR binlog chain is not configured.',
                'data' => [
                    'configured_count' => count($configuredBinlogs),
                    'configured_files' => $configuredBinlogs,
                ],
            ],
            [
                'code' => 'binlog.chain_files_exist',
                'status' => $configuredBinlogs !== [] && $missingBinlogs === [] ? 'pass' : 'fail',
                'message' => $configuredBinlogs !== [] && $missingBinlogs === []
                    ? 'Configured MySQL binlog files are present.'
                    : 'One or more configured MySQL binlog files are missing.',
                'data' => [
                    'missing_files' => $missingBinlogs,
                ],
            ],
            [
                'code' => 'target.not_future',
                'status' => $resolvedTarget->lessThanOrEqualTo(now()) ? 'pass' : 'fail',
                'message' => $resolvedTarget->lessThanOrEqualTo(now())
                    ? 'Requested PITR target is not in the future.'
                    : 'Requested PITR target is in the future.',
                'data' => [
                    'target' => $resolvedTarget->format('Y-m-d H:i:s'),
                    'now' => now()->format('Y-m-d H:i:s'),
                ],
            ],
            [
                'code' => 'target.after_baseline',
                'status' => $baseline instanceof CommandRun && $baseline->last_known_good_at instanceof Carbon
                    ? ($resolvedTarget->greaterThanOrEqualTo($baseline->last_known_good_at) ? 'pass' : 'fail')
                    : 'fail',
                'message' => $baseline instanceof CommandRun && $baseline->last_known_good_at instanceof Carbon
                    ? ($resolvedTarget->greaterThanOrEqualTo($baseline->last_known_good_at)
                        ? 'Requested PITR target is newer than or equal to baseline backup.'
                        : 'Requested PITR target is older than baseline backup.')
                    : 'Unable to compare PITR target with baseline backup timestamp.',
                'data' => [
                    'target' => $resolvedTarget->format('Y-m-d H:i:s'),
                    'baseline_last_known_good_at' => $baseline?->last_known_good_at?->format('Y-m-d H:i:s'),
                ],
            ],
        ];

        $failedChecks = count(array_filter(
            $checks,
            static fn (array $check): bool => $check['status'] === 'fail',
        ));

        return [
            'generated_at' => now()->toIso8601String(),
            'target' => $resolvedTarget->format('Y-m-d H:i:s'),
            'readiness' => $failedChecks === 0 ? 'ready' : 'not_ready',
            'checks' => $checks,
            'summary' => [
                'pass' => count($checks) - $failedChecks,
                'fail' => $failedChecks,
            ],
        ];
    }

    private function resolvedTarget(?string $target): Carbon
    {
        $value = is_string($target) ? trim($target) : '';

        if ($value === '') {
            return now();
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            throw new InvalidArgumentException('PITR target must be a valid datetime string.');
        }
    }

    private function latestBaseline(): ?CommandRun
    {
        $run = CommandRun::query()
            ->where('operation', 'logical_backup')
            ->where('status', 'succeeded')
            ->whereNotNull('artifact_path')
            ->whereNotNull('last_known_good_at')
            ->latest('last_known_good_at')
            ->latest('id')
            ->first();

        return $run instanceof CommandRun ? $run : null;
    }

    /**
     * @return list<string>
     */
    private function configuredBinlogFiles(): array
    {
        $configured = $this->config->get('checkpoint.drivers.mysql.pitr.binlog_files', []);

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? trim($item) : '',
            $configured,
        ), static fn (string $item): bool => $item !== ''));
    }
}
