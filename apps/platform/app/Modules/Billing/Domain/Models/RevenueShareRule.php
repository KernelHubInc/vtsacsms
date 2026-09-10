<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Models;

use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** @property string $tenant_id @property string $operator_organization_id @property string|null $site_host_organization_id @property int $platform_basis_points @property int $operator_basis_points @property int $site_host_basis_points */
final class RevenueShareRule extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'effective_from' => 'immutable_datetime', 'effective_to' => 'immutable_datetime'];
    }
}
