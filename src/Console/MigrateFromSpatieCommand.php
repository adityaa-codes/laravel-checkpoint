<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

final class MigrateFromSpatieCommand extends CheckpointCommand
{
    protected $signature = 'checkpoint:migrate-from-spatie
        {--dry-run : Preview the migration plan without making changes.}
        {--force : Skip all confirmation prompts.}
        {--remove-spatie : Also remove spatie/laravel-backup from composer.json.}';

    protected $description = 'Migrate from spatie/laravel-backup to Laravel Checkpoint with interactive guidance.';

    /**
     * @var array<string, string>
     */
    private const array COMMAND_MAP = [
        'backup:run' => 'checkpoint:backup',
        'backup:clean' => 'checkpoint:prune',
        'backup:monitor' => 'checkpoint:status --health --format=json',
        'backup:list' => 'checkpoint:status',
    ];

    /**
     * @var array<string, array{config_path:string, default:string, label:string}>
     */
    private const array ENV_MAP = [
        'database_driver' => ['config_path' => 'checkpoint.driver', 'default' => '', 'label' => 'Checkpoint driver'],
        'backup_disk' => ['config_path' => 'checkpoint.output.filesystem.disk', 'default' => '', 'label' => 'Output filesystem disk'],
        'backup_dir' => ['config_path' => 'checkpoint.drivers.shell.backup_dir', 'default' => '', 'label' => 'Backup destination directory'],
        'schedule_time' => ['config_path' => 'checkpoint.schedule.logical_backup_daily_at', 'default' => '', 'label' => 'Scheduled backup time'],
        'retention_default_days' => ['config_path' => 'checkpoint.retention_days', 'default' => '', 'label' => 'Retention days'],
        'retention_hot_days' => ['config_path' => 'checkpoint.retention_days', 'default' => '', 'label' => 'Retention days (hot)'],
    ];

    /**
     * @var array<string, string>
     */
    private const array GAP_NOTES = [
        'file_backup' => 'Spatie backs up application files; checkpoint is database-only. Preserve your file backup strategy separately.',
        'encryption' => 'Spatie offers ZIP/AES encryption on archives. Checkpoint relies on driver-level encryption (pgBackRest cipher, filesystem encryption, etc.).',
        'multiple_disks' => 'Spatie supports multiple filesystem destination disks. Checkpoint targets one output disk per driver configuration.',
        'health_checks' => 'Spatie monitors backup age and size. Checkpoint monitors age, anomaly duration, drill pass rate, and orphan recovery.',
    ];

