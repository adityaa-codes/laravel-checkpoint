<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Console\Concerns\UsesLaravelPrompts;
use AdityaaCodes\LaravelCheckpoint\Services\EnvFileManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

final class MigrateFromSpatieCommand extends Command
{
    use UsesLaravelPrompts;

    public function __construct(
        private readonly EnvFileManager $envFileManager,
    ) {
        parent::__construct();
    }

    protected $signature = 'checkpoint:migrate-from-spatie
        {--dry-run : Preview the migration plan without making changes.}
        {--force : Skip all confirmation prompts.}
        {--skip-config : Skip writing configuration values to .env.}
        {--skip-schedule : Skip updating scheduler entries.}
        {--skip-notifications : Skip mapping notifications configuration.}
        {--write-env : Persist mapped values into the environment file.}
        {--remove-spatie : Also remove spatie/laravel-backup from composer.json.}';

    protected $description = 'Migrate from spatie/laravel-backup to Laravel Checkpoint with interactive guidance.';

    /**
     * @var array<string, string>
     */
    private const array COMMAND_MAP = [
        'backup:run' => 'checkpoint:enqueue-backup',
        'backup:clean' => 'checkpoint:prune',
        'backup:monitor' => 'checkpoint:doctor --format=json',
        'backup:list' => 'checkpoint:status',
    ];

