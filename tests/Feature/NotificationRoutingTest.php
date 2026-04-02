<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillPassRateAlarmTriggered;
use AdityaaCodes\LaravelCheckpoint\Events\BackupCompleted;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Notifications\CheckpointEventNotification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

it('routes critical events to configured channels', function (): void {
    config()->set('checkpoint.notifications.enabled', true);
    config()->set('checkpoint.notifications.routing.critical', ['log', 'mail', 'webhook']);
    config()->set('checkpoint.notifications.mail.to', ['ops@example.com']);
    config()->set('checkpoint.notifications.webhook.url', 'https://hooks.example.com/checkpoint');

    Http::fake();
    Notification::fake();
    Log::shouldReceive('channel->warning')->once();

    $run = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Failed,
        'attempts' => 1,
    ]);

    event(new BackupFailed($run, 1, 'backup failed'));

    Notification::assertSentOnDemand(CheckpointEventNotification::class);
    Http::assertSentCount(1);
});

it('formats webhook body for slack provider', function (): void {
    config()->set('checkpoint.notifications.enabled', true);
    config()->set('checkpoint.notifications.routing.critical', ['webhook']);
    config()->set('checkpoint.notifications.webhook.url', 'https://hooks.slack.example/checkpoint');
    config()->set('checkpoint.notifications.webhook.provider', 'slack');

    Http::fake();

    $run = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Failed,
        'attempts' => 1,
    ]);

    event(new BackupFailed($run, 1, 'backup failed'));

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return isset($data['text'], $data['event'])
            && is_string($data['text'])
            && str_contains($data['text'], '[CRITICAL]')
            && is_array($data['event']);
    });
});

it('formats webhook body for telegram provider', function (): void {
    config()->set('checkpoint.notifications.enabled', true);
    config()->set('checkpoint.notifications.routing.critical', ['webhook']);
    config()->set('checkpoint.notifications.webhook.url', 'https://api.telegram.org/botTOKEN/sendMessage');
    config()->set('checkpoint.notifications.webhook.provider', 'telegram');

    Http::fake();

    $run = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Failed,
        'attempts' => 1,
    ]);

    event(new BackupFailed($run, 1, 'backup failed'));

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return isset($data['text'], $data['event'], $data['parse_mode'])
            && $data['parse_mode'] === 'Markdown'
            && is_string($data['text'])
            && str_contains($data['text'], '[CRITICAL]')
            && is_array($data['event']);
    });
});

it('includes drill remediation playbook metadata in webhook notifications', function (): void {
    config()->set('checkpoint.notifications.enabled', true);
    config()->set('checkpoint.notifications.routing.critical', ['webhook']);
    config()->set('checkpoint.notifications.webhook.url', 'https://hooks.slack.example/checkpoint');
    config()->set('checkpoint.notifications.webhook.provider', 'slack');

    Http::fake();

    $drill = BackupDrillRun::query()->create([
        'run_uuid' => 'drill-fail-001',
        'overall_result' => 'fail',
        'executed_at' => now()->subDay(),
    ]);

    event(new BackupDrillPassRateAlarmTriggered(
        windowDays: 14,
        passing: 0,
        total: 1,
        passRatePercent: 0.0,
        thresholdPercent: 100.0,
        latestRun: $drill,
    ));

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();
        $event = is_array($data['event'] ?? null) ? $data['event'] : [];
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $remediation = is_array($payload['remediation'] ?? null) ? $payload['remediation'] : [];
        $message = is_array($event['message'] ?? null) ? $event['message'] : [];
        $canonical = is_array($message['canonical'] ?? null) ? $message['canonical'] : [];
        $context = is_array($canonical['context'] ?? null) ? $canonical['context'] : [];
        $actions = is_array($canonical['actions'] ?? null) ? $canonical['actions'] : [];

        return ($remediation['signature'] ?? null) === 'drill.pass_rate_below_threshold'
            && ($remediation['severity'] ?? null) === 'warn'
            && in_array('php artisan db-ops:enqueue-drill', $actions, true)
            && ($context['playbook_signature'] ?? null) === 'drill.pass_rate_below_threshold';
    });
});

it('does not route events when notifications are disabled', function (): void {
    config()->set('checkpoint.notifications.enabled', false);
    config()->set('checkpoint.notifications.routing.info', ['log']);
    config()->set('checkpoint.notifications.mail.to', ['ops@example.com']);
    config()->set('checkpoint.notifications.webhook.url', 'https://hooks.example.com/checkpoint');

    Http::fake();
    Notification::fake();

    $run = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 1,
    ]);

    event(new BackupCompleted($run, 0, 'ok'));

    Notification::assertNothingSent();
    Http::assertNothingSent();
});

it('filters routed events when notification events are explicitly configured', function (): void {
    config()->set('checkpoint.notifications.enabled', true);
    config()->set('checkpoint.notifications.events', ['backup.failed']);
    config()->set('checkpoint.notifications.routing.info', ['log']);
    config()->set('checkpoint.notifications.routing.critical', ['log']);

    Http::fake();
    Notification::fake();
    Log::shouldReceive('channel->warning')->once();

    $succeeded = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 1,
    ]);

    $failed = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Failed,
        'attempts' => 1,
    ]);

    event(new BackupCompleted($succeeded, 0, 'done'));
    event(new BackupFailed($failed, 1, 'failed'));
});
