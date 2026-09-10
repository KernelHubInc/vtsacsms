<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain\Models;

use App\Models\User;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $tenant_id
 * @property ScopeType $scope_type
 * @property string|null $scope_id
 * @property CarbonImmutable|null $expires_at
 */
final class RoleAssignment extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'membership_id',
        'role_id',
        'scope_type',
        'scope_id',
        'granted_by_user_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'scope_type' => ScopeType::class,
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Membership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<User, $this> */
    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
