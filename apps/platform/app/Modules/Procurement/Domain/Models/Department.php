<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain\Models;

use Database\Factories\Modules\Procurement\Domain\Models\DepartmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

final class Department extends TenantProcurementModel
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    protected $table = 'procurement_departments';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
