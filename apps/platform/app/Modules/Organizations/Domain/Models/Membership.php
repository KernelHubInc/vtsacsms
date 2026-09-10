<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain\Models;

use App\Models\User;
use App\Modules\Organizations\Domain\MembershipStatus;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Organizations\Domain\Models\MembershipFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $tenant_id
 * @property int $user_id
 * @property string|null $organization_id
 * @property MembershipStatus $status
 * @property CarbonImmutable $joined_at
 * @property CarbonImmutable|null $expires_at
 */
final class Membership extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<MembershipFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'organization_id',
        'status',
        'joined_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'joined_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<RoleAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
