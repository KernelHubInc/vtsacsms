<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain\Models;

use Database\Factories\Modules\Procurement\Domain\Models\CostCenterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

final class CostCenter extends TenantProcurementModel
{
    /** @use HasFactory<CostCenterFactory> */
    use HasFactory;

    protected $table = 'procurement_cost_centers';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
