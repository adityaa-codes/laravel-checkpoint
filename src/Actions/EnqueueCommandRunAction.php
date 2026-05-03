<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Actions;

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Events\BackupQueued;
use AdityaaCodes\LaravelCheckpoint\Jobs\ProcessCommandRunJob;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Services\CommandRunCatalog;
use AdityaaCodes\LaravelCheckpoint\Services\ReplicationGovernanceEvaluator;
use AdityaaCodes\LaravelCheckpoint\Services\ReplicationRequestFactory;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Services\ReplicationSecretRedactor;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;

class EnqueueCommandRunAction
{
    public function __construct(
        private readonly CommandRunCatalog $catalog,
        private readonly DatabaseManager $database,
        private readonly Dispatcher $dispatcher,
        private readonly EventDispatcher $events,
        private readonly Repository $config,
        private readonly ReplicationGovernanceEvaluator $replicationGovernanceEvaluator,
        private readonly ReplicationRequestFactory $replicationRequestFactory,
        private readonly ReplicationSecretRedactor $replicationSecretRedactor,
        private readonly ValidateOperationBinaries $validateOperationBinaries,
    ) {}

    public function execute(string $operation, ?string $argument = null, ?Model $requestedBy = null): CommandRun
    {
        if (! (bool) $this->config->get('checkpoint.operations_enabled', true)) {
            throw new ConfigurationException('Checkpoint operations are disabled (checkpoint.operations_enabled or CP_OPERATIONS_ENABLED).');
        }

        $this->validateOperationBinaries->validate($operation);

        $prepared = $this->preparedOperation($operation, $argument);

        $run = $this->database->transaction(fn (): CommandRun => CommandRun::query()->create([
            'operation' => $prepared['operation'],
            'argument_text' => $prepared['argument_text'],
            'metadata' => $prepared['metadata'],
            'status' => CommandRunStatus::Pending,
            'attempts' => 0,
            'requested_by_type' => $requestedBy?->getMorphClass(),
            'requested_by_id' => $requestedBy?->getKey(),
        ]));

        $job = new ProcessCommandRunJob($run)
            ->onQueue((string) $this->config->get('checkpoint.queue.name', 'db-ops'))
            ->afterCommit();

        $this->dispatcher->dispatch($job);
        $this->events->dispatch(new BackupQueued($run));

        return $run;
    }

    /**
     * @return array{operation:string,argument_text:?string,metadata:array<string,mixed>|null}
     */
    private function preparedOperation(string $operation, ?string $argument): array
    {
        if ($operation !== 'replication_sync') {
            return [
                'operation' => $operation,
                'argument_text' => $this->catalog->validate($operation, $argument),
                'metadata' => null,
            ];
        }

        $normalizedArgument = $this->catalog->validate($operation, $argument);
        $payload = json_decode((string) $normalizedArgument, true, flags: JSON_THROW_ON_ERROR);

        /** @var array{source:string,destination:string,dry_run?:bool,apply?:bool,force?:bool,force_overwrite?:bool,overwrite_destination?:bool,critical_tables?:array<int,string>} $payload */
        $request = $this->replicationRequestFactory->fromInput(
            sourceInput: $payload['source'],
            destinationInput: $payload['destination'],
            dryRunRequested: (bool) ($payload['dry_run'] ?? true),
        );
        $dryRunRequested = (bool) ($payload['dry_run'] ?? true);
        $applyRequested = (bool) ($payload['apply'] ?? ! $dryRunRequested);
        $forceOverwriteRequested = (bool) ($payload['force_overwrite'] ?? $payload['force'] ?? false);
        $overwriteDestination = (bool) ($payload['overwrite_destination'] ?? $forceOverwriteRequested);
        $criticalTables = is_array($payload['critical_tables'] ?? null)
            ? array_values(array_unique(array_filter($payload['critical_tables'], static fn (mixed $value): bool => trim((string) $value) !== '')))
            : [];
        $governancePreflight = $this->replicationGovernanceEvaluator->evaluate($request, $applyRequested);
        $this->replicationGovernanceEvaluator->assertAllowed($governancePreflight, $applyRequested);

        $serializedArgument = json_encode([
            'source' => $request->source->toRedactedString(),
            'destination' => $request->destination->toRedactedString(),
            'dry_run' => $dryRunRequested,
            'apply' => $applyRequested,
            'force_overwrite' => $forceOverwriteRequested,
            'governance_preflight' => $governancePreflight,
            'critical_tables' => $criticalTables,
        ], JSON_THROW_ON_ERROR);

        return [
            'operation' => $operation,
            'argument_text' => $serializedArgument,
            'metadata' => [
                'replication' => [
                    'engine' => $request->engine->value,
                    'source' => [
                        'kind' => $request->source->kind->value,
                        'identifier' => $request->source->identifier,
                        'redacted' => $this->replicationSecretRedactor->redact($request->source->rawInput),
                    ],
                    'destination' => [
                        'kind' => $request->destination->kind->value,
                        'identifier' => $request->destination->identifier,
                        'redacted' => $this->replicationSecretRedactor->redact($request->destination->rawInput),
                    ],
                    'queue_only' => $request->queueOnly,
                    'dry_run_requested' => $dryRunRequested,
                    'apply_requested' => $applyRequested,
                    'force_requested' => $forceOverwriteRequested,
                    'force_overwrite_requested' => $forceOverwriteRequested,
                    'overwrite_destination' => $overwriteDestination,
                    'critical_tables' => $criticalTables,
                    'governance_preflight' => $governancePreflight,
                ],
            ],
        ];
    }
}