    /**
     * @var array<string, array{env_key:string, default:string, label:string}>
     */
    private const array ENV_MAP = [
        'database_driver' => ['env_key' => 'DB_OPS_DRIVER', 'default' => '', 'label' => 'Checkpoint driver'],
        'backup_disk' => ['env_key' => 'DB_OPS_OUTPUT_FILESYSTEM_DISK', 'default' => '', 'label' => 'Output filesystem disk'],
        'backup_dir' => ['env_key' => 'DB_OPS_BACKUP_DIR', 'default' => '', 'label' => 'Backup destination directory'],
        'schedule_time' => ['env_key' => 'DB_OPS_BACKUP_DAILY_AT', 'default' => '', 'label' => 'Scheduled backup time (UTC)'],
        'notification_mail_to' => ['env_key' => 'DB_OPS_NOTIFICATIONS_MAIL_TO', 'default' => '', 'label' => 'Notification mail recipients'],
        'retention_hot_days' => ['env_key' => 'DB_OPS_RETENTION_TIER_HOT_DAYS', 'default' => '', 'label' => 'Hot retention tier (days)'],
        'retention_default_days' => ['env_key' => 'DB_OPS_RETENTION_DEFAULT_DAYS', 'default' => '', 'label' => 'Default retention (days)'],
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

            if ($this->enhancedInteractiveMode()) {
                intro('Spatie → Laravel Checkpoint Migration');
                note('What: guided migration of backup configuration from spatie/laravel-backup to laravel-checkpoint.');
                note('When: you want to replace Spatie\'s backup package with checkpoint\'s db-ops workflow.');
                note('Scope: database backup, retention, health monitoring, and notifications.');
            }

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

            if (! $isDryRun && $isForced) {
                $this->applyMigration($mapped);
            } elseif (! $isDryRun) {
                if ($this->enhancedInteractiveMode()) {
                    $steps = $this->buildMigrationPlan($spatieConfig);
                    $this->renderMigrationPlan($steps);
                    $this->newLine();
                }

                if ($isForced || confirm(label: 'Apply these migration changes?', default: true)) {
                    $this->applyMigration($mapped);
                } else {
                    note('Migration cancelled. Run with --dry-run to preview the plan at any time.');
                }
            }

            $this->renderGapNotes();

            if ($this->enhancedInteractiveMode()) {
                $this->newLine();
                note('After migration:');
                note('1. Review config/checkpoint.php for driver-specific settings.');
                note('2. Run php artisan checkpoint:install to validate your setup.');
                note('3. Run php artisan checkpoint:enqueue-backup to test your first backup.');
                note('4. Start a queue worker: php artisan queue:work --queue=db-ops');
                outro('Migration plan ready.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);

            foreach (preg_split('/\r\n|\r|\n/', $exception->getMessage()) ?: [] as $line) {
                if (trim((string) $line) !== '') {
                    $this->promptError((string) $line);
                }
            }

            return self::FAILURE;
        }
    }

    private function spatieIsInstalled(): bool
    {
        $composerPath = base_path('composer.json');

        if (! file_exists($composerPath)) {
            return false;
        }

        $composer = json_decode((string) file_get_contents($composerPath), true);

        if (! is_array($composer)) {
            return false;
        }

        $packages = array_merge(
            array_keys($composer['require'] ?? []),
            array_keys($composer['require-dev'] ?? []),
        );

        return in_array('spatie/laravel-backup', $packages, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function readSpatieConfig(): array
    {
        $path = config_path('backup.php');

        if (! file_exists($path)) {
            return [];
        }

        $config = require $path;

        return is_array($config) ? $config : [];
    }

    /**
     * @param  array<string, mixed>  $spatieConfig
     * @return array<string, array{env_key:string, value:string, label:string}>
     */
    private function mapConfiguration(array $spatieConfig): array
    {
        $mapped = [];

        $databaseDriver = $this->detectDatabaseDriver() ?: 'shell';
        $mapped['database_driver'] = [
            'env_key' => 'DB_OPS_DRIVER',
            'value' => $databaseDriver,
            'label' => self::ENV_MAP['database_driver']['label'],
        ];

        $disks = $spatieConfig['backup']['destination']['disks'] ?? [];
        $firstDisk = is_array($disks) && $disks !== [] ? (string) reset($disks) : '';
        if ($firstDisk !== '') {
            $mapped['backup_disk'] = [
                'env_key' => 'DB_OPS_OUTPUT_FILESYSTEM_DISK',
                'value' => $firstDisk,
                'label' => self::ENV_MAP['backup_disk']['label'],
            ];
        }

        $backupFileNamePrefix = $spatieConfig['backup']['destination']['filename_prefix'] ?? '';
        if (is_string($backupFileNamePrefix) && $backupFileNamePrefix !== '') {
            $mapped['backup_prefix'] = [
                'env_key' => 'DB_OPS_BACKUP_PREFIX',
                'value' => $backupFileNamePrefix,
                'label' => 'Backup file prefix',
            ];
        }

        $mailTo = $spatieConfig['backup']['notifications']['notifications'][BackupHasFailedNotification::class]['to']
            ?? $spatieConfig['backup']['notifications']['mail']['to']
            ?? null;

        if (is_array($mailTo)) {
            $mapped['notification_mail_to'] = [
                'env_key' => 'DB_OPS_NOTIFICATIONS_MAIL_TO',
                'value' => implode(',', $mailTo),
                'label' => self::ENV_MAP['notification_mail_to']['label'],
            ];

            $mapped['notification_enabled'] = [
                'env_key' => 'DB_OPS_NOTIFICATIONS_ENABLED',
                'value' => 'true',
                'label' => 'Notifications enabled',
            ];
        }

        if (isset($spatieConfig['backup']['notifications'])) {
            $mapped['notification_enabled'] = [
                'env_key' => 'DB_OPS_NOTIFICATIONS_ENABLED',
                'value' => 'true',
                'label' => 'Notifications enabled',
            ];
        }

        $retentionDays = $spatieConfig['cleanup']['default_strategy']['keep_all_backups_for_days']
            ?? $spatieConfig['backup']['cleanup']['default_strategy']['keep_all_backups_for_days']
            ?? null;

        if (is_int($retentionDays) && $retentionDays > 0) {
            $mapped['retention_hot_days'] = [
                'env_key' => 'DB_OPS_RETENTION_TIER_HOT_DAYS',
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

        if ($longestRetention > 0) {
            $mapped['retention_default_days'] = [
                'env_key' => 'DB_OPS_RETENTION_DEFAULT_DAYS',
                'value' => (string) $longestRetention,
                'label' => self::ENV_MAP['retention_default_days']['label'],
            ];
        }

        return $mapped;
    }

    private function detectDatabaseDriver(): string
    {
        $defaultConnection = (string) config('database.default', '');
        $driver = strtolower(trim((string) config('database.connections.'.$defaultConnection.'.driver', $defaultConnection)));

        return match ($driver) {
            'pgsql', 'postgres', 'postgresql' => 'postgres',
            'mysql', 'mariadb' => 'mysql',
            'sqlite' => 'shell',
            default => 'shell',
        };
    }

    /**
     * @param  array<string, array{env_key:string, value:string, label:string}>  $mapped
     */
    private function applyMigration(array $mapped): void
    {
        $isDryRun = (bool) $this->option('dry-run');
        $skipConfig = (bool) $this->option('skip-config');
        $writeEnv = (bool) $this->option('write-env');
        $skipNotifications = (bool) $this->option('skip-notifications');
        $removeSpatie = (bool) $this->option('remove-spatie');

        if ($isDryRun) {
            return;
        }

        if (! $skipConfig && $mapped !== []) {
            if ($writeEnv || ($this->enhancedInteractiveMode() && confirm(label: 'Write mapped configuration to .env?', default: true))) {
                $entries = [];
                foreach ($mapped as $item) {
                    $entries[$item['env_key']] = $item['value'];
                }

                if (! $skipNotifications) {
                    $entries['DB_OPS_NOTIFICATIONS_ENABLED'] = 'true';
                }

                $this->writeEnvEntries($entries);
            }
        }

        $this->publishCheckpointConfig();

        if ($removeSpatie) {
            $this->removeSpatiePackage();
        }

        $this->promptInfo('Migration applied. Run php artisan checkpoint:install to validate.');
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function writeEnvEntries(array $entries): void
    {
        $path = app()->environmentFilePath();

        if (! file_exists($path)) {
            throw new RuntimeException(sprintf('Environment file [%s] does not exist.', $path));
        }

        $contents = (string) file_get_contents($path);
        $contents = $this->envFileManager->writeEntries($contents, $entries);
        file_put_contents($path, $contents);

        $this->promptInfo('.env updated with '.count($entries).' value(s).');
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

    private function removeSpatiePackage(): void
    {
        $composerPath = base_path('composer.json');

        if (! file_exists($composerPath)) {
            $this->promptWarning('composer.json not found. Cannot remove spatie/laravel-backup automatically.');

            return;
        }

        $composer = json_decode((string) file_get_contents($composerPath), true);

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
        file_put_contents($composerPath, $encoded);

        $this->promptInfo('Removed spatie/laravel-backup from composer.json. Run composer update to complete removal.');

        $backupConfigPath = config_path('backup.php');

        if (file_exists($backupConfigPath)) {
            if (confirm(label: 'Delete config/backup.php?', default: true)) {
                unlink($backupConfigPath);
                $this->promptInfo('Deleted config/backup.php.');
            }
        }
    }

    private function renderCommandMap(): void
    {
        if (! $this->enhancedInteractiveMode()) {
            $this->newLine();
            $this->info('Artisan Command Mapping (spatie/laravel-backup → laravel-checkpoint):');
            $this->newLine();

            $rows = [];
            foreach (self::COMMAND_MAP as $spatieCommand => $checkpointCommand) {
                $rows[] = [$spatieCommand, $checkpointCommand];
            }
            $this->table(['Spatie Command', 'Checkpoint Replacement'], $rows);

            return;
        }

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
            'action' => sprintf('DB_OPS_DRIVER=%s (detected from database.default).', $dbDriver),
        ];

        $disks = $spatieConfig['backup']['destination']['disks'] ?? [];
        if (is_array($disks) && $disks !== []) {
            $steps[] = [
                'step' => '3. Map storage disk',
                'action' => sprintf('DB_OPS_OUTPUT_FILESYSTEM_DISK=%s (from Spatie destination disks).', implode(', ', array_map('strval', $disks))),
            ];
        }

        $mailTo = $spatieConfig['backup']['notifications']['notifications'][BackupHasFailedNotification::class]['to']
            ?? $spatieConfig['backup']['notifications']['mail']['to']
            ?? null;

        if ($mailTo !== null) {
            $to = is_array($mailTo) ? implode(', ', array_map('strval', $mailTo)) : (string) $mailTo;
            $steps[] = [
                'step' => '4. Map notifications',
                'action' => sprintf('DB_OPS_NOTIFICATIONS_ENABLED=true, DB_OPS_NOTIFICATIONS_MAIL_TO=%s.', $to),
            ];
        }

        $steps[] = [
            'step' => '5. Schedule migration',
            'action' => 'Replace $schedule->command(\'backup:run\') with $schedule->command(\'checkpoint:enqueue-backup\').',
        ];

        $steps[] = [
            'step' => '6. Retention',
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

        if ($this->enhancedInteractiveMode()) {
            warning('Important differences to be aware of:');
        } else {
            $this->warn('Important differences to be aware of:');
        }

        $gapRows = [];
        foreach (self::GAP_NOTES as $area => $note) {
            $gapRows[] = [str($area)->replace('_', ' ')->title()->toString(), $note];
        }

        if ($this->enhancedInteractiveMode()) {
            table(headers: ['Area', 'Note'], rows: $gapRows);
        } else {
            $this->table(['Area', 'Note'], $gapRows);
        }
    }
}
