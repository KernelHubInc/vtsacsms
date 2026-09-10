<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Domain\Models;

use Carbon\CarbonImmutable;
use Database\Factories\Modules\Maintenance\Domain\Models\SlaPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int $acknowledge_seconds
 * @property int $resolve_seconds
 * @property list<string>|null $pause_states
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 */
final class SlaPolicy extends TenantMaintenanceModel
{
    /** @use HasFactory<SlaPolicyFactory> */
    use HasFactory;

    protected $table = 'maintenance_sla_policies';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'acknowledge_seconds' => 'integer',
            'resolve_seconds' => 'integer',
            'pause_states' => 'array',
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }
}
