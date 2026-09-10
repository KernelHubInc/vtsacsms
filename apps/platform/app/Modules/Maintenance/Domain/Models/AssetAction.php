<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Domain\Models;

use Carbon\CarbonImmutable;

/**
 * @property array<string, mixed>|null $evidence
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $decided_at
 */
final class AssetAction extends TenantMaintenanceModel
{
    protected $table = 'maintenance_asset_actions';

    protected function casts(): array
    {
        return ['evidence' => 'array', 'requested_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime'];
    }
}
