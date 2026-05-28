<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Services\GatePolicyEvaluator;

it('keeps warning-only local checks as pass by default', function (): void {
    config()->set('app.env', 'testing');

    $decision = resolve(GatePolicyEvaluator::class)->evaluate([
        [
            'code' => 'backup_drill.latest_run',
            'check' => 'Backup drills: latest run',
            'status' => 'warn',
            'notes' => 'No backup drill recorded',
            'data' => [],
        ],
    ]);

    expect($decision->profile)->toBe('local');
    expect($decision->profileSource)->toBe('environment');
    expect($decision->verdict)->toBe('pass');
    expect($decision->failedGate)->toBe('none');
    expect($decision->exitCode)->toBe(0);
});

it('fails evidence gate for staging profile when evidence checks degrade', function (): void {
    config()->set('app.env', 'testing');
    config()->set('checkpoint.gates.environment_profile_map.testing', 'staging');

    $decision = resolve(GatePolicyEvaluator::class)->evaluate([
        [
            'code' => 'restore.post_verification',
            'check' => 'Restore posture: post-restore verification',
            'status' => 'warn',
            'notes' => 'No restore run available for post-restore verification evaluation',
            'data' => [],
        ],
    ]);

    expect($decision->profile)->toBe('staging');
    expect($decision->profileSource)->toBe('environment');
    expect($decision->verdict)->toBe('fail');
    expect($decision->failedGate)->toBe('evidence');
    expect($decision->exitCode)->toBe(11);
});

it('fails safety gate for staging profile when selected warning code appears', function (): void {
    config()->set('app.env', 'testing');
    config()->set('checkpoint.gates.environment_profile_map.testing', 'staging');

    $decision = resolve(GatePolicyEvaluator::class)->evaluate([
        [
            'code' => 'queue.orphaned_runs',
            'check' => 'Orphaned runs',
            'status' => 'warn',
            'notes' => '1 pending run beyond threshold',
            'data' => [],
        ],
    ]);

    expect($decision->profile)->toBe('staging');
    expect($decision->profileSource)->toBe('environment');
    expect($decision->verdict)->toBe('fail');
    expect($decision->failedGate)->toBe('safety');
    expect($decision->exitCode)->toBe(10);
});

it('returns warning exit code when profile enables exit_on_warn', function (): void {
    config()->set('app.env', 'testing');
    config()->set('checkpoint.gates.environment_profile_map.testing', 'local');
    config()->set('checkpoint.gates.profiles.local.exit_on_warn', true);

    $decision = resolve(GatePolicyEvaluator::class)->evaluate([
        [
            'code' => 'queue.worker_visibility',
            'check' => 'Queue: checkpoint',
            'status' => 'warn',
            'notes' => 'Cannot verify queue without running worker',
            'data' => [],
        ],
    ]);

    expect($decision->profile)->toBe('local');
    expect($decision->profileSource)->toBe('environment');
    expect($decision->verdict)->toBe('warn');
    expect($decision->failedGate)->toBe('none');
    expect($decision->exitCode)->toBe(2);
});

it('uses explicit policy profile override when provided', function (): void {
    config()->set('app.env', 'testing');
    config()->set('checkpoint.gates.environment_profile_map.testing', 'local');

    $decision = resolve(GatePolicyEvaluator::class)->evaluate([
        [
            'code' => 'restore.post_verification',
            'check' => 'Restore posture: post-restore verification',
            'status' => 'warn',
            'notes' => 'No restore run available for post-restore verification evaluation',
            'data' => [],
        ],
    ], [], 'staging');

    expect($decision->profile)->toBe('staging');
    expect($decision->profileSource)->toBe('override');
    expect($decision->verdict)->toBe('fail');
    expect($decision->failedGate)->toBe('evidence');
    expect($decision->exitCode)->toBe(11);
});
