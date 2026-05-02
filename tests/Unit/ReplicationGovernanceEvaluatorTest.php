<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\ReplicationEngine;
use AdityaaCodes\LaravelCheckpoint\Services\ReplicationGovernanceEvaluator;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\ReplicationEndpoint;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\ReplicationEndpointKind;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\ReplicationRequest;
use Illuminate\Support\Facades\Date;

it('allows apply mode during overnight change windows', function (): void {
    Date::setTestNow('2026-03-09 23:30:00');
    config()->set('checkpoint.replication.allowlisted_destinations', ['profile:pg-destination']);
    config()->set('checkpoint.replication.enforce_change_window', true);
    config()->set('checkpoint.replication.change_window_timezone', 'UTC');
    config()->set('checkpoint.replication.change_window_days', ['mon', 'tue']);
    config()->set('checkpoint.replication.change_window_start', '22:00');
    config()->set('checkpoint.replication.change_window_end', '02:00');

    $evaluation = resolve(ReplicationGovernanceEvaluator::class)->evaluate(replicationRequest(), true);

    expect($evaluation['allowed'])->toBeTrue()
        ->and($evaluation['blocked_reasons'])->toBe([])
        ->and($evaluation['change_window'])->toMatchArray([
            'enforced' => true,
            'allowed' => true,
            'reason' => 'within_window',
            'day_allowed' => true,
            'time_allowed' => true,
        ]);

    Date::setTestNow();
});

it('treats equal change window boundaries as always open when the day is allowed', function (): void {
    Date::setTestNow('2026-03-09 03:15:00');
    config()->set('checkpoint.replication.allowlisted_destinations', ['profile:pg-destination']);
    config()->set('checkpoint.replication.enforce_change_window', true);
    config()->set('checkpoint.replication.change_window_timezone', 'UTC');
    config()->set('checkpoint.replication.change_window_days', ['mon']);
    config()->set('checkpoint.replication.change_window_start', '09:00');
    config()->set('checkpoint.replication.change_window_end', '09:00');

    $evaluation = resolve(ReplicationGovernanceEvaluator::class)->evaluate(replicationRequest(), true);

    expect($evaluation['allowed'])->toBeTrue()
        ->and($evaluation['change_window']['time_allowed'] ?? null)->toBeTrue()
        ->and($evaluation['blocked_reasons'])->toBe([]);

    Date::setTestNow();
});

it('blocks apply mode outside configured change window days', function (): void {
    Date::setTestNow('2026-03-09 12:00:00');
    config()->set('checkpoint.replication.allowlisted_destinations', ['profile:pg-destination']);
    config()->set('checkpoint.replication.enforce_change_window', true);
    config()->set('checkpoint.replication.change_window_timezone', 'UTC');
    config()->set('checkpoint.replication.change_window_days', ['tue']);
    config()->set('checkpoint.replication.change_window_start', '00:00');
    config()->set('checkpoint.replication.change_window_end', '23:59');

    $evaluation = resolve(ReplicationGovernanceEvaluator::class)->evaluate(replicationRequest(), true);

    expect($evaluation['allowed'])->toBeFalse()
        ->and($evaluation['blocked_reasons'])->toContain('outside_change_window')
        ->and($evaluation['change_window']['day_allowed'] ?? null)->toBeFalse()
        ->and($evaluation['change_window']['time_allowed'] ?? null)->toBeTrue();

    Date::setTestNow();
});

function replicationRequest(): ReplicationRequest
{
    return new ReplicationRequest(
        source: new ReplicationEndpoint(
            kind: ReplicationEndpointKind::ConfigProfile,
            rawInput: 'profile:pg-source',
            engine: ReplicationEngine::Pgsql,
            identifier: 'pg-source',
        ),
        destination: new ReplicationEndpoint(
            kind: ReplicationEndpointKind::ConfigProfile,
            rawInput: 'profile:pg-destination',
            engine: ReplicationEngine::Pgsql,
            identifier: 'pg-destination',
        ),
        engine: ReplicationEngine::Pgsql,
        queueOnly: false,
        dryRunRequested: false,
    );
}
