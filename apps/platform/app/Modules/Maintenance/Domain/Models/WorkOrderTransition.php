<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Domain\Models;

use Carbon\CarbonImmutable;
use DomainException;

/**
 * @property int $version
 * @property CarbonImmutable $occurred_at
 */
final class WorkOrderTransition extends TenantMaintenanceModel
{
    protected $table = 'maintenance_work_order_transitions';

    protected function casts(): array
    {
        return ['version' => 'integer', 'occurred_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new DomainException('Work-order transitions are immutable.');
        });
        self::deleting(static function (): never {
            throw new DomainException('Work-order transitions are immutable.');
        });
    }
}
