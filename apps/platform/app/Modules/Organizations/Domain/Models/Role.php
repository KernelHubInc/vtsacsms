<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain\Models;

use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Database\Factories\Modules\Organizations\Domain\Models\RoleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property string $tenant_id */
final class Role extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'key',
        'name',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasMany<RoleAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }
}
