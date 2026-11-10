<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Notifications\WorkforceEnrollmentNotification;
use Illuminate\Notifications\Events\NotificationSent;

final class RecordWorkforceEnrollmentDelivery
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel === 'mail' && $event->notification instanceof WorkforceEnrollmentNotification) {
            $event->notification->recordDelivery();
        }
    }
}
