<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Support\Domain\Models\SupportTicket;
use Illuminate\Support\Facades\DB;

final readonly class SupportContactRetention
{
    public function purgeDue(int $limit = 100): int
    {
        return DB::transaction(function () use ($limit): int {
            $tickets = SupportTicket::query()->where('source', 'website')->whereIn('status', ['resolved', 'closed'])
                ->whereNotNull('contact_email')->whereNotNull('contact_expires_at')->where('contact_expires_at', '<=', now('UTC'))
                ->orderBy('id')->limit($limit)->lockForUpdate()->get();
            foreach ($tickets as $ticket) {
                $ticket->update(['contact_email' => null]);
                app(AuditRecorder::class)->record(new AuditEntry('support.contact.expired', 'support_ticket', (string) $ticket->getKey(), AuditResult::Succeeded));
            }

            return $tickets->count();
        });
    }
}
