<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Contracts;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;

/** @api */
interface BackupDriver
{
    /**
     * Execute the operation described by the command run.
     *
     * Responsible for:
     * - marking the run as running
     * - performing the configured operation
     * - writing command line, output, exit code, and timing details
     * - marking the run as succeeded or failed
     * - firing started/completed/failed events
     */
    public function execute(CommandRun $run): void;
}
