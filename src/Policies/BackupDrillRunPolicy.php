<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Policies;

use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;

final class BackupDrillRunPolicy
{
    public function viewAny(object $user): bool
    {
        return true;
    }

    public function view(object $user, BackupDrillRun $backupDrillRun): bool
    {
        return true;
    }

    public function create(object $user): bool
    {
        return false;
    }

    public function update(object $user, BackupDrillRun $backupDrillRun): bool
    {
        return false;
    }

    public function delete(object $user, BackupDrillRun $backupDrillRun): bool
    {
        return false;
    }
}
