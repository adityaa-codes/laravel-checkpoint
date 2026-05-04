<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Tests\Support;

use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Facades\Date;

final class DoctorCommandTestSupport
{
    public static function freezeTime(): void
    {
        Date::setTestNow('2026-03-11 12:00:00');
    }

    public static function resetTime(): void
    {
        Date::setTestNow();
    }

    /**
     * @return array{passing: BackupDrillRun, failing: BackupDrillRun}
     */
    public static function seedRecentDrillPair(): array
    {
        $passing = BackupDrillRun::query()->create([
            'run_uuid' => 'drill-pass-001',
            'overall_result' => 'pass',
            'executed_at' => now()->subDays(5),
        ]);

        $failing = BackupDrillRun::query()->create([
            'run_uuid' => 'drill-fail-001',
            'overall_result' => 'fail',
            'executed_at' => now()->subDays(2),
        ]);

        return ['passing' => $passing, 'failing' => $failing];
    }

    /**
     * @return array{latest: BackupDrillRun, previous: BackupDrillRun}
     */
    public static function seedStaleDrillAlarmState(): array
    {
        $latest = BackupDrillRun::query()->create([
            'run_uuid' => 'drill-fail-001',
            'overall_result' => 'fail',
            'executed_at' => now()->subDays(10),
        ]);

        $previous = BackupDrillRun::query()->create([
            'run_uuid' => 'drill-pass-001',
            'overall_result' => 'pass',
            'executed_at' => now()->subDays(12),
        ]);

        return ['latest' => $latest, 'previous' => $previous];
    }

    public static function seedAnomalousBackupHistory(): void
    {
        CommandRun::query()->create([
            'operation' => 'logical_backup',
            'backup_type' => 'logical_export',
            'status' => 'succeeded',
            'attempts' => 0,
            'duration_seconds' => 900,
            'last_known_good_at' => now()->subHours(30),
        ]);
        CommandRun::query()->create([
            'operation' => 'physical_backup',
            'backup_type' => 'full',
            'status' => 'succeeded',
            'attempts' => 0,
            'duration_seconds' => 300,
            'last_known_good_at' => now()->subHours(32),
        ]);
        CommandRun::query()->create([
            'operation' => 'physical_backup',
            'backup_type' => 'diff',
            'status' => 'succeeded',
            'attempts' => 0,
            'duration_seconds' => 320,
            'last_known_good_at' => now()->subHours(31),
        ]);
        CommandRun::query()->create([
            'operation' => 'physical_backup',
            'backup_type' => 'incr',
            'status' => 'succeeded',
            'attempts' => 0,
            'duration_seconds' => 900,
            'last_known_good_at' => now()->subHours(33),
        ]);
    }

    public static function seedStaleLastKnownGoodBackup(): CommandRun
    {
        return CommandRun::query()->create([
            'operation' => 'logical_backup',
            'backup_type' => 'logical_export',
            'status' => 'succeeded',
            'attempts' => 0,
            'last_known_good_at' => now()->subHours(30),
        ]);
    }
}
