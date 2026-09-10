<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain\Models;

final class SupplierReturn extends TenantProcurementModel
{
    protected $table = 'procurement_supplier_returns';

    protected function casts(): array
    {
        return ['shipped_at' => 'immutable_datetime', 'acknowledged_at' => 'immutable_datetime'];
    }
}
