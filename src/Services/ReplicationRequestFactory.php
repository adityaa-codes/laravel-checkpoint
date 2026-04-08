<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Contracts\ReplicationEndpointParser;
use AdityaaCodes\LaravelCheckpoint\Enums\ReplicationEngine;
use AdityaaCodes\LaravelCheckpoint\Exceptions\InvalidArgumentException;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\ReplicationEndpoint;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\ReplicationEndpointKind;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\ReplicationRequest;
use Illuminate\Contracts\Config\Repository;

/** @internal */
final readonly class ReplicationRequestFactory
{
    public function __construct(
        private ReplicationEndpointParser $parser,
        private Repository $config,
    ) {}

    public function fromInput(?string $sourceInput, ?string $destinationInput, bool $dryRunRequested): ReplicationRequest
    {
        $source = $this->resolveEndpoint($sourceInput, 'source');
        $destination = $this->resolveEndpoint($destinationInput, 'destination');

        $engine = $this->resolveEngine($source, $destination);
        $this->assertSafetyDefaults();

        return new ReplicationRequest(
            source: $source,
            destination: $destination,
            engine: $engine,
            queueOnly: true,
            dryRunRequested: $dryRunRequested,
        );
    }

    private function resolveEndpoint(?string $input, string $role): ReplicationEndpoint
    {
        $parsed = $this->parser->parse($input ?? '');

        if ($parsed->kind === ReplicationEndpointKind::Prompt) {
            return $parsed;
        }

        if ($parsed->kind === ReplicationEndpointKind::ConfigProfile) {
            $profile = $this->profile($role, $parsed->identifier ?? '');
            $engineValue = is_string($profile['engine'] ?? null)
                ? ReplicationEngine::fromInput($profile['engine'])
                : null;

            if (!$engineValue instanceof \AdityaaCodes\LaravelCheckpoint\Enums\ReplicationEngine) {
                throw new InvalidArgumentException(
                    sprintf('Replication profile [%s] must define engine as pgsql or mysql.', $parsed->identifier ?? ''),
                );
            }

            return new ReplicationEndpoint(
                kind: ReplicationEndpointKind::ConfigProfile,
                rawInput: $parsed->rawInput,
                engine: $engineValue,
                identifier: $parsed->identifier,
                attributes: [],
            );
        }

        return $parsed;
    }

    private function resolveEngine(ReplicationEndpoint $source, ReplicationEndpoint $destination): ReplicationEngine
    {
        $sourceEngine = $source->engine;
        $destinationEngine = $destination->engine;

        if (!$sourceEngine instanceof \AdityaaCodes\LaravelCheckpoint\Enums\ReplicationEngine || !$destinationEngine instanceof \AdityaaCodes\LaravelCheckpoint\Enums\ReplicationEngine) {
            throw new InvalidArgumentException(
                'Replication requires explicit source and destination engines. Use profile, DSN scheme, or key=value engine field.',
            );
        }

        if ($sourceEngine !== $destinationEngine) {
            throw new InvalidArgumentException(
                sprintf(
                    'Replication v1 supports same-engine only. Received %s -> %s.',
                    $sourceEngine->value,
                    $destinationEngine->value,
                ),
            );
        }

        return $sourceEngine;
    }

    private function assertSafetyDefaults(): void
    {
        if (! (bool) $this->config->get('checkpoint.replication.require_confirmation_token', true)) {
            throw new InvalidArgumentException('Replication safety requires confirmation token enforcement.');
        }

        if (! (bool) $this->config->get('checkpoint.replication.block_in_ci', true)) {
            throw new InvalidArgumentException('Replication safety requires CI blocking by default.');
        }

        if (! (bool) $this->config->get('checkpoint.replication.require_dry_run_before_apply', true)) {
            throw new InvalidArgumentException('Replication safety requires dry-run-before-apply gate.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(string $role, string $identifier): array
    {
        $profiles = $this->config->get('checkpoint.replication.profiles', []);

        if (! is_array($profiles)) {
            throw new InvalidArgumentException('Replication profiles must be configured as an array.');
        }

        $profile = $profiles[$identifier] ?? null;

        if (! is_array($profile)) {
            throw new InvalidArgumentException(
                sprintf('Unknown replication profile [%s] for %s endpoint.', $identifier, $role),
            );
        }

        return $profile;
    }
}
