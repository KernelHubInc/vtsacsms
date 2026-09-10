<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain\Models;

use App\Modules\Organizations\Domain\OrganizationType;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Database\Factories\Modules\Organizations\Domain\Models\OrganizationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $tenant_id
 * @property OrganizationType $type
 */
final class Organization extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'parent_id',
        'type',
        'name',
        'code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Organization, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
