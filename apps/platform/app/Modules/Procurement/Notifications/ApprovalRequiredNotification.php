<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ApprovalRequiredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $documentType,
        private readonly string $documentId,
        private readonly string $reference,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Procurement approval required')
            ->line("{$this->documentType} {$this->reference} is awaiting your approval.")
            ->line('Open the authorized Power Solutions panel to review the immutable approval evidence.');
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return [
            'document_type' => $this->documentType,
            'document_id' => $this->documentId,
            'reference' => $this->reference,
        ];
    }
}
