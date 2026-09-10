<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Models;

use App\Models\User;
use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $tenant_id
 * @property int $user_id
 * @property CarbonImmutable $last_active_at
 * @property CarbonImmutable|null $revoked_at
 */
final class AuthSession extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'session_id_hash',
        'device_name',
        'ip_address',
        'user_agent',
        'last_active_at',
        'revoked_at',
    ];

    protected $hidden = ['session_id_hash'];

    protected function casts(): array
    {
        return [
            'last_active_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
