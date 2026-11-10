<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use App\Modules\Identity\Application\WorkforceEnrollmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class WorkforceEnrollmentNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $tenantId, private readonly string $enrollmentId, #[\SensitiveParameter] private readonly string $token)
    {
        $this->afterCommit();
        $this->onQueue('invitations');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return app(WorkforceEnrollmentService::class)->canSend($this->tenantId, $this->enrollmentId, $this->token);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Set up your Power Solutions administrator password')
            ->line('The owner of Power Solutions approved an administrator account for this email address.')
            ->action('Set your private password', url('/workforce/setup').'#token='.$this->token)
            ->line('This personal setup link expires in 24 hours and works once. Choose your own password on the secure page.')
            ->line('If you were not expecting this access, do not use the link and contact the account owner.');
    }

    public function recordDelivery(): void
    {
        app(WorkforceEnrollmentService::class)->delivered($this->tenantId, $this->enrollmentId, $this->token);
    }
}
