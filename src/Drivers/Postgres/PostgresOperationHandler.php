<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers\Postgres;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Symfony\Component\Process\Process;

/** @internal */
interface PostgresOperationHandler
{
    public function supports(string $operation): bool;

    /**
     * @param  array<string, mixed>  $plannedMetadata
     */
    public function buildProcess(CommandRun $run, array $plannedMetadata): Process;

    /**
     * @return array<string, mixed>
     */
    public function plannedMetadata(CommandRun $run): array;

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}|null
     */
    public function execute(CommandRun $run, array $plannedMetadata): ?array;

    /**
     * @param  array<string, mixed>  $plannedMetadata
     */
    public function displayCommandLine(CommandRun $run, array $plannedMetadata): string;
}
