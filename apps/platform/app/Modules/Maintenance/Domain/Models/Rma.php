<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Domain\Models;

use Carbon\CarbonImmutable;

/**
 * @property int $claimed_minor
 * @property int $approved_minor
 * @property int $recovered_minor
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $resolved_at
 */
final class Rma extends TenantMaintenanceModel
{
    protected $table = 'maintenance_rmas';

    protected function casts(): array
    {
        return [
            'claimed_minor' => 'integer',
            'approved_minor' => 'integer',
            'recovered_minor' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
