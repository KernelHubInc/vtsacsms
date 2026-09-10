<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class UserInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $plainTextToken,
        private readonly string $organizationName,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You are invited to Power Solutions')
            ->line("You were invited to join {$this->organizationName}.")
            ->action('Accept invitation', url('/accept-invitation?token='.urlencode($this->plainTextToken)))
            ->line('This invitation is personal and expires automatically.');
    }
}
