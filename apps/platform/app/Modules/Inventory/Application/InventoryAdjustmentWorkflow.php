<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Inventory\Domain\Models\AdjustmentLine;
use App\Modules\Inventory\Domain\Models\AdjustmentRequest;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Tenancy\Application\CurrentTenant;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class InventoryAdjustmentWorkflow
{
    public function __construct(
        private CurrentTenant $tenant,
        private StockLedger $ledger,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param list<array{
     *   item_id: string, uom_id: string, bin_id: string, lot_id?: string|null,
     *   serial_id?: string|null, quantity_delta_base: int, unit_cost_minor?: int|null,
     *   currency?: string|null
     * }> $lines
     */
    public function request(
        string $adjustmentNumber,
        string $warehouseId,
        string $reasonCode,
        string $reasonNotes,
        array $lines,
        ?string $sourceType = null,
        ?string $sourceId = null,
    ): AdjustmentRequest {
        return DB::transaction(function () use (
            $adjustmentNumber, $warehouseId, $reasonCode, $reasonNotes, $lines, $sourceType, $sourceId,
        ): AdjustmentRequest {
            if ($lines === [] || trim($reasonNotes) === '') {
                throw new DomainException('An adjustment requires lines and a documented reason.');
            }
            $request = AdjustmentRequest::query()->create([
                'adjustment_number' => $adjustmentNumber,
                'status' => 'pending_approval',
                'warehouse_id' => $warehouseId,
                'reason_code' => $reasonCode,
                'reason_notes' => $reasonNotes,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'requested_by' => $this->actorId(),
            ]);
            foreach ($lines as $line) {
                if ($line['quantity_delta_base'] === 0) {
                    throw new DomainException('Adjustment quantities cannot be zero.');
                }
                $item = InventoryItem::query()->whereKey($line['item_id'])->firstOrFail();
                AdjustmentLine::query()->create([
                    'adjustment_request_id' => $request->getKey(),
                    'item_id' => $item->getKey(),
                    'uom_id' => $line['uom_id'],
                    'bin_id' => $line['bin_id'],
                    'lot_id' => $line['lot_id'] ?? null,
                    'serial_id' => $line['serial_id'] ?? null,
                    'quantity_delta_base' => $line['quantity_delta_base'],
                    'unit_cost_minor' => $line['unit_cost_minor'] ?? $item->standard_cost_minor ?? 0,
                    'currency' => mb_strtoupper($line['currency'] ?? (string) $item->currency),
                ]);
            }
            $this->audit->record(new AuditEntry(
                'inventory.adjustment.requested',
                'inventory_adjustment',
                (string) $request->getKey(),
                AuditResult::Succeeded,
                reason: $reasonCode,
            ));

            return $request->load('lines');
        });
    }

    public function approve(AdjustmentRequest $request): AdjustmentRequest
    {
        if ($request->status !== 'pending_approval') {
            throw new DomainException('Only pending adjustments can be approved.');
        }
        if ($request->requested_by === $this->actorId()) {
            throw new DomainException('The requester cannot approve their own inventory adjustment.');
        }
        $request->forceFill([
            'status' => 'approved',
            'approved_by' => $this->actorId(),
            'approved_at' => now('UTC'),
        ])->save();
        $this->audit->record(new AuditEntry(
            'inventory.adjustment.approved',
            'inventory_adjustment',
            (string) $request->getKey(),
            AuditResult::Succeeded,
            reason: (string) $request->reason_code,
        ));

        return $request;
    }

    public function reject(AdjustmentRequest $request, string $reason): AdjustmentRequest
    {
        if ($request->status !== 'pending_approval' || trim($reason) === '') {
            throw new DomainException('Only a pending adjustment can be rejected with a reason.');
        }
        $request->forceFill(['status' => 'rejected', 'reason_notes' => $request->reason_notes."\n".$reason])->save();

        return $request;
    }

    public function post(AdjustmentRequest $request): AdjustmentRequest
    {
        return DB::transaction(function () use ($request): AdjustmentRequest {
            $locked = AdjustmentRequest::query()->with('lines')->whereKey($request->getKey())
                ->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'approved') {
                throw new DomainException('Only approved adjustments can be posted.');
            }
            foreach ($locked->lines as $line) {
                $increase = (int) $line->quantity_delta_base > 0;
                $this->ledger->post([
                    'item_id' => (string) $line->item_id,
                    'uom_id' => (string) $line->uom_id,
                    'from_bin_id' => $increase ? null : (string) $line->bin_id,
                    'to_bin_id' => $increase ? (string) $line->bin_id : null,
                    'lot_id' => $line->lot_id,
                    'serial_id' => $line->serial_id,
                    'movement_type' => $increase
                        ? MovementType::AdjustmentIncrease
                        : MovementType::AdjustmentDecrease,
                    'quantity_base' => abs((int) $line->quantity_delta_base),
                    'currency' => (string) $line->currency,
                    'unit_cost_minor' => (int) $line->unit_cost_minor,
                    'reference_type' => 'inventory_adjustment',
                    'reference_id' => (string) $locked->getKey(),
                    'reason_code' => (string) $locked->reason_code,
                    'idempotency_key' => "adjustment:{$locked->getKey()}:{$line->getKey()}",
                ]);
            }
            $locked->forceFill(['status' => 'posted', 'posted_at' => now('UTC')])->save();

            return $locked;
        });
    }

    private function actorId(): string
    {
        return $this->tenant->get()->actorId
            ?? throw new DomainException('Inventory changes require an accountable actor.');
    }
}
