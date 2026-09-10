<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class DiscrepancyOpenedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $vendorInvoiceId,
        private readonly string $invoiceReference,
        private readonly int $issueCount,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Procurement match discrepancy')
            ->line("Vendor invoice {$this->invoiceReference} has {$this->issueCount} matching issue(s).")
            ->line('Review the PO, accepted receipt, and invoice evidence before approval.');
    }

    /** @return array<string, int|string> */
    public function toArray(object $notifiable): array
    {
        return [
            'vendor_invoice_id' => $this->vendorInvoiceId,
            'invoice_reference' => $this->invoiceReference,
            'issue_count' => $this->issueCount,
        ];
    }
}
