<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Domain\Models;

use Carbon\CarbonImmutable;

/**
 * @property CarbonImmutable|null $due_at
 * @property CarbonImmutable $generated_at
 */
final class PreventiveOccurrence extends TenantMaintenanceModel
{
    protected $table = 'maintenance_preventive_occurrences';

    protected function casts(): array
    {
        return [
            'trigger_value' => 'integer',
            'due_at' => 'immutable_datetime',
            'generated_at' => 'immutable_datetime',
        ];
    }
}
