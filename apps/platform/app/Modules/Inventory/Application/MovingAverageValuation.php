<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Inventory\Domain\ValuationMethod;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Support\Facades\DB;

final readonly class MovingAverageValuation
{
    public function __construct(
        private CurrentTenant $tenant,
        private StockAvailabilityQuery $availability,
    ) {}

    public function unitCostMinor(InventoryItem $item): int
    {
        if ($item->valuation_method === ValuationMethod::Standard) {
            return (int) ($item->standard_cost_minor ?? 0);
        }

        $quantity = $this->availability->forItem((string) $item->getKey())->onHandBase;
        if ($quantity <= 0) {
            return (int) ($item->standard_cost_minor ?? 0);
        }

        $tenantId = $this->tenant->get()->tenantId;
        $receivedValue = (int) DB::table('inventory_stock_movements')
            ->where('tenant_id', $tenantId)
            ->where('item_id', $item->getKey())
            ->whereNull('from_bin_id')
            ->sum('total_cost_minor');
        $issuedValue = (int) DB::table('inventory_stock_movements')
            ->where('tenant_id', $tenantId)
            ->where('item_id', $item->getKey())
            ->whereNull('to_bin_id')
            ->sum('total_cost_minor');

        return max(0, intdiv(max(0, $receivedValue - $issuedValue), $quantity));
    }
}
