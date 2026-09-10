<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Domain\Models\GoodsReceipt;
use App\Modules\Inventory\Domain\Models\GoodsReceiptLine;
use App\Modules\Inventory\Domain\Models\InventoryReturn;
use App\Modules\Inventory\Domain\Models\InventoryReturnLine;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Procurement\Application\Contracts\SupplierReturnContract;
use App\Modules\Tenancy\Application\CurrentTenant;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class SupplierReturnWorkflow
{
    public function __construct(
        private CurrentTenant $tenant,
        private StockLedger $ledger,
        private SupplierReturnContract $procurement,
    ) {}

    public function returnRejected(
        GoodsReceipt $receipt,
        string $returnNumber,
        string $reason,
    ): InventoryReturn {
        return DB::transaction(function () use ($receipt, $returnNumber, $reason): InventoryReturn {
            $locked = GoodsReceipt::query()->with('lines')->whereKey($receipt->getKey())
                ->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'posted_with_rejections') {
                throw new DomainException('This goods receipt has no posted rejection awaiting return.');
            }
            $rejected = $locked->lines->filter(
                static fn (GoodsReceiptLine $line): bool => (int) $line->rejected_quantity_base > 0,
            );
            $return = InventoryReturn::query()->create([
                'return_number' => $returnNumber,
                'return_type' => 'supplier',
                'warehouse_id' => $locked->warehouse_id,
                'status' => 'draft',
                'source_type' => 'goods_receipt',
                'source_id' => $locked->getKey(),
                'reason' => $reason,
                'created_by' => $this->actorId(),
            ]);
            foreach ($rejected as $line) {
                if ($line->rejection_bin_id === null) {
                    throw new DomainException('Rejected stock has no controlled-custody bin.');
                }
                InventoryReturnLine::query()->create([
                    'inventory_return_id' => $return->getKey(),
                    'item_id' => $line->item_id,
                    'uom_id' => $line->uom_id,
                    'bin_id' => $line->rejection_bin_id,
                    'lot_id' => $line->lot_id,
                    'serial_id' => $line->serial_id,
                    'quantity_base' => $line->rejected_quantity_base,
                    'unit_cost_minor' => $line->unit_cost_minor,
                    'currency' => $line->currency,
                ]);
            }
            foreach ($return->lines()->get() as $line) {
                $this->ledger->post([
                    'item_id' => (string) $line->item_id,
                    'uom_id' => (string) $line->uom_id,
                    'from_bin_id' => (string) $line->bin_id,
                    'lot_id' => $line->lot_id,
                    'serial_id' => $line->serial_id,
                    'movement_type' => MovementType::SupplierReturn,
                    'quantity_base' => (int) $line->quantity_base,
                    'currency' => (string) $line->currency,
                    'unit_cost_minor' => (int) $line->unit_cost_minor,
                    'reference_type' => 'inventory_return',
                    'reference_id' => (string) $return->getKey(),
                    'reason_code' => 'supplier_rejection_return',
                    'idempotency_key' => "supplier-return:{$return->getKey()}:{$line->getKey()}",
                ]);
            }
            $return->forceFill(['status' => 'posted', 'posted_at' => now('UTC')])->save();
            $this->procurement->register(
                (string) $locked->purchase_order_id,
                (string) $locked->supplier_id,
                (string) $return->getKey(),
                $returnNumber,
                $reason,
            );

            return $return->load('lines');
        });
    }

    private function actorId(): string
    {
        return $this->tenant->get()->actorId
            ?? throw new DomainException('Inventory changes require an accountable actor.');
    }
}