    public function handle(): int
    {
        try {
            $isDryRun = (bool) $this->option('dry-run');
            $isForced = (bool) $this->option('force');

            if (! $this->spatieIsInstalled()) {
                $this->promptWarning(
                    'spatie/laravel-backup does not appear to be installed. This command maps configuration '
                    .'from an existing Spatie installation. Proceeding in advisory mode.'
                );
                $this->newLine();
            }

            $spatieConfig = $this->readSpatieConfig();
            $mapped = $this->mapConfiguration($spatieConfig);

            $this->renderCommandMap();
            $this->renderInteractiveIntroAndPlan($spatieConfig, $isDryRun);
            $this->handleMigrationDecision($mapped, $isDryRun, $isForced);

            if ($this->option('remove-spatie') && ! $isDryRun) {
                $this->removeSpatiePackage($isForced);
            }

            $this->renderGapNotes();

            if ($this->enhancedInteractiveMode()) {
                outro('Migration plan ready.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);

            foreach (Str::of($exception->getMessage())->split('/\\r\\n|\\r|\\n/')->all() ?: [] as $line) {
                if (Str::trim($line) !== '') {
                    $this->promptError($line);
                }
            }

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, mixed>  $spatieConfig
     */
    private function renderInteractiveIntroAndPlan(array $spatieConfig, bool $isDryRun): void
    {
        if ($this->enhancedInteractiveMode()) {
            intro('Spatie → Laravel Checkpoint Migration');
        }

        if (! $isDryRun && $this->enhancedInteractiveMode()) {
            $steps = $this->buildMigrationPlan($spatieConfig);
            $this->renderMigrationPlan($steps);
            $this->newLine();
        }
    }

    /**
     * @param  array<string, array{config_path:string, value:string, label:string}>  $mapped
     */
    private function handleMigrationDecision(array $mapped, bool $isDryRun, bool $isForced): void
    {
        if (! $isDryRun && $isForced) {
            $this->displayMigrationPlan($mapped);
        } elseif (! $isDryRun) {
            if (confirm(label: 'Apply these migration changes?', default: true)) {
                $this->displayMigrationPlan($mapped);
            } else {
                note('Migration cancelled. Run with --dry-run to preview the plan at any time.');
            }
        }
    }

    private function spatieIsInstalled(): bool
    {
        $composerPath = base_path('composer.json');

        if (! File::exists($composerPath)) {
            return false;
        }

        $composer = json_decode(File::get($composerPath), true);

        if (! is_array($composer)) {
            return false;
        }

        $packages = collect($composer['require'] ?? [])->keys()->merge(collect($composer['require-dev'] ?? [])->keys())->all();

        return collect($packages)->containsStrict('spatie/laravel-backup');
    }

    /**
     * @return array<string, mixed>
     */
    private function readSpatieConfig(): array
    {
        $path = config_path('backup.php');

        if (! File::exists($path)) {
            return [];
        }

        $config = require $path;

        return is_array($config) ? $config : [];
    }

    /**
     * @param  array<string, mixed>  $spatieConfig
     * @return array<string, array{config_path:string, value:string, label:string}>
     */
    private function mapConfiguration(array $spatieConfig): array
    {
        $mapped = [];

        $mapped['database_driver'] = $this->mapDatabaseDriverConfig();

        foreach ($this->mapDiskConfigs($spatieConfig) as $key => $value) {
            $mapped[$key] = $value;
        }

        foreach ($this->mapRetentionConfig($spatieConfig) as $key => $value) {
            $mapped[$key] = $value;
        }

        return $mapped;
    }

    /**
     * @return array{config_path:string, value:string, label:string}
     */
    private function mapDatabaseDriverConfig(): array
    {
        $databaseDriver = $this->detectDatabaseDriver() ?: 'mysql';

        return [
            'config_path' => 'checkpoint.driver',
            'value' => $databaseDriver,
            'label' => self::ENV_MAP['database_driver']['label'],
        ];
    }

    /**
     * @param  array<string, mixed>  $spatieConfig
     * @return array<string, array{config_path:string, value:string, label:string}>
     */
    private function mapDiskConfigs(array $spatieConfig): array
    {
        $configs = [];

        $disks = $spatieConfig['backup']['destination']['disks'] ?? [];
        $firstDisk = is_array($disks) && $disks !== [] ? (string) reset($disks) : '';
        if ($firstDisk !== '') {
            $configs['backup_disk'] = [
                'config_path' => 'checkpoint.output.filesystem.disk',
                'value' => $firstDisk,
                'label' => self::ENV_MAP['backup_disk']['label'],
            ];
        }

        $backupFileNamePrefix = $spatieConfig['backup']['destination']['filename_prefix'] ?? '';
        if (is_string($backupFileNamePrefix) && $backupFileNamePrefix !== '') {
            $configs['backup_prefix'] = [
                'config_path' => 'checkpoint.drivers.shell.backup_prefix',
                'value' => $backupFileNamePrefix,
                'label' => 'Backup file prefix',
            ];
        }

        return $configs;
    }

    /**
     * @param  array<string, mixed>  $spatieConfig
     * @return array<string, array{config_path:string, value:string, label:string}>
     */
    private function mapRetentionConfig(array $spatieConfig): array
    {
        $configs = [];

        $retentionDays = $spatieConfig['cleanup']['default_strategy']['keep_all_backups_for_days']
            ?? $spatieConfig['backup']['cleanup']['default_strategy']['keep_all_backups_for_days']
            ?? null;

        if (is_int($retentionDays) && $retentionDays > 0) {
            $configs['retention_hot_days'] = [
                'config_path' => 'checkpoint.retention_days',
                'value' => (string) $retentionDays,
                'label' => self::ENV_MAP['retention_hot_days']['label'],
            ];
        }

        $longestRetention = max(
            (int) ($spatieConfig['cleanup']['default_strategy']['keep_all_backups_for_days'] ?? 0),
            (int) ($spatieConfig['cleanup']['default_strategy']['keep_daily_backups_for_days'] ?? 0),
            (int) ($spatieConfig['cleanup']['default_strategy']['keep_weekly_backups_for_weeks'] ?? 0) * 7,
            (int) ($spatieConfig['cleanup']['default_strategy']['keep_monthly_backups_for_months'] ?? 0) * 30,
            (int) ($spatieConfig['cleanup']['default_strategy']['keep_yearly_backups_for_years'] ?? 0) * 365,
            (int) ($spatieConfig['backup']['cleanup']['default_strategy']['keep_all_backups_for_days'] ?? 0),
            (int) ($spatieConfig['backup']['cleanup']['default_strategy']['keep_daily_backups_for_days'] ?? 0),
            (int) ($spatieConfig['backup']['cleanup']['default_strategy']['keep_weekly_backups_for_weeks'] ?? 0) * 7,
            (int) ($spatieConfig['backup']['cleanup']['default_strategy']['keep_monthly_backups_for_months'] ?? 0) * 30,
            (int) ($spatieConfig['backup']['cleanup']['default_strategy']['keep_yearly_backups_for_years'] ?? 0) * 365,
        );

        $tierKeys = [
            'keep_all_backups_for_days',
            'keep_daily_backups_for_days',
            'keep_weekly_backups_for_weeks',
            'keep_monthly_backups_for_months',
            'keep_yearly_backups_for_years',
        ];

        $activeTierCount = 0;

        foreach ($tierKeys as $tier) {
            $val = (int) ($spatieConfig['cleanup']['default_strategy'][$tier]
                ?? $spatieConfig['backup']['cleanup']['default_strategy'][$tier]
                ?? 0);

            if ($val > 0) {
                $activeTierCount++;
            }
        }

        if ($activeTierCount > 1) {
            warning(sprintf(
                'Spatie multi-tier retention (daily/weekly/monthly/yearly) has been collapsed to a single retention_days value (%d days). Review config/checkpoint.php after migration.',
                $longestRetention
            ));
        }

        if ($longestRetention > 0) {
            $configs['retention_default_days'] = [
                'config_path' => 'checkpoint.retention_days',
                'value' => (string) $longestRetention,
                'label' => self::ENV_MAP['retention_default_days']['label'],
            ];
        }

        return $configs;
    }

    private function detectDatabaseDriver(): string
    {
        $defaultConnection = (string) config('database.default', '');
        $driver = Str::lower(Str::trim((string) config('database.connections.'.$defaultConnection.'.driver', $defaultConnection)));

        return match ($driver) {
            'pgsql', 'postgres', 'postgresql' => 'postgres',
            'mysql', 'mariadb' => 'mysql',
            default => 'postgres',
        };
    }

    /**
     * @param  array<string, array{config_path:string, value:string, label:string}>  $mapped
     */
    private function displayMigrationPlan(array $mapped): void
    {
        $isDryRun = (bool) $this->option('dry-run');

        if ($isDryRun) {
            return;
        }

        $this->publishCheckpointConfig();

        if ($mapped !== []) {
            $this->line('');
            $this->line('Publish config and set these values in config/checkpoint.php:');
            $this->promptTable(['Config key', 'Value', 'Description'], collect($mapped)->map(static fn (array $item): array => [$item['config_path'], $item['value'], $item['label']])->all());
        }

        $this->promptInfo('Config published and migration plan applied. Review config/checkpoint.php.');
    }

    private function publishCheckpointConfig(): void
    {
        $force = (bool) $this->option('force');

        $code = Artisan::call('vendor:publish', [
            '--tag' => 'checkpoint-config',
            '--force' => $force,
        ]);

        if ($code !== self::SUCCESS) {
            $this->promptWarning('Could not publish checkpoint config. Run: php artisan vendor:publish --tag=checkpoint-config');
        }
    }

    private function removeSpatiePackage(bool $isForced): void
    {
        $composerPath = base_path('composer.json');

        if (! File::exists($composerPath)) {
            $this->promptWarning('composer.json not found. Cannot remove spatie/laravel-backup automatically.');

            return;
        }

        $composer = json_decode(File::get($composerPath), true);

        if (! is_array($composer)) {
            $this->promptWarning('Could not parse composer.json.');

            return;
        }

        $removed = false;

        foreach (['require', 'require-dev'] as $section) {
            if (isset($composer[$section]['spatie/laravel-backup'])) {
                unset($composer[$section]['spatie/laravel-backup']);
                $removed = true;
            }
        }

        if (! $removed) {
            return;
        }

        $encoded = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        File::put($composerPath, $encoded);

        $this->promptInfo('Removed spatie/laravel-backup from composer.json. Run composer update to complete removal.');

        $backupConfigPath = config_path('backup.php');

        if (File::exists($backupConfigPath) && ($isForced || confirm(label: 'Delete config/backup.php?', default: true))) {
            File::delete($backupConfigPath);
            $this->promptInfo('Deleted config/backup.php.');
        }
    }

    private function renderCommandMap(): void
    {
        $this->newLine();
        note('Artisan Command Mapping (spatie/laravel-backup → laravel-checkpoint):');

        $rows = [];
        foreach (self::COMMAND_MAP as $spatieCommand => $checkpointCommand) {
            $rows[] = [$spatieCommand, $checkpointCommand];
        }
        table(headers: ['Spatie Command', 'Checkpoint Replacement'], rows: $rows);
    }

    /**
     * @param  array<string, mixed>  $spatieConfig
     * @return list<array{step:string,action:string}>
     */
    private function buildMigrationPlan(array $spatieConfig): array
    {
        $steps = [];

        $steps[] = [
            'step' => '1. Publish config',
            'action' => 'Publish config/checkpoint.php via vendor:publish.',
        ];

        $dbDriver = $this->detectDatabaseDriver();
        $steps[] = [
            'step' => '2. Set driver',
            'action' => sprintf('Set checkpoint.driver = %s (detected from database.default).', $dbDriver),
        ];

        $disks = $spatieConfig['backup']['destination']['disks'] ?? [];
        if (is_array($disks) && $disks !== []) {
            $steps[] = [
                'step' => '3. Map storage disk',
                'action' => sprintf('Set checkpoint.output.filesystem.disk = %s (from Spatie destination disks).', Arr::join(collect($disks)->map(strval(...))->all(), ', ')),
            ];
        }

        $steps[] = [
            'step' => '4. Schedule migration',
            'action' => 'Replace $schedule->command(\'backup:run\') with $schedule->command(\'checkpoint:backup\').',
        ];

        $steps[] = [
            'step' => '5. Retention',
            'action' => 'Map Spatie cleanup strategy → Checkpoint retention tiers (hot/warm/cold).',
        ];

        return $steps;
    }

    /**
     * @param  list<array{step:string,action:string}>  $steps
     */
    private function renderMigrationPlan(array $steps): void
    {
        $this->newLine();
        note('Migration Plan:');

        foreach ($steps as $step) {
            info(sprintf('%s: %s', $step['step'], $step['action']));
        }
    }

    private function renderGapNotes(): void
    {
        $this->newLine();
        warning('Important differences to be aware of:');

        $gapRows = [];
        foreach (self::GAP_NOTES as $area => $note) {
            $gapRows[] = [str($area)->replace('_', ' ')->title()->toString(), $note];
        }

        table(headers: ['Area', 'Note'], rows: $gapRows);
    }
}
