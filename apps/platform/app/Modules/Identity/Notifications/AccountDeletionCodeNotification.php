<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use App\Modules\Identity\Application\AccountDeletionRequests;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AccountDeletionCodeNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $challenge, #[\SensitiveParameter] private readonly string $code) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return config('mail.default') !== 'log' && app(AccountDeletionRequests::class)->isPending($this->challenge, $this->code);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Confirm your Power Solutions account deletion request')
            ->line('Enter this code on the account deletion page in the browser where you requested it.')
            ->line($this->code)
            ->line('The code expires after 15 minutes. If you did not request account deletion, ignore this email.');
    }
}
