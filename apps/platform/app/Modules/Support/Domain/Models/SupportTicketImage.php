<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Models;

use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SupportTicketImage extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = [];

    protected $hidden = ['content'];

    protected function casts(): array
    {
        return ['content' => 'encrypted', 'purged_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<SupportTicket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }
}
