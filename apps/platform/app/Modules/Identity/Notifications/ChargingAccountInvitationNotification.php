<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ChargingAccountInvitationNotification extends Notification
{
    public function __construct(public readonly string $accountName) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Power Solutions charging account invitation')
            ->line('You have been invited to '.$this->accountName.'.')
            ->line('Open Power Solutions and sign in to your registered account using this email address. Verify your email before accepting.')
            ->line('Open Account > Charging accounts > Invitations to accept. This invitation expires in seven days.');
    }
}
