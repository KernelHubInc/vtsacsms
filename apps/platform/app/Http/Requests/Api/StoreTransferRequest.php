<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;
use LogicException;

final class StoreTransferRequest extends TenantFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = $this->tenantId();
        $warehouseExists = Rule::exists('inventory_warehouses', 'id')->where('tenant_id', $tenantId);
        $binExists = Rule::exists('inventory_bins', 'id')->where('tenant_id', $tenantId);

        return [
            'transfer_number' => [
                'required', 'string', 'max:80',
                Rule::unique('inventory_transfers')->where('tenant_id', $tenantId),
            ],
            'source_warehouse_id' => ['required', 'ulid', $warehouseExists],
            'destination_warehouse_id' => ['required', 'ulid', 'different:source_warehouse_id', $warehouseExists],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.item_id' => ['required', 'ulid', Rule::exists('inventory_items', 'id')->where('tenant_id', $tenantId)],
            'lines.*.uom_id' => [
                'required', 'ulid', Rule::exists('inventory_units_of_measure', 'id')->where('tenant_id', $tenantId),
            ],
            'lines.*.source_bin_id' => ['required', 'ulid', $binExists],
            'lines.*.in_transit_bin_id' => ['required', 'ulid', $binExists],
            'lines.*.destination_bin_id' => ['required', 'ulid', $binExists],
            'lines.*.lot_id' => ['nullable', 'ulid', Rule::exists('inventory_lots', 'id')->where('tenant_id', $tenantId)],
            'lines.*.serial_id' => ['nullable', 'ulid', Rule::exists('inventory_serials', 'id')->where('tenant_id', $tenantId)],
            'lines.*.requested_quantity_base' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return list<array{
     *   item_id: string, uom_id: string, source_bin_id: string, in_transit_bin_id: string,
     *   destination_bin_id: string, lot_id: string|null, serial_id: string|null,
     *   requested_quantity_base: int
     * }>
     */
    public function lines(): array
    {
        $rawLines = $this->validated('lines');
        if (! is_array($rawLines)) {
            throw new LogicException('Validated transfer lines are unavailable.');
        }
        $lines = [];
        foreach ($rawLines as $rawLine) {
            if (! is_array($rawLine)) {
                throw new LogicException('A validated transfer line is invalid.');
            }
            $lines[] = [
                'item_id' => (string) $rawLine['item_id'],
                'uom_id' => (string) $rawLine['uom_id'],
                'source_bin_id' => (string) $rawLine['source_bin_id'],
                'in_transit_bin_id' => (string) $rawLine['in_transit_bin_id'],
                'destination_bin_id' => (string) $rawLine['destination_bin_id'],
                'lot_id' => isset($rawLine['lot_id']) ? (string) $rawLine['lot_id'] : null,
                'serial_id' => isset($rawLine['serial_id']) ? (string) $rawLine['serial_id'] : null,
                'requested_quantity_base' => (int) $rawLine['requested_quantity_base'],
            ];
        }

        return $lines;
    }
}
