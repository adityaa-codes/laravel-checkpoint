<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Enums;

enum CheckpointOperation: string
{
    case Backup = 'logical_backup';
    case Drill = 'backup_drill';
    case Replicate = 'replication_sync';
    case RestoreLatest = 'logical_restore_latest';
    case RestoreFile = 'logical_restore_file';
    case PitrRestore = 'pitr_restore';
}
