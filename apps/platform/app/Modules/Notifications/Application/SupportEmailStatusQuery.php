<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Domain\Models\SupportEmailDelivery;
use App\Modules\Support\Domain\Models\SupportTicket;
use Illuminate\Database\Eloquent\Builder;

final readonly class SupportEmailStatusQuery
{
    /**
     * @param  Builder<SupportTicket>  $tickets
     * @return Builder<SupportTicket>
     */
    public function withLatestStatus(Builder $tickets): Builder
    {
        $latest = SupportEmailDelivery::query()->whereColumn('ticket_id', 'support_tickets.id')
            ->whereColumn('support_email_deliveries.tenant_id', 'support_tickets.tenant_id')
            ->selectRaw("CASE WHEN sent_at IS NOT NULL THEN 'accepted' WHEN failed_at IS NOT NULL THEN 'failed' WHEN attempt_count > 0 THEN 'retrying' ELSE 'queued' END")
            ->latest('id')->limit(1);

        return $tickets->select('support_tickets.*')->selectSub($latest, 'customer_email_state');
    }
}
