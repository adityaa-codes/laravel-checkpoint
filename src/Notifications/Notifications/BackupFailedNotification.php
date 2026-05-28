<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Notifications\Notifications;

use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use AdityaaCodes\LaravelCheckpoint\Notifications\BaseNotification;
use Illuminate\Notifications\Messages\MailMessage;

final class BackupFailedNotification extends BaseNotification
{
    public function __construct(public BackupFailed $event) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject(sprintf('[%s] Backup Failed', $this->applicationName()))
            ->line(sprintf('A backup operation failed on %s.', $this->applicationName()))
            ->line(sprintf('Operation: %s', $this->event->run->operation ?? 'unknown'))
            ->line(sprintf('Exit Code: %d', $this->event->exitCode))
            ->line(sprintf('Run ID: %d', (int) $this->event->run->getKey()))
            ->line('')
            ->line('Last output:')
            ->line(substr($this->event->output, 0, 500));
    }
}
