<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Notifications\Notifications;

use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillFailed;
use AdityaaCodes\LaravelCheckpoint\Notifications\BaseNotification;
use Illuminate\Notifications\Messages\MailMessage;

final class BackupDrillFailedNotification extends BaseNotification
{
    public function __construct(public BackupDrillFailed $event) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject(sprintf('[%s] Drill Failed', $this->applicationName()))
            ->line(sprintf('A backup drill operation failed on %s.', $this->applicationName()))
            ->line(sprintf('Operation: %s', $this->event->run->operation ?? 'unknown'))
            ->line(sprintf('Exit Code: %d', $this->event->exitCode))
            ->line(sprintf('Run ID: %d', (int) $this->event->run->getKey()))
            ->line('')
            ->line('Last output:')
            ->line(substr($this->event->output, 0, 500));
    }
}
