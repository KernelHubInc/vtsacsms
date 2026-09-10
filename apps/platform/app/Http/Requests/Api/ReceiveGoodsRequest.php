<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;
use LogicException;

final class ReceiveGoodsRequest extends TenantFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = $this->tenantId();

        return [
            'purchase_order_id' => ['required', 'ulid', Rule::exists('purchase_orders', 'id')->where('tenant_id', $tenantId)],
            'supplier_id' => ['required', 'ulid', Rule::exists('procurement_suppliers', 'id')->where('tenant_id', $tenantId)],
            'warehouse_id' => ['required', 'ulid', Rule::exists('inventory_warehouses', 'id')->where('tenant_id', $tenantId)],
            'receipt_number' => [
                'required', 'string', 'max:80',
                Rule::unique('inventory_goods_receipts')->where('tenant_id', $tenantId),
            ],
            'supplier_delivery_reference' => ['nullable', 'string', 'max:120'],
            'received_at' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.purchase_order_line_id' => [
                'required', 'ulid', Rule::exists('purchase_order_lines', 'id')->where('tenant_id', $tenantId),
            ],
            'lines.*.item_id' => ['required', 'ulid', Rule::exists('inventory_items', 'id')->where('tenant_id', $tenantId)],
            'lines.*.uom_id' => [
                'required', 'ulid', Rule::exists('inventory_units_of_measure', 'id')->where('tenant_id', $tenantId),
            ],
            'lines.*.destination_bin_id' => [
                'required', 'ulid', Rule::exists('inventory_bins', 'id')->where('tenant_id', $tenantId),
            ],
            'lines.*.rejection_bin_id' => [
                'nullable', 'ulid', Rule::exists('inventory_bins', 'id')->where('tenant_id', $tenantId),
            ],
            'lines.*.lot_id' => ['nullable', 'ulid', Rule::exists('inventory_lots', 'id')->where('tenant_id', $tenantId)],
            'lines.*.serial_id' => ['nullable', 'ulid', Rule::exists('inventory_serials', 'id')->where('tenant_id', $tenantId)],
            'lines.*.received_quantity_base' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{
     *   purchase_order_id: string, supplier_id: string, warehouse_id: string,
     *   receipt_number: string, supplier_delivery_reference: string|null, received_at: string|null,
     *   lines: list<array{
     *     purchase_order_line_id: string, item_id: string, uom_id: string,
     *     destination_bin_id: string, rejection_bin_id: string|null,
     *     lot_id: string|null, serial_id: string|null, received_quantity_base: int
     *   }>
     * }
     */
    public function payload(): array
    {
        $data = $this->validated();
        $rawLines = $data['lines'] ?? null;
        if (! is_array($rawLines)) {
            throw new LogicException('Validated goods-receipt lines are unavailable.');
        }
        $lines = [];
        foreach ($rawLines as $rawLine) {
            if (! is_array($rawLine)) {
                throw new LogicException('A validated goods-receipt line is invalid.');
            }
            $lines[] = [
                'purchase_order_line_id' => (string) $rawLine['purchase_order_line_id'],
                'item_id' => (string) $rawLine['item_id'],
                'uom_id' => (string) $rawLine['uom_id'],
                'destination_bin_id' => (string) $rawLine['destination_bin_id'],
                'rejection_bin_id' => isset($rawLine['rejection_bin_id'])
                    ? (string) $rawLine['rejection_bin_id']
                    : null,
                'lot_id' => isset($rawLine['lot_id']) ? (string) $rawLine['lot_id'] : null,
                'serial_id' => isset($rawLine['serial_id']) ? (string) $rawLine['serial_id'] : null,
                'received_quantity_base' => (int) $rawLine['received_quantity_base'],
            ];
        }

        return [
            'purchase_order_id' => (string) $data['purchase_order_id'],
            'supplier_id' => (string) $data['supplier_id'],
            'warehouse_id' => (string) $data['warehouse_id'],
            'receipt_number' => (string) $data['receipt_number'],
            'supplier_delivery_reference' => isset($data['supplier_delivery_reference'])
                ? (string) $data['supplier_delivery_reference']
                : null,
            'received_at' => isset($data['received_at']) ? (string) $data['received_at'] : null,
            'lines' => $lines,
        ];
    }
}
