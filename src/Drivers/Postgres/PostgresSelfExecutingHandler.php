<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers\Postgres;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;

/** @internal */
interface PostgresSelfExecutingHandler extends PostgresOperationHandler
{
    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    public function execute(CommandRun $run, array $plannedMetadata): array;
}
