<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Domain\Models;

final class PartRequirement extends TenantMaintenanceModel
{
    protected $table = 'maintenance_part_requirements';

    protected function casts(): array
    {
        return [
            'required_quantity_base' => 'integer',
            'reserved_quantity_base' => 'integer',
            'issued_quantity_base' => 'integer',
            'returned_quantity_base' => 'integer',
        ];
    }
}
