<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class MaintenanceAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<string, int|string|null> $context */
    public function __construct(
        private readonly string $kind,
        private readonly string $subject,
        private readonly string $message,
        private readonly array $context,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject)
            ->line($this->message)
            ->line('Open the authorized Power Solutions maintenance workspace to review the current evidence.');
    }

    /** @return array<string, int|string|null> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, ...$this->context];
    }
}
