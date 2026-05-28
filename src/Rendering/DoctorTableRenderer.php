<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Rendering;

use AdityaaCodes\LaravelCheckpoint\Rendering\Concerns\FormatsHealthData;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;

/** @internal */
final readonly class DoctorTableRenderer
{
    use FormatsHealthData;

    public function __construct(
        private Repository $config,
    ) {}

    /**
     * @param  list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>  $checks
     */
    public function renderHealthTable(Command $command, array $checks, bool $briefMode): void
    {
        $checks = $this->withSeverity($checks);

        if (! $briefMode) {
            $this->renderHealthFullTable($command, $checks);

            return;
        }

        $this->renderHealthTriage($command, $checks);
    }

    /**
     * @param  list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>  $checks
     */
    private function renderHealthFullTable(Command $command, array $checks): void
    {
        $orderedChecks = $this->orderedChecksForDisplay($checks);
        $visibleChecks = $this->shouldCollapsePassingChecks($command)
            ? collect($orderedChecks)->filter(static fn (array $check): bool => $check['status'] !== 'pass')->values()->all()
            : $orderedChecks;

        $command->table(['Check', 'Status', 'Priority', 'Severity', 'Notes'], collect($visibleChecks)->map(fn (array $check): array => [
            $check['check'],
            $this->statusWord($check['status']),
            $this->priorityLabel((string) ($check['status'] ?? 'pass')),
            Str::upper((string) $check['severity']),
            $check['notes'],
        ])->all());

        if ($this->shouldCollapsePassingChecks($command)) {
            $suppressedPassChecks = count(collect($orderedChecks)->filter(static fn (array $check): bool => $check['status'] === 'pass')->all());

            if ($suppressedPassChecks > 0) {
                $command->line(sprintf('Suppressed %d passing checks (P2/P3). Re-run with -v for full detail.', $suppressedPassChecks));
            }
        }
    }

    /**
     * @param  list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>  $checks
     */
    private function renderHealthTriage(Command $command, array $checks): void
    {
        $totals = $this->severityTotals($checks);
        $topIssues = $this->topIssues($checks, 3);
        $suggestions = $this->suggestionsFromChecksForTriage($checks);
        $priorityCounts = $this->priorityCounts($checks);
        $suppressedPassChecks = count(collect($checks)->filter(static fn (array $check): bool => $check['status'] === 'pass')->all());

        $command->line('Doctor triage (brief)');
        $command->line(sprintf(
            'Blockers: %d | Warnings: %d | Info: %d',
            $totals['blocker'],
            $totals['warning'],
            $totals['info'],
        ));
        $command->line(sprintf('P0: %d | P1: %d | Suppressed lower-priority: %d', $priorityCounts['p0'], $priorityCounts['p1'], $suppressedPassChecks));
        $command->line(sprintf('Actionable warnings: %d', $this->effectiveWarningCount($checks)));

        if ($topIssues !== []) {
            $issue = $topIssues[0];
            $command->line(sprintf('Top issue: [%s] %s — %s', Str::upper((string) $issue['severity']), $issue['check'], $issue['notes']));
        }

        $command->line('Action now: '.($suggestions[0] ?? 'Run checkpoint:status --health --format=json for full issue details.'));
        $command->line('Deep dive: php artisan checkpoint:status --health --format=json');
    }

    /**
     * @param  list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>  $checks
     * @return list<string>
     */
    private function suggestionsFromChecksForTriage(array $checks): array
    {
        $suggestions = [];

        foreach ($checks as $check) {
            if ($check['status'] === 'pass') {
                continue;
            }

            $code = $check['code'];

            $suggestions[] = match (true) {
                $code === 'queue.orphaned_runs' => 'checkpoint:recover-orphans',
                Str::startsWith($code, 'backup_drill.') => 'checkpoint:drill',
                $code === 'backup.last_known_good' => 'Queue a backup',
                $code === 'backup.duration_anomaly' => 'Inspect backup duration history',
                $code === 'restore.post_verification' => 'Inspect restore verification results',
                $code === 'verification.runs' => 'Inspect verification runs',
                $code === 'restore.posture.environments' => 'Review restore policy',
                $code === 'restore.posture.databases' => 'Review database posture',
                $code === 'restore.posture.ci_bypass' => 'Review CI configuration',
                $code === 'restore.posture.verified_backup' => 'Enable verified backups',
                $code === 'config.backup_command' => 'Set backup command in config',
                Str::startsWith($code, 'driver.binary.') => sprintf('Install %s', str($code)->after('driver.binary.')->replace('.', ' ')->toString()),
                Str::startsWith($code, 'binary.') => sprintf('Install %s', str($code)->after('binary.')->replace('.', ' ')->toString()),
                default => 'Review check output',
            };

            if (Str::startsWith($code, 'backup_drill.')) {
                $playbookCommands = $check['data']['recommended_commands'] ?? null;

                if (is_array($playbookCommands)) {
                    foreach ($playbookCommands as $commandStr) {
                        if (is_string($commandStr) && Str::trim($commandStr) !== '') {
                            $suggestions[] = $commandStr;
                        }
                    }
                }
            }
        }

        return collect($suggestions)->unique()->values()->slice(0, 5)->all();
    }

    /**
     * @param  list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>  $checks
     * @return array{p0:int,p1:int}
     */
    private function priorityCounts(array $checks): array
    {
        return [
            'p0' => count(collect($checks)->filter(static fn (array $check): bool => $check['status'] === 'fail')->all()),
            'p1' => count(collect($checks)->filter(static fn (array $check): bool => $check['status'] === 'warn')->all()),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function renderPitrTableOutput(Command $command, array $payload): void
    {
        $command->table(['Field', 'Value'], [
            ['Driver', (string) $this->config->get('checkpoint.driver')],
            ['Target', $payload['target'] ?? '-'],
            ['Readiness', $payload['readiness']],
            ['Pass checks', (string) $payload['summary']['pass']],
            ['Fail checks', (string) $payload['summary']['fail']],
        ]);

        $checks = $payload['checks'];
        $command->table(
            ['Check', 'Status', 'Message'],
            collect($checks)->map(static fn (array $check): array => [
                $check['code'],
                $check['status'],
                $check['message'],
            ])->values()->all(),
        );
    }

    /**
     * @param  array{recent_runs:list<array<string,mixed>>,summary:array<string,mixed>,breakdown:array<string,mixed>,verification:array<string,mixed>,health:array{ok:bool,checks:list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>}}  $reportPayload
     */
    public function renderReportTableReport(Command $command, array $reportPayload, int $requestedLimit, int $effectiveLimit, bool $briefMode): void
    {
        if ($briefMode) {
            $this->renderReportBriefTableReport($command, $reportPayload);

            return;
        }

        $this->renderReportFullTableReport($command, $reportPayload, $requestedLimit, $effectiveLimit);
    }

    /**
     * @param  array{summary:array<string,mixed>,health:array{ok:bool,checks:list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>}}  $reportPayload
     */
    private function renderReportBriefTableReport(Command $command, array $reportPayload): void
    {
        $lastFailedRun = is_array($reportPayload['summary']['latest_failed_run'] ?? null)
            ? $reportPayload['summary']['latest_failed_run']
            : [];

        $failedChecks = count(collect($reportPayload['health']['checks'])->filter(static fn (array $check): bool => $check['status'] === 'fail')->all());
        $warnChecks = count(collect($reportPayload['health']['checks'])->filter(static fn (array $check): bool => $check['status'] === 'warn')->all());
        $suppressedLowerPriority = count(collect($reportPayload['health']['checks'])->filter(static fn (array $check): bool => $check['status'] === 'pass')->all());
        $reason = (string) ($lastFailedRun['failure_reason'] ?? 'No recent failed run reason available.');
        $actionNow = (string) ($lastFailedRun['next_action'] ?? 'Run php artisan checkpoint:status --full --limit=10 --format=json for full failure context.');

        $command->line('Checkpoint report (brief)');
        $command->line(sprintf(
            'Failed runs (24h): %d | Health checks: %d fail, %d warn',
            (int) ($reportPayload['summary']['failed_runs_24h'] ?? 0),
            $failedChecks,
            $warnChecks,
        ));
        $command->line(sprintf('P0: %d | P1: %d | Suppressed lower-priority: %d', $failedChecks, $warnChecks, $suppressedLowerPriority));
        $command->line('Last failed: '.($lastFailedRun['label'] ?? '-'));
        $command->line('Cause: '.$reason);
        $command->line('Action now: '.$actionNow);
        $command->line('Deep dive: php artisan checkpoint:status --full --limit=10 --format=json');
    }

    /**
     * @param  array{recent_runs:list<array<string,mixed>>,summary:array<string,mixed>,verification:array<string,mixed>,health:array{ok:bool,checks:list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>}}  $reportPayload
     */
    private function renderReportFullTableReport(Command $command, array $reportPayload, int $requestedLimit, int $effectiveLimit): void
    {
        $this->renderReportSummaryTable($command, $reportPayload, $requestedLimit, $effectiveLimit);
        $this->renderReportRecentRunsTable($command, $reportPayload);
        $this->renderReportHealthChecksTable($command, $reportPayload);
    }

    /**
     * @param  array{recent_runs:list<array<string,mixed>>,summary:array<string,mixed>,verification:array<string,mixed>}  $reportPayload
     */
    private function renderReportSummaryTable(Command $command, array $reportPayload, int $requestedLimit, int $effectiveLimit): void
    {
        $command->table(['Field', 'Value'], [
            ['Driver', (string) $this->config->get('checkpoint.driver')],
            ['Limit requested', (string) $requestedLimit],
            ['Limit applied', (string) $effectiveLimit],
            ['Recent runs returned', (string) count($reportPayload['recent_runs'])],
            ['Health OK', $reportPayload['health']['ok'] ? 'yes' : 'no'],
            ['Pending runs', (string) ($reportPayload['summary']['pending_runs'] ?? 0)],
            ['Running runs', (string) ($reportPayload['summary']['running_runs'] ?? 0)],
            ['Failed runs (24h)', (string) ($reportPayload['summary']['failed_runs_24h'] ?? 0)],
            ['Last known good backup', (string) ($reportPayload['summary']['last_known_good_backup']['label'] ?? '-')],
            ['Latest verified backup', (string) ($reportPayload['summary']['latest_verified_backup']['label'] ?? '-')],
            ['Latest backup drill', (string) ($reportPayload['summary']['latest_backup_drill']['label'] ?? '-')],
            ['Latest failed drill', (string) ($reportPayload['summary']['latest_failed_backup_drill']['label'] ?? '-')],
            ['Drill remediation playbook', (string) ($reportPayload['summary']['backup_drill_remediation_playbook']['title'] ?? '-')],
            ['Latest restore run', (string) ($reportPayload['summary']['latest_restore_run']['label'] ?? '-')],
            ['Latest restore failure', (string) ($reportPayload['summary']['latest_restore_failure']['label'] ?? '-')],
            ['Latest restore post-verification', (string) ($reportPayload['summary']['latest_restore_run']['post_restore_verification']['aggregate_result'] ?? '-')],
            ['Verification runs', (string) ($reportPayload['verification']['total_runs'] ?? 0)],
            ['Verification failed', (string) ($reportPayload['verification']['failed_runs'] ?? 0)],
            ['Verification health', (string) ($reportPayload['verification']['health_status'] ?? 'warn')],
        ]);
    }

    /**
     * @param  array{recent_runs:list<array<string,mixed>>}  $reportPayload
     */
    private function renderReportRecentRunsTable(Command $command, array $reportPayload): void
    {
        $recentRuns = $reportPayload['recent_runs'];

        if ($recentRuns === []) {
            return;
        }

        $command->table(['ID', 'Operation', 'Status', 'Exit', 'Backup', 'Verify', 'Started', 'Finished'], collect($recentRuns)->map(static fn (array $run): array => [
            (string) ($run['id'] ?? '-'),
            (string) ($run['operation'] ?? '-'),
            (string) ($run['status'] ?? '-'),
            $run['exit_code'] !== null ? (string) $run['exit_code'] : '-',
            (string) ($run['backup'] ?? '-'),
            (string) ($run['verification_state'] ?? '-'),
            (string) ($run['started_at'] ?? '-'),
            (string) ($run['finished_at'] ?? '-'),
        ])->all());
    }

    /**
     * @param  array{health:array{ok:bool,checks:list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>}}  $reportPayload
     */
    private function renderReportHealthChecksTable(Command $command, array $reportPayload): void
    {
        $orderedChecks = $this->orderedChecksForDisplay($reportPayload['health']['checks']);
        $visibleChecks = $this->shouldCollapsePassingChecks($command)
            ? collect($orderedChecks)->filter(static fn (array $check): bool => (string) ($check['status'] ?? '') !== 'pass')->values()->all()
            : $orderedChecks;

        $command->table(['Check', 'Status', 'Priority', 'Notes'], collect($visibleChecks)->map(fn (array $check): array => [
            (string) ($check['check'] ?? '-'),
            (string) ($check['status'] ?? '-'),
            $this->priorityLabel((string) ($check['status'] ?? 'pass')),
            (string) ($check['notes'] ?? '-'),
        ])->all());

        if ($this->shouldCollapsePassingChecks($command)) {
            $suppressedPassChecks = count(collect($orderedChecks)->filter(static fn (array $check): bool => (string) ($check['status'] ?? '') === 'pass')->all());

            if ($suppressedPassChecks > 0) {
                $command->line(sprintf('Suppressed %d passing checks (P2/P3). Re-run with -v for full detail.', $suppressedPassChecks));
            }
        }
    }

    private function shouldCollapsePassingChecks(Command $command): bool
    {
        return ! $command->getOutput()->isVerbose();
    }

    private function statusWord(string $level): string
    {
        $key = match ($level) {
            'pass' => 'messages.cli.doctor_pass',
            'warn' => 'messages.cli.doctor_warn',
            default => 'messages.cli.doctor_fail',
        };

        $value = __($key);

        if ($value !== $key) {
            return $value;
        }

        return match ($level) {
            'pass' => 'PASS',
            'warn' => 'WARN',
            default => 'FAIL',
        };
    }

    private function priorityLabel(string $status): string
    {
        return match ($status) {
            'fail' => 'P0',
            'warn' => 'P1',
            default => 'P3',
        };
    }
}
