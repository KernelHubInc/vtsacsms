<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Models;

use App\Models\User;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $tenant_id
 * @property CarbonImmutable|null $contact_expires_at
 * @property CarbonImmutable|null $contact_verified_at
 * @property string|null $contact_email
 * @property string|null $request_fingerprint
 */
final class SupportTicket extends Model
{
    use BelongsToTenant, HasUlids;

    public const ACCOUNT_DELETION = 'account_deletion';

    public const LEVELS = [1 => 'Level 1 — Operator site admin', 2 => 'Level 2 — EVCS Admin', 3 => 'Level 3 — Kernel Hub (App Developer)'];

    protected $guarded = [];

    protected $attributes = ['support_level' => 2];

    protected $hidden = ['contact_email', 'request_fingerprint', 'open_fingerprint', 'idempotency_key'];

    protected function casts(): array
    {
        return [
            'escalation_level' => 'integer',
            'support_level' => 'integer',
            'resolved_at' => 'immutable_datetime',
            'contact_email' => 'encrypted',
            'contact_verified_at' => 'immutable_datetime',
            'contact_expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<SupportTicketMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class);
    }

    /** @return HasMany<SupportTicketImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(SupportTicketImage::class, 'ticket_id');
    }
}
