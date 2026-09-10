<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Models;

use App\Modules\Inventory\Domain\TransferStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property TransferStatus $status
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $dispatched_at
 * @property CarbonImmutable|null $received_at
 */
final class StockTransfer extends TenantInventoryModel
{
    protected $table = 'inventory_transfers';

    protected function casts(): array
    {
        return [
            'status' => TransferStatus::class,
            'approved_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<StockTransferLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class, 'transfer_id');
    }
}
