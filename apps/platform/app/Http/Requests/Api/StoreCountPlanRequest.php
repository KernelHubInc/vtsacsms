<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;
use LogicException;

final class StoreCountPlanRequest extends TenantFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = $this->tenantId();

        return [
            'plan_number' => [
                'required', 'string', 'max:80',
                Rule::unique('inventory_count_plans')->where('tenant_id', $tenantId),
            ],
            'count_type' => ['required', 'in:physical,cycle'],
            'warehouse_id' => ['required', 'ulid', Rule::exists('inventory_warehouses', 'id')->where('tenant_id', $tenantId)],
            'scheduled_for' => ['required', 'date_format:Y-m-d'],
            'blind_count' => ['required', 'boolean'],
            'sheets' => ['required', 'array', 'min:1', 'max:500'],
            'sheets.*.stock_location_id' => [
                'required', 'ulid', Rule::exists('inventory_stock_locations', 'id')->where('tenant_id', $tenantId),
            ],
            'sheets.*.lines' => ['required', 'array', 'min:1', 'max:1000'],
            'sheets.*.lines.*.item_id' => [
                'required', 'ulid', Rule::exists('inventory_items', 'id')->where('tenant_id', $tenantId),
            ],
            'sheets.*.lines.*.bin_id' => [
                'required', 'ulid', Rule::exists('inventory_bins', 'id')->where('tenant_id', $tenantId),
            ],
            'sheets.*.lines.*.lot_id' => [
                'nullable', 'ulid', Rule::exists('inventory_lots', 'id')->where('tenant_id', $tenantId),
            ],
            'sheets.*.lines.*.serial_id' => [
                'nullable', 'ulid', Rule::exists('inventory_serials', 'id')->where('tenant_id', $tenantId),
            ],
        ];
    }

    /**
     * @return list<array{
     *   stock_location_id: string,
     *   lines: list<array{item_id: string, bin_id: string, lot_id: string|null, serial_id: string|null}>
     * }>
     */
    public function sheets(): array
    {
        $rawSheets = $this->validated('sheets');
        if (! is_array($rawSheets)) {
            throw new LogicException('Validated count sheets are unavailable.');
        }
        $sheets = [];
        foreach ($rawSheets as $rawSheet) {
            if (! is_array($rawSheet) || ! is_array($rawSheet['lines'] ?? null)) {
                throw new LogicException('A validated count sheet is invalid.');
            }
            $lines = [];
            foreach ($rawSheet['lines'] as $rawLine) {
                if (! is_array($rawLine)) {
                    throw new LogicException('A validated count line is invalid.');
                }
                $lines[] = [
                    'item_id' => (string) $rawLine['item_id'],
                    'bin_id' => (string) $rawLine['bin_id'],
                    'lot_id' => isset($rawLine['lot_id']) ? (string) $rawLine['lot_id'] : null,
                    'serial_id' => isset($rawLine['serial_id']) ? (string) $rawLine['serial_id'] : null,
                ];
            }
            $sheets[] = [
                'stock_location_id' => (string) $rawSheet['stock_location_id'],
                'lines' => $lines,
            ];
        }

        return $sheets;
    }
}
