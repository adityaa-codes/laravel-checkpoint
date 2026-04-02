<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Support;

final class NotificationEventLevelResolver
{
    public function levelFor(string $eventKey): string
    {
        return match ($eventKey) {
            'backup.failed',
            'backup.freshness_alarm',
            'backup_drill.freshness_alarm',
            'backup_drill.pass_rate_alarm',
            'queue.lag_detected' => 'critical',
            'queue.orphan_redispatched' => 'warning',
            default => 'info',
        };
    }
}
