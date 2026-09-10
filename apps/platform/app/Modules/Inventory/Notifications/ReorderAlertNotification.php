<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ReorderAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $itemId,
        private readonly string $warehouseId,
        private readonly int $availableBase,
        private readonly int $minimumBase,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Inventory reorder alert')
            ->line("Available stock ({$this->availableBase}) is at or below the reorder minimum ({$this->minimumBase}).")
            ->line('Open the authorized Power Solutions inventory workspace for item and warehouse details.');
    }

    /** @return array<string, int|string> */
    public function toArray(object $notifiable): array
    {
        return [
            'item_id' => $this->itemId,
            'warehouse_id' => $this->warehouseId,
            'available_base' => $this->availableBase,
            'minimum_base' => $this->minimumBase,
        ];
    }
}
