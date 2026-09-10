<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Inventory\Domain\Models\ReorderPoint;
use App\Modules\Inventory\Domain\Models\Warehouse;
use App\Modules\Tenancy\Application\CurrentTenant;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final readonly class InventoryCatalogService
{
    public function __construct(
        private CurrentTenant $tenant,
        private AuditRecorder $audit,
    ) {}

    public function setItemArchived(InventoryItem $item, bool $archived, string $reason): InventoryItem
    {
        return $this->change(
            $item,
            [
                'archived_at' => $archived ? now('UTC') : null,
                'is_active' => ! $archived,
            ],
            $archived ? 'inventory.item.archived' : 'inventory.item.restored',
            'inventory_item',
            $reason,
        );
    }

    public function setWarehouseActive(Warehouse $warehouse, bool $active, string $reason): Warehouse
    {
        return $this->change(
            $warehouse,
            ['is_active' => $active],
            $active ? 'inventory.warehouse.reactivated' : 'inventory.warehouse.deactivated',
            'inventory_warehouse',
            $reason,
        );
    }

    public function setReorderPointActive(ReorderPoint $point, bool $active, string $reason): ReorderPoint
    {
        return $this->change(
            $point,
            ['is_active' => $active],
            $active ? 'inventory.reorder_point.reactivated' : 'inventory.reorder_point.deactivated',
            'inventory_reorder_point',
            $reason,
        );
    }

    /**
     * @template TModel of Model
     *
     * @param  TModel  $record
     * @param  array<string, mixed>  $changes
     * @return TModel
     */
    private function change(
        Model $record,
        array $changes,
        string $action,
        string $targetType,
        string $reason,
    ): Model {
        $context = $this->tenant->get();
        if (! hash_equals($context->tenantId, (string) $record->getAttribute('tenant_id'))) {
            throw new DomainException('The inventory record is outside the active tenant.');
        }

        return DB::transaction(function () use ($action, $changes, $reason, $record, $targetType): Model {
            /** @var TModel $locked */
            $locked = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $before = $locked->attributesToArray();
            $locked->forceFill($changes)->save();

            $this->audit->record(new AuditEntry(
                $action,
                $targetType,
                (string) $locked->getKey(),
                AuditResult::Succeeded,
                reason: $reason,
                before: $before,
                after: $locked->fresh()->attributesToArray(),
            ));

            return $locked;
        });
    }
}
