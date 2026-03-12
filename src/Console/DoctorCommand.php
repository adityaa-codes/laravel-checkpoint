<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Services\ConfigValidator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Symfony\Component\Process\ExecutableFinder;

final class DoctorCommand extends Command
{
    protected $signature = 'db-ops:doctor {--format=table}';

    protected $description = 'Show checkpoint package health checks.';

    public function __construct(
        private readonly ConfigValidator $validator,
        private readonly Repository $config,
        private readonly DatabaseManager $database,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $rows = [];
        $format = (string) $this->option('format');
        $outputMode = in_array($format, ['table', 'json'], true) ? $format : 'table';

        try {
            $this->validator->validate();
        } catch (\Throwable $exception) {
            $rows[] = ['Config validation', $this->statusWord('fail'), $exception->getMessage()];

            if ($outputMode === 'json') {
                $this->line($this->jsonReport($rows, false));

                return self::FAILURE;
            }

            $this->table(['Check', 'Status', 'Notes'], $rows);

            return self::FAILURE;
        }

        $rows[] = ['Config: driver', $this->statusWord('pass'), (string) $this->config->get('checkpoint.driver')];
        $rows[] = ['Config: queue.name', $this->statusWord('pass'), (string) $this->config->get('checkpoint.queue.name', 'db-ops')];
        $rows[] = ['Config: log_channel', $this->statusWord('pass'), (string) $this->config->get('checkpoint.log_channel', 'stack')];
        $rows[] = ['Config: pgbackrest.stanza', $this->statusWord('pass'), (string) $this->config->get('checkpoint.drivers.pgbackrest.stanza', 'main')];
        $rows[] = ['Config: pgbackrest.repo', $this->statusWord('pass'), (string) $this->config->get('checkpoint.drivers.pgbackrest.repo', 1)];
        $rows[] = ['Config: pgbackrest.repositories', $this->statusWord('pass'), (string) count($this->pgBackRestRepositories())];
        $rows[] = ['Config: pgbackrest.process_max', $this->statusWord('pass'), (string) $this->config->get('checkpoint.drivers.pgbackrest.process_max', 1)];
        $rows[] = $this->selectedPgBackRestRepositoryRow();
        $rows[] = $this->selectedPgBackRestTargetRow();
        $rows[] = $this->selectedPgBackRestTlsRow();
        $rows[] = $this->selectedPgBackRestEncryptionRow();
        $rows[] = $this->binaryRow('pg_dump');
        $rows[] = $this->configuredBinaryRow(
            'pgBackRest',
            (string) $this->config->get('checkpoint.drivers.pgbackrest.binary', 'pgbackrest'),
            (string) $this->config->get('checkpoint.driver', '') === 'pgbackrest',
        );
        $rows[] = $this->binaryRow('gzip');
        $rows[] = $this->tableRow('command_runs', (new CommandRun)->getTable());
        $rows[] = $this->tableRow('backup_drill_runs', (new BackupDrillRun)->getTable());
        $rows[] = ['Queue: '.$this->config->get('checkpoint.queue.name', 'db-ops'), $this->statusWord('warn'), 'Cannot verify queue without running worker'];
        $rows[] = ['Orphaned runs', $this->statusWord('pass'), sprintf('%d pending runs beyond threshold', $this->orphanedRunsCount())];
        $rows[] = $this->lastKnownGoodRow();
        $rows[] = $this->backupDurationAnomalyRow();

        if ($outputMode === 'json') {
            $this->line($this->jsonReport($rows, true));

            return self::SUCCESS;
        }

        $this->table(['Check', 'Status', 'Notes'], $rows);

        return self::SUCCESS;
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function binaryRow(string $binary): array
    {
        return $this->configuredBinaryRow($binary, $binary, false);
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function configuredBinaryRow(string $label, string $binary, bool $required): array
    {
        $trimmedBinary = trim($binary);

        if ($trimmedBinary === '') {
            return ['Binary: '.$label, $this->statusWord($required ? 'fail' : 'warn'), 'Binary is empty'];
        }

        $path = (new ExecutableFinder)->find($trimmedBinary);

        if ($path === null) {
            return ['Binary: '.$label, $this->statusWord($required ? 'fail' : 'warn'), sprintf('%s not found on PATH', $trimmedBinary)];
        }

        return ['Binary: '.$label, $this->statusWord('pass'), $path];
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function tableRow(string $label, string $table): array
    {
        $connection = $this->database->connection();

        if (! $connection->getSchemaBuilder()->hasTable($table)) {
            return ['DB: '.$label.' table', $this->statusWord('fail'), 'Table not found'];
        }

        try {
            $count = $connection->table($table)->count();
        } catch (QueryException) {
            $count = 0;
        }

        return ['DB: '.$label.' table', $this->statusWord('pass'), sprintf('%d rows', $count)];
    }

    private function orphanedRunsCount(): int
    {
        $thresholdMinutes = max(1, (int) $this->config->get('checkpoint.queue.orphan_threshold', 10));

        return CommandRun::query()
            ->pending()
            ->where('created_at', '<', now()->subMinutes($thresholdMinutes))
            ->count();
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function lastKnownGoodRow(): array
    {
        $maxAgeHours = max(1, (int) $this->config->get('checkpoint.observability.max_last_known_good_age_hours', 24));
        $latest = CommandRun::query()
            ->whereNotNull('last_known_good_at')
            ->latest('last_known_good_at')
            ->first();

        if (! $latest instanceof CommandRun || $latest->last_known_good_at === null) {
            return ['Backups: last known good', $this->statusWord('warn'), 'No last-known-good backup recorded'];
        }

        $ageHours = max(0, (int) ceil(now()->diffInMinutes($latest->last_known_good_at) / 60));
        $level = $ageHours > $maxAgeHours ? 'warn' : 'pass';

        return [
            'Backups: last known good',
            $this->statusWord($level),
            sprintf('%d hours old (threshold: %d)', $ageHours, $maxAgeHours),
        ];
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function backupDurationAnomalyRow(): array
    {
        $minSamples = max(2, (int) $this->config->get('checkpoint.observability.backup_duration_min_samples', 3));
        $factor = max(1.1, (float) $this->config->get('checkpoint.observability.backup_duration_anomaly_factor', 2.0));
        $runs = CommandRun::query()
            ->whereNotNull('backup_type')
            ->whereNotNull('duration_seconds')
            ->where('status', 'succeeded')
            ->latest('id')
            ->limit($minSamples)
            ->get();

        if ($runs->count() < $minSamples) {
            return ['Backups: duration anomaly', $this->statusWord('warn'), 'Not enough successful backup samples'];
        }

        $latest = $runs->first();
        $baseline = $runs->slice(1)->pluck('duration_seconds')->filter()->sort()->values();

        if (! $latest instanceof CommandRun || $baseline->isEmpty()) {
            return ['Backups: duration anomaly', $this->statusWord('warn'), 'Not enough successful backup samples'];
        }

        $medianSeconds = (int) $baseline->get((int) floor(($baseline->count() - 1) / 2));
        $latestSeconds = (int) $latest->duration_seconds;
        $level = $latestSeconds > (int) ceil($medianSeconds * $factor) ? 'warn' : 'pass';

        return [
            'Backups: duration anomaly',
            $this->statusWord($level),
            sprintf('latest %ds vs median %ds (factor: %.1f)', $latestSeconds, $medianSeconds, $factor),
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function pgBackRestRepositories(): array
    {
        $repositories = $this->config->get('checkpoint.drivers.pgbackrest.repositories', []);

        return is_array($repositories) ? $repositories : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function selectedPgBackRestRepository(): array
    {
        $repoId = (int) $this->config->get('checkpoint.drivers.pgbackrest.repo', 1);
        $repositories = $this->pgBackRestRepositories();

        $repository = $repositories[$repoId] ?? [];

        return is_array($repository) ? $repository : [];
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function selectedPgBackRestRepositoryRow(): array
    {
        $repoId = (int) $this->config->get('checkpoint.drivers.pgbackrest.repo', 1);
        $type = (string) ($this->selectedPgBackRestRepository()['type'] ?? 'unknown');

        return ['Repo: pgbackrest.active', $this->statusWord('pass'), sprintf('repo%d (%s)', $repoId, $type)];
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function selectedPgBackRestTargetRow(): array
    {
        $repository = $this->selectedPgBackRestRepository();
        $type = (string) ($repository['type'] ?? 'unknown');

        if ($type === 's3') {
            $s3 = is_array($repository['s3'] ?? null) ? $repository['s3'] : [];

            return [
                'Repo: pgbackrest.target',
                $this->statusWord('pass'),
                sprintf('s3://%s via %s', (string) ($s3['bucket'] ?? '-'), (string) ($s3['endpoint'] ?? '-')),
            ];
        }

        return [
            'Repo: pgbackrest.target',
            $this->statusWord('pass'),
            (string) ($repository['path'] ?? '-'),
        ];
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function selectedPgBackRestTlsRow(): array
    {
        $tls = $this->selectedPgBackRestRepository()['tls'] ?? [];
        $tls = is_array($tls) ? $tls : [];
        $verify = (bool) ($tls['verify'] ?? true);
        $caFile = $tls['ca_file'] ?? null;
        $notes = $verify ? 'verify enabled' : 'verify disabled';

        if (is_string($caFile) && trim($caFile) !== '') {
            $notes .= sprintf(' (ca: %s)', $caFile);
        }

        return ['Repo: pgbackrest.tls', $this->statusWord($verify ? 'pass' : 'warn'), $notes];
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function selectedPgBackRestEncryptionRow(): array
    {
        $encryption = $this->selectedPgBackRestRepository()['encryption'] ?? [];
        $encryption = is_array($encryption) ? $encryption : [];
        $enabled = (bool) ($encryption['enabled'] ?? false);
        $cipherType = (string) ($encryption['cipher_type'] ?? 'unknown');
        $notes = $enabled ? sprintf('enabled (%s)', $cipherType) : 'disabled';

        return ['Repo: pgbackrest.encryption', $this->statusWord($enabled ? 'pass' : 'warn'), $notes];
    }

    private function statusWord(string $level): string
    {
        return match ($level) {
            'pass' => (string) __('messages.cli.doctor_pass'),
            'warn' => (string) __('messages.cli.doctor_warn'),
            default => (string) __('messages.cli.doctor_fail'),
        };
    }

    private function statusLevel(string $statusWord): string
    {
        return match ($statusWord) {
            (string) __('messages.cli.doctor_pass'), 'messages.cli.doctor_pass' => 'pass',
            (string) __('messages.cli.doctor_warn'), 'messages.cli.doctor_warn' => 'warn',
            default => 'fail',
        };
    }

    /**
     * @param  list<array{0:string,1:string,2:string}>  $rows
     */
    private function jsonReport(array $rows, bool $ok): string
    {
        $checks = array_map(function (array $row): array {
            return [
                'check' => $row[0],
                'status' => $this->statusLevel($row[1]),
                'notes' => $row[2],
            ];
        }, $rows);

        $report = [
            'ok' => $ok,
            'driver' => (string) $this->config->get('checkpoint.driver'),
            'generated_at' => now()->toIso8601String(),
            'checks' => $checks,
        ];

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '{"ok":false,"checks":[]}';
    }
}
