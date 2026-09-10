<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

final class VerifyEmailNotification extends VerifyEmail
{
    protected function verificationUrl($notifiable): string
    {
        if (! $notifiable instanceof User || $notifiable->public_id === null) {
            return '';
        }

        return URL::temporarySignedRoute(
            'api.v1.auth.email.verify',
            now('UTC')->addMinutes(60),
            ['user' => $notifiable->public_id, 'hash' => sha1($notifiable->getEmailForVerification())],
        );
    }

    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your Power Solutions email address')
            ->line('Confirm this email address to finish securing your Power Solutions account.')
            ->action('Verify email address', $url)
            ->line('This link expires in 60 minutes.');
    }
}
