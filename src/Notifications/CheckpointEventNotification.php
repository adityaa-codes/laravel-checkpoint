<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class CheckpointEventNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly string $eventKey,
        private readonly string $level,
        private readonly array $payload,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(sprintf('[checkpoint][%s] %s', strtoupper($this->level), $this->eventKey))
            ->line(sprintf('Event: %s', $this->eventKey))
            ->line(sprintf('Severity: %s', $this->level))
            ->line(sprintf('Occurred at: %s', now()->toIso8601String()))
            ->line('Payload:')
            ->line($this->encodePayload($this->payload));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event_key' => $this->eventKey,
            'level' => $this->level,
            'payload' => $this->payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodePayload(array $payload): string
    {
        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
