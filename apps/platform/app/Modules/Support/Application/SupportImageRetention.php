<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Support\Domain\Models\SupportTicketImage;
use Illuminate\Support\Facades\DB;

final readonly class SupportImageRetention
{
    public function purgeDue(int $limit = 100): int
    {
        return DB::transaction(function () use ($limit): int {
            $images = SupportTicketImage::query()->whereNotNull('content')
                ->whereHas('ticket', fn ($query) => $query->whereIn('status', ['resolved', 'closed'])
                    ->where('resolved_at', '<=', now('UTC')->subDays((int) config('support.image_retention_days', 90))))
                ->orderBy('id')->limit($limit)->lockForUpdate()->get(['id', 'tenant_id', 'ticket_id']);
            foreach ($images as $image) {
                $image->update(['content' => null, 'purged_at' => now('UTC')]);
                app(AuditRecorder::class)->record(new AuditEntry('support.image.expired', 'support_ticket', (string) $image->ticket_id,
                    AuditResult::Succeeded, metadata: ['image_id' => $image->getKey()]));
            }

            return $images->count();
        });
    }
}
