<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Notifications\CheckpointEventNotification;
use AdityaaCodes\LaravelCheckpoint\Support\NotificationEventLevelResolver;
use AdityaaCodes\LaravelCheckpoint\Support\NotificationEventMap;
use AdityaaCodes\LaravelCheckpoint\Support\NotificationEventPayloadBuilder;
use AdityaaCodes\LaravelCheckpoint\Support\NotificationMessageFormatter;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

final readonly class NotificationRouter
{
    public function __construct(
        private Repository $config,
        private Dispatcher $events,
        private NotificationEventPayloadBuilder $payloadBuilder,
        private NotificationEventLevelResolver $levelResolver,
        private NotificationMessageFormatter $messageFormatter,
    ) {}

    public function register(): void
    {
        foreach (NotificationEventMap::supportedClasses() as $eventClass) {
            $this->events->listen($eventClass, function (object $event): void {
                $this->route($event);
            });
        }
    }

    public function route(object $event): void
    {
        $eventKey = NotificationEventMap::keyFor($event);

        if ($eventKey === null || ! $this->eventEnabled($eventKey)) {
            return;
        }

        $level = $this->levelResolver->levelFor($eventKey);
        $channels = $this->channelsForLevel($level);

        if ($channels === []) {
            return;
        }

        $payload = $this->basePayload($event, $eventKey, $level);
        $formatted = $this->messageFormatter->format($payload);
        $payload['message'] = $formatted;

        if (in_array('log', $channels, true)) {
            $this->routeLog($payload);
        }

        if (in_array('mail', $channels, true)) {
            $this->routeMail($payload, $eventKey, $level);
        }

        if (in_array('webhook', $channels, true)) {
            $this->routeWebhook($payload);
        }
    }

    private function eventEnabled(string $eventKey): bool
    {
        $enabledEvents = $this->config->get('checkpoint.notifications.events', []);

        if (! is_array($enabledEvents) || $enabledEvents === []) {
            return true;
        }

        return in_array($eventKey, $enabledEvents, true);
    }

    /**
     * @return list<string>
     */
    private function channelsForLevel(string $level): array
    {
        $channels = $this->config->get(sprintf('checkpoint.notifications.routing.%s', $level), []);

        if (! is_array($channels)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => is_string($value) ? trim($value) : '',
            $channels,
        ), static fn (string $value): bool => $value !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(object $event, string $eventKey, string $level): array
    {
        return [
            'event_key' => $eventKey,
            'event_class' => $event::class,
            'level' => $level,
            'occurred_at' => now()->toIso8601String(),
            'payload' => $this->payloadBuilder->build($event, $eventKey),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function routeLog(array $payload): void
    {
        Log::channel((string) $this->config->get('checkpoint.log_channel', 'stack'))
            ->warning('Checkpoint notification routed', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function routeMail(array $payload, string $eventKey, string $level): void
    {
        $to = $this->config->get('checkpoint.notifications.mail.to', []);

        if (! is_array($to) || $to === []) {
            return;
        }

        foreach ($to as $address) {
            if (! is_string($address)) {
                continue;
            }
            if (trim($address) === '') {
                continue;
            }
            Notification::route('mail', $address)
                ->notify(new CheckpointEventNotification($eventKey, $level, $payload));
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function routeWebhook(array $payload): void
    {
        $url = $this->config->get('checkpoint.notifications.webhook.url');
        $timeout = (int) $this->config->get('checkpoint.notifications.webhook.timeout_seconds', 5);
        $provider = (string) $this->config->get('checkpoint.notifications.webhook.provider', 'generic');

        if (! is_string($url) || trim($url) === '') {
            return;
        }

        $body = $this->webhookBody($payload, $provider);

        try {
            Http::timeout(max(1, $timeout))
                ->asJson()
                ->post($url, $body)
                ->throw();
        } catch (Throwable $e) {
            Log::channel((string) $this->config->get('checkpoint.log_channel', 'stack'))
                ->error('Checkpoint webhook delivery failed', [
                    'url' => $url,
                    'provider' => $provider,
                    'error' => $e->getMessage(),
                ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function webhookBody(array $payload, string $provider): array
    {
        $message = is_array($payload['message'] ?? null) ? $payload['message'] : [];

        return match ($provider) {
            'slack' => [
                'text' => (string) ($message['slack_text'] ?? ''),
                'event' => $payload,
            ],
            'telegram' => [
                'text' => (string) ($message['telegram_text'] ?? ''),
                'parse_mode' => 'Markdown',
                'event' => $payload,
            ],
            default => $payload,
        };
    }
}
