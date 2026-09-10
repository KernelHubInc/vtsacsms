<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Inventory\Domain\CountStatus;
use App\Modules\Inventory\Domain\Models\CountLine;
use App\Modules\Inventory\Domain\Models\CountPlan;
use App\Modules\Inventory\Domain\Models\CountSheet;
use App\Modules\Inventory\Domain\Models\InventoryBin;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Tenancy\Application\CurrentTenant;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class StockCountWorkflow
{
    public function __construct(
        private CurrentTenant $tenant,
        private StockAvailabilityQuery $availability,
        private InventoryAdjustmentWorkflow $adjustments,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param list<array{
     *   stock_location_id: string,
     *   lines: list<array{item_id: string, bin_id: string, lot_id?: string|null, serial_id?: string|null}>
     * }> $sheets
     */
    public function create(
        string $planNumber,
        string $countType,
        string $warehouseId,
        \DateTimeInterface|string $scheduledFor,
        bool $blindCount,
        array $sheets,
    ): CountPlan {
        return DB::transaction(function () use (
            $planNumber, $countType, $warehouseId, $scheduledFor, $blindCount, $sheets,
        ): CountPlan {
            if ($sheets === []) {
                throw new DomainException('A count plan requires at least one sheet.');
            }
            $plan = CountPlan::query()->create([
                'plan_number' => $planNumber,
                'count_type' => $countType,
                'warehouse_id' => $warehouseId,
                'status' => CountStatus::Scheduled,
                'blind_count' => $blindCount,
                'scheduled_for' => $scheduledFor,
                'created_by' => $this->actorId(),
            ]);
            foreach ($sheets as $sheetData) {
                $sheet = CountSheet::query()->create([
                    'count_plan_id' => $plan->getKey(),
                    'stock_location_id' => $sheetData['stock_location_id'],
                    'round' => 1,
                    'status' => 'open',
                ]);
                foreach ($sheetData['lines'] as $line) {
                    InventoryItem::query()->whereKey($line['item_id'])->firstOrFail();
                    InventoryBin::query()->whereKey($line['bin_id'])
                        ->where('stock_location_id', $sheetData['stock_location_id'])->firstOrFail();
                    CountLine::query()->create([
                        'count_sheet_id' => $sheet->getKey(),
                        ...$line,
                        'system_quantity_base' => $blindCount
                            ? null
                            : $this->availability->forItem(
                                $line['item_id'],
                                $warehouseId,
                                $line['bin_id'],
                            )->onHandBase,
                    ]);
                }
            }

            return $plan->load('sheets.lines');
        });
    }

    public function start(CountPlan $plan): CountPlan
    {
        if ($plan->status !== CountStatus::Scheduled) {
            throw new DomainException('Only a scheduled count can start.');
        }
        $plan->forceFill(['status' => CountStatus::InProgress])->save();
        $plan->sheets()->update(['status' => 'in_progress', 'started_at' => now('UTC')]);

        return $plan;
    }

    /** @param array<string, int> $countsByLineId */
    public function submitSheet(CountSheet $sheet, array $countsByLineId, int $recountThresholdBase = 0): CountSheet
    {
        return DB::transaction(function () use ($sheet, $countsByLineId, $recountThresholdBase): CountSheet {
            $locked = CountSheet::query()->with(['lines'])->whereKey($sheet->getKey())->lockForUpdate()->firstOrFail();
            $plan = CountPlan::query()->whereKey($locked->count_plan_id)->lockForUpdate()->firstOrFail();
            if ($plan->status !== CountStatus::InProgress || $locked->status !== 'in_progress') {
                throw new DomainException('Only an in-progress count sheet can be submitted.');
            }
            $needsRecount = false;
            foreach ($locked->lines as $line) {
                $counted = $countsByLineId[(string) $line->getKey()] ?? null;
                if (! is_int($counted) || $counted < 0) {
                    throw new DomainException('Every count line requires a non-negative integer observation.');
                }
                $system = $this->availability->forItem(
                    (string) $line->item_id,
                    (string) $plan->warehouse_id,
                    (string) $line->bin_id,
                )->onHandBase;
                $variance = $counted - $system;
                $needsRecount = $needsRecount || abs($variance) > $recountThresholdBase;
                $line->forceFill([
                    'system_quantity_base' => $system,
                    'counted_quantity_base' => $counted,
                    'variance_quantity_base' => $variance,
                    'counted_by' => $this->actorId(),
                    'counted_at' => now('UTC'),
                ])->save();
            }
            $locked->forceFill(['status' => 'submitted', 'submitted_at' => now('UTC')])->save();
            $plan->forceFill([
                'status' => $needsRecount ? CountStatus::RecountRequired : CountStatus::Review,
            ])->save();

            return $locked;
        });
    }

    public function createRecount(CountSheet $sheet): CountSheet
    {
        return DB::transaction(function () use ($sheet): CountSheet {
            $source = CountSheet::query()->with('lines')->whereKey($sheet->getKey())->firstOrFail();
            $plan = CountPlan::query()->whereKey($source->count_plan_id)->lockForUpdate()->firstOrFail();
            if ($plan->status !== CountStatus::RecountRequired) {
                throw new DomainException('A recount can only follow a threshold variance.');
            }
            $recount = CountSheet::query()->create([
                'count_plan_id' => $plan->getKey(),
                'stock_location_id' => $source->stock_location_id,
                'round' => (int) $source->round + 1,
                'status' => 'in_progress',
                'started_at' => now('UTC'),
            ]);
            foreach ($source->lines as $line) {
                CountLine::query()->create([
                    'count_sheet_id' => $recount->getKey(),
                    'item_id' => $line->item_id,
                    'bin_id' => $line->bin_id,
                    'lot_id' => $line->lot_id,
                    'serial_id' => $line->serial_id,
                ]);
            }
            $plan->forceFill(['status' => CountStatus::InProgress])->save();

            return $recount->load('lines');
        });
    }

    public function approve(CountPlan $plan): CountPlan
    {
        if ($plan->status !== CountStatus::Review) {
            throw new DomainException('Only a reviewed count can be approved.');
        }
        if ($plan->created_by === $this->actorId()) {
            throw new DomainException('The count planner cannot approve their own count.');
        }
        $plan->forceFill([
            'status' => CountStatus::Approved,
            'approved_by' => $this->actorId(),
            'approved_at' => now('UTC'),
        ])->save();
        $this->audit->record(new AuditEntry(
            'inventory.count.approved',
            'inventory_count_plan',
            (string) $plan->getKey(),
            AuditResult::Succeeded,
        ));

        return $plan;
    }

    public function post(CountPlan $plan): CountPlan
    {
        return DB::transaction(function () use ($plan): CountPlan {
            $locked = CountPlan::query()->with('sheets.lines')->whereKey($plan->getKey())
                ->lockForUpdate()->firstOrFail();
            if ($locked->status !== CountStatus::Approved) {
                throw new DomainException('Only an approved count can post adjustments.');
            }
            $latestSheets = $locked->sheets->sortByDesc('round')->unique('stock_location_id');
            $lines = [];
            foreach ($latestSheets as $sheet) {
                foreach ($sheet->lines as $line) {
                    if ((int) $line->variance_quantity_base === 0) {
                        continue;
                    }
                    $item = InventoryItem::query()->whereKey($line->item_id)->firstOrFail();
                    $lines[] = [
                        'item_id' => (string) $line->item_id,
                        'uom_id' => (string) $item->base_uom_id,
                        'bin_id' => (string) $line->bin_id,
                        'lot_id' => $line->lot_id,
                        'serial_id' => $line->serial_id,
                        'quantity_delta_base' => (int) $line->variance_quantity_base,
                        'unit_cost_minor' => (int) ($item->standard_cost_minor ?? 0),
                        'currency' => (string) $item->currency,
                    ];
                }
            }
            if ($lines !== []) {
                $adjustment = $this->adjustments->request(
                    'COUNT-'.$locked->plan_number,
                    (string) $locked->warehouse_id,
                    'physical_count_variance',
                    'Adjustment generated from approved count plan '.$locked->plan_number,
                    $lines,
                    'inventory_count_plan',
                    (string) $locked->getKey(),
                );
                $adjustment->forceFill([
                    'status' => 'approved',
                    'approved_by' => $locked->approved_by,
                    'approved_at' => $locked->approved_at,
                ])->save();
                $this->adjustments->post($adjustment);
            }
            $locked->forceFill(['status' => CountStatus::Posted, 'posted_at' => now('UTC')])->save();

            return $locked;
        });
    }

    private function actorId(): string
    {
        return $this->tenant->get()->actorId
            ?? throw new DomainException('Inventory changes require an accountable actor.');
    }
}
