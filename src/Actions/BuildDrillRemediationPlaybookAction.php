<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Actions;

use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use Illuminate\Support\Carbon;

final readonly class BuildDrillRemediationPlaybookAction
{
    /**
     * @param  array<string,mixed>  $trend
     * @return array{
     *   signature:string,
     *   severity:'info'|'warn'|'critical',
     *   title:string,
     *   summary:string,
     *   recommended_commands:list<string>,
     *   steps:list<string>,
     *   evidence:array<string,mixed>
     * }
     */
    public function execute(
        ?BackupDrillRun $latestRun,
        int $windowDays,
        int $total,
        int $passing,
        float $minimumPassRatePercent,
        int $maxAgeDays,
        array $trend,
    ): array {
        $passRatePercent = $total > 0 ? round(($passing / $total) * 100, 1) : 0.0;
        $latestResult = $latestRun instanceof BackupDrillRun ? strtolower((string) $latestRun->overall_result) : null;
        $latestRunUuid = $latestRun?->run_uuid;
        $latestAgeDays = $latestRun instanceof BackupDrillRun
            ? max(0, (int) ceil($latestRun->executed_at->diffInHours(now()) / 24))
            : null;
        $isStale = $latestRun instanceof BackupDrillRun
            && $latestRun->executed_at->lt(now()->subDays($maxAgeDays));
        $trendStatus = is_string($trend['status'] ?? null) ? (string) $trend['status'] : 'insufficient_data';
        $trendTrajectory = is_string($trend['trajectory'] ?? null) ? (string) $trend['trajectory'] : 'insufficient_data';

        $evidence = [
            'window_days' => $windowDays,
            'total' => $total,
            'passing' => $passing,
            'pass_rate_percent' => $passRatePercent,
            'minimum_pass_rate_percent' => $minimumPassRatePercent,
            'latest_result' => $latestResult,
            'latest_run_uuid' => $latestRunUuid,
            'latest_age_days' => $latestAgeDays,
            'max_age_days' => $maxAgeDays,
            'trend_status' => $trendStatus,
            'trend_trajectory' => $trendTrajectory,
        ];

        if (! $latestRun instanceof BackupDrillRun) {
            return $this->playbook(
                signature: 'drill.missing_run',
                severity: 'critical',
                title: 'No backup drill evidence available',
                summary: 'No backup drill run is recorded. Schedule and record a drill run before relying on restore readiness.',
                recommendedCommands: [
                    'db-ops:enqueue-drill',
                    'db-ops:record-drill --run-uuid=<uuid> --overall-result=pass --executed-at="<iso-8601>"',
                ],
                steps: [
                    'Queue and execute a backup drill immediately.',
                    'Capture marker, RTO, and RPO outcomes in the drill record.',
                    sprintf('Keep at least one successful drill within the last %d days.', $maxAgeDays),
                ],
                evidence: $evidence,
            );
        }

        if ($isStale) {
            return $this->playbook(
                signature: 'drill.stale_run',
                severity: 'critical',
                title: 'Backup drill evidence is stale',
                summary: sprintf('Latest drill run %s is %d day(s) old and exceeds the %d-day freshness target.', (string) $latestRunUuid, (int) $latestAgeDays, $maxAgeDays),
                recommendedCommands: [
                    'db-ops:enqueue-drill',
                    'db-ops:doctor --format=json',
                ],
                steps: [
                    'Run a fresh backup drill and confirm it completes.',
                    'Validate the latest drill freshness and pass-rate checks in doctor/report output.',
                    'Keep drill scheduling enabled to prevent future freshness gaps.',
                ],
                evidence: $evidence,
            );
        }

        if ($trendStatus === 'degrading' || $trendTrajectory === 'degrading') {
            return $this->playbook(
                signature: 'drill.degrading_trend',
                severity: 'warn',
                title: 'Backup drill trend is degrading',
                summary: 'Recent drill outcomes show a degrading trajectory. Investigate repeated failure patterns before the next restore event.',
                recommendedCommands: [
                    'db-ops:report --format=json',
                    'db-ops:doctor --agent',
                ],
                steps: [
                    'Inspect the latest failing drill outcomes and identify recurring failure stages.',
                    'Fix underlying restore path issues and rerun drills until trend stabilizes.',
                    'Track trend status in status/report surfaces after each remediation run.',
                ],
                evidence: $evidence,
            );
        }

        if ($passRatePercent < $minimumPassRatePercent) {
            return $this->playbook(
                signature: 'drill.pass_rate_below_threshold',
                severity: 'warn',
                title: 'Backup drill pass rate is below threshold',
                summary: sprintf('Drill pass rate is %.1f%% in the last %d day(s), below the configured %.1f%% threshold.', $passRatePercent, $windowDays, $minimumPassRatePercent),
                recommendedCommands: [
                    'db-ops:enqueue-drill',
                    'db-ops:status --summary --format=json',
                ],
                steps: [
                    'Run a new drill and validate marker, RTO, and RPO checks.',
                    'Prioritize fixes for the most common failing check dimensions.',
                    'Increase successful drill frequency until pass-rate SLO is restored.',
                ],
                evidence: $evidence,
            );
        }

        if ($latestResult === 'fail') {
            return $this->playbook(
                signature: 'drill.latest_failed',
                severity: 'warn',
                title: 'Latest backup drill failed',
                summary: 'The most recent drill run failed even though aggregate trend and pass-rate are currently acceptable.',
                recommendedCommands: [
                    'db-ops:report --format=json',
                ],
                steps: [
                    'Review the latest drill failure reason and remediation notes.',
                    'Fix the immediate issue and rerun a drill to confirm recovery.',
                ],
                evidence: $evidence,
            );
        }

        return $this->playbook(
            signature: 'drill.healthy',
            severity: 'info',
            title: 'Backup drill posture is healthy',
            summary: 'Drill freshness, trend, and pass-rate are within configured expectations.',
            recommendedCommands: [
                'db-ops:status --summary',
            ],
            steps: [
                'Keep drill automation enabled and monitor trend and pass-rate fields.',
            ],
            evidence: $evidence,
        );
    }

    /**
     * @param  list<string>  $recommendedCommands
     * @param  list<string>  $steps
     * @param  array<string,mixed>  $evidence
     * @return array{
     *   signature:string,
     *   severity:'info'|'warn'|'critical',
     *   title:string,
     *   summary:string,
     *   recommended_commands:list<string>,
     *   steps:list<string>,
     *   evidence:array<string,mixed>
     * }
     */
    private function playbook(
        string $signature,
        string $severity,
        string $title,
        string $summary,
        array $recommendedCommands,
        array $steps,
        array $evidence,
    ): array {
        return [
            'signature' => $signature,
            'severity' => $severity,
            'title' => $title,
            'summary' => $summary,
            'recommended_commands' => array_values(array_unique($recommendedCommands)),
            'steps' => array_values(array_unique($steps)),
            'evidence' => $evidence,
        ];
    }
}
