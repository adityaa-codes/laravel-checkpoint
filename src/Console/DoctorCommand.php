<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Services\ConfigValidator;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\ExecutableFinder;

class DoctorCommand extends Command
{
    protected $signature = 'db-ops:doctor';

    protected $description = 'Show checkpoint package health checks.';

    public function handle(ConfigValidator $validator): int
    {
        $rows = [];

        try {
            $validator->validate();
        } catch (\Throwable $exception) {
            $rows[] = ['Config validation', $this->statusWord('fail'), $exception->getMessage()];
            $this->table(['Check', 'Status', 'Notes'], $rows);

            return self::FAILURE;
        }

        $rows[] = ['Config: driver', $this->statusWord('pass'), (string) config('checkpoint.driver')];
        $rows[] = ['Config: queue.name', $this->statusWord('pass'), (string) config('checkpoint.queue.name', 'db-ops')];
        $rows[] = ['Config: log_channel', $this->statusWord('pass'), (string) config('checkpoint.log_channel', 'stack')];
        $rows[] = $this->binaryRow('pg_dump');
        $rows[] = $this->binaryRow('pgbackrest');
        $rows[] = $this->binaryRow('gzip');
        $rows[] = $this->tableRow('command_runs', (new CommandRun)->getTable());
        $rows[] = $this->tableRow('backup_drill_runs', (new BackupDrillRun)->getTable());
        $rows[] = ['Queue: '.config('checkpoint.queue.name', 'db-ops'), $this->statusWord('warn'), 'Cannot verify queue without running worker'];
        $rows[] = ['Orphaned runs', $this->statusWord('pass'), sprintf('%d pending runs beyond threshold', $this->orphanedRunsCount())];

        $this->table(['Check', 'Status', 'Notes'], $rows);

        return self::SUCCESS;
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function binaryRow(string $binary): array
    {
        $path = (new ExecutableFinder)->find($binary);

        if ($path === null) {
            return ['Binary: '.$binary, $this->statusWord('warn'), 'Not found on PATH'];
        }

        return ['Binary: '.$binary, $this->statusWord('pass'), $path];
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function tableRow(string $label, string $table): array
    {
        if (! Schema::hasTable($table)) {
            return ['DB: '.$label.' table', $this->statusWord('fail'), 'Table not found'];
        }

        try {
            $count = DB::table($table)->count();
        } catch (QueryException) {
            $count = 0;
        }

        return ['DB: '.$label.' table', $this->statusWord('pass'), sprintf('%d rows', $count)];
    }

    private function orphanedRunsCount(): int
    {
        $thresholdMinutes = max(1, (int) config('checkpoint.queue.orphan_threshold', 10));

        return CommandRun::query()
            ->pending()
            ->where('created_at', '<', now()->subMinutes($thresholdMinutes))
            ->count();
    }

    private function statusWord(string $level): string
    {
        return match ($level) {
            'pass' => (string) __('messages.cli.doctor_pass'),
            'warn' => (string) __('messages.cli.doctor_warn'),
            default => (string) __('messages.cli.doctor_fail'),
        };
    }
}
