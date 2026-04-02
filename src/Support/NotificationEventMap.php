<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Support;

use AdityaaCodes\LaravelCheckpoint\Events\BackupCompleted;
use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillCompleted;
use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillFreshnessAlarmTriggered;
use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillPassRateAlarmTriggered;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFreshnessAlarmTriggered;
use AdityaaCodes\LaravelCheckpoint\Events\BackupQueued;
use AdityaaCodes\LaravelCheckpoint\Events\BackupStarted;
use AdityaaCodes\LaravelCheckpoint\Events\OrphanRunRedispatched;
use AdityaaCodes\LaravelCheckpoint\Events\QueueLagDetected;

final class NotificationEventMap
{
    /** @var array<class-string, string> */
    private const CLASS_TO_KEY = [
        BackupQueued::class => 'backup.queued',
        BackupStarted::class => 'backup.started',
        BackupCompleted::class => 'backup.completed',
        BackupFailed::class => 'backup.failed',
        BackupFreshnessAlarmTriggered::class => 'backup.freshness_alarm',
        BackupDrillCompleted::class => 'backup_drill.completed',
        BackupDrillFreshnessAlarmTriggered::class => 'backup_drill.freshness_alarm',
        BackupDrillPassRateAlarmTriggered::class => 'backup_drill.pass_rate_alarm',
        QueueLagDetected::class => 'queue.lag_detected',
        OrphanRunRedispatched::class => 'queue.orphan_redispatched',
    ];

    /**
     * @return list<class-string>
     */
    public static function supportedClasses(): array
    {
        return array_keys(self::CLASS_TO_KEY);
    }

    /**
     * @return list<string>
     */
    public static function supportedKeys(): array
    {
        return array_values(self::CLASS_TO_KEY);
    }

    public static function keyFor(object $event): ?string
    {
        return self::CLASS_TO_KEY[$event::class] ?? null;
    }

    public static function supportsKey(string $key): bool
    {
        return in_array($key, self::CLASS_TO_KEY, true);
    }
}
