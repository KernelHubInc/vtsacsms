<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Models;

use App\Modules\Inventory\Domain\ReservationStatus;
use Carbon\CarbonImmutable;

/**
 * @property ReservationStatus $status
 * @property int $requested_quantity_base
 * @property int $reserved_quantity_base
 * @property int $consumed_quantity_base
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $released_at
 */
final class StockReservation extends TenantInventoryModel
{
    protected $table = 'inventory_reservations';

    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'requested_quantity_base' => 'integer',
            'reserved_quantity_base' => 'integer',
            'consumed_quantity_base' => 'integer',
            'expires_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
        ];
    }
}
