<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Rendering\Concerns;

trait FormatsHealthData
{
    /**
     * @param  list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>,severity?:string}>  $checks
     * @return list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>
     */
    private function withSeverity(array $checks): array
    {
        return collect($checks)->map(function (array $check): array {
            $status = $check['status'];
            $severity = match ($status) {
                'fail' => 'blocker',
                'warn' => 'warning',
                default => 'info',
            };

            return [
                'code' => $check['code'],
                'check' => $check['check'],
                'status' => $status,
                'severity' => $severity,
                'notes' => $check['notes'],
                'data' => $check['data'],
            ];
        })->all();
    }

    /**
     * @param  list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>  $checks
     * @return array{blocker:int,warning:int,info:int}
     */
    private function severityTotals(array $checks): array
    {
        $blocker = count(collect($checks)->filter(static fn (array $check): bool => $check['severity'] === 'blocker')->all());
        $warning = count(collect($checks)->filter(static fn (array $check): bool => $check['severity'] === 'warning')->all());

        return [
            'blocker' => $blocker,
            'warning' => $warning,
            'info' => max(0, count($checks) - $blocker - $warning),
        ];
    }

    /**
     * @param  list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>  $checks
     * @return list<array<string, mixed>>
     */
    private function topIssues(array $checks, int $limit): array
    {
        $issues = collect($checks)->filter(static fn (array $check): bool => $check['status'] !== 'pass')->values()->all();
        $issues = $this->orderedChecksForDisplay($issues);

        return collect($issues)
            ->sort(fn (array $left, array $right): int => $this->impactScore($left) <=> $this->impactScore($right)
                ?: (($left['check'] ?? '') <=> ($right['check'] ?? '')))
            ->values()
            ->slice(0, max(1, $limit))
            ->all();
    }

    /**
     * @param  array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}  $check
     */
    private function impactScore(array $check): int
    {
        return match ($check['severity'] ?? 'info') {
            'blocker' => 0,
            'warning' => 1,
            default => 2,
        };
    }

    /**
     * @param  list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>  $checks
     */
    private function effectiveWarningCount(array $checks): int
    {
        $environment = app()->environment();

        if (! collect(['local', 'testing'])->containsStrict($environment)) {
            return count(collect($checks)->filter(static fn (array $check): bool => $check['severity'] === 'warning')->all());
        }

        $advisoryCodes = [
            'queue.worker_visibility',
            'restore.post_verification',
            'backup.last_known_good',
            'backup.duration_anomaly',
            'backup_drill.latest_run',
            'backup_drill.pass_rate',
            'backup_drill.trend',
            'backup_drill.playbook',
            'verification.runs',
        ];

        return count(collect($checks)->filter(static fn (array $check): bool => $check['severity'] === 'warning'
            && ! collect($advisoryCodes)->containsStrict($check['code']))->all());
    }

    /**
     * @param  list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>  $checks
     * @return array{window:string,indicators:list<array{name:string,target:int|float,current:int|float,status:string,unit:string}>,overall_status:string}
     */
    private function healthSloPayload(array $checks, int $failedCount, int $warnCount): array
    {
        $totalChecks = count($checks);
        $passCount = max(0, $totalChecks - $failedCount - $warnCount);
        $indicators = [
            [
                'name' => 'failed_checks',
                'target' => 0,
                'current' => $failedCount,
                'status' => $failedCount > 0 ? 'fail' : 'pass',
                'unit' => 'checks',
            ],
            [
                'name' => 'warning_checks',
                'target' => 0,
                'current' => $warnCount,
                'status' => $warnCount > 0 ? 'warn' : 'pass',
                'unit' => 'checks',
            ],
            [
                'name' => 'passing_checks',
                'target' => $totalChecks,
                'current' => $passCount,
                'status' => $failedCount > 0 ? 'fail' : ($warnCount > 0 ? 'warn' : 'pass'),
                'unit' => 'checks',
            ],
        ];

        return [
            'window' => 'current_health_snapshot',
            'indicators' => $indicators,
            'overall_status' => $failedCount > 0 ? 'fail' : ($warnCount > 0 ? 'warn' : 'pass'),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @return list<array<string,mixed>>
     */
    private function orderedChecksForDisplay(array $checks): array
    {
        $rank = [
            'fail' => 0,
            'warn' => 1,
            'pass' => 2,
        ];

        return collect($checks)
            ->sort(fn (array $left, array $right): int => ($rank[$left['status'] ?? 'pass'] ?? 3) <=> ($rank[$right['status'] ?? 'pass'] ?? 3)
                ?: (($left['check'] ?? '') <=> ($right['check'] ?? '')))
            ->values()->all();
    }
}
