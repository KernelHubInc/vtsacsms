<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain\Models;

use Database\Factories\Modules\Procurement\Domain\Models\SupplierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

final class Supplier extends TenantProcurementModel
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    protected $table = 'procurement_suppliers';

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }
}
