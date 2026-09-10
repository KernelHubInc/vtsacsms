<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain\Models;

final class ApprovalRule extends TenantProcurementModel
{
    protected $table = 'procurement_approval_rules';

    protected function casts(): array
    {
        return [
            'minimum_amount_minor' => 'integer',
            'maximum_amount_minor' => 'integer',
            'sequence' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
