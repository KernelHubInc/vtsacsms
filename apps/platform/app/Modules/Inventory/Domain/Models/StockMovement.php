<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Models;

use App\Modules\Inventory\Domain\MovementType;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * @property MovementType $movement_type
 * @property int $quantity_base
 * @property int $unit_cost_minor
 * @property int $total_cost_minor
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $posted_at
 */
final class StockMovement extends TenantInventoryModel
{
    protected $table = 'inventory_stock_movements';

    protected function casts(): array
    {
        return [
            'movement_type' => MovementType::class,
            'quantity_base' => 'integer',
            'unit_cost_minor' => 'integer',
            'total_cost_minor' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new DomainException('Stock movements are immutable; post a compensating movement.');
        });
        self::deleting(static function (): never {
            throw new DomainException('Stock movements are immutable; post a compensating movement.');
        });
    }
}
