<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain\Models;

final class VendorInvoiceLine extends TenantProcurementModel
{
    protected function casts(): array
    {
        return [
            'quantity_base' => 'integer',
            'unit_price_minor' => 'integer',
            'total_minor' => 'integer',
        ];
    }
}
