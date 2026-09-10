<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

final class ReserveStockRequest extends TenantFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = $this->tenantId();

        return [
            'item_id' => ['required', 'ulid', Rule::exists('inventory_items', 'id')->where('tenant_id', $tenantId)],
            'warehouse_id' => ['required', 'ulid', Rule::exists('inventory_warehouses', 'id')->where('tenant_id', $tenantId)],
            'bin_id' => ['nullable', 'ulid', Rule::exists('inventory_bins', 'id')->where('tenant_id', $tenantId)],
            'lot_id' => ['nullable', 'ulid', Rule::exists('inventory_lots', 'id')->where('tenant_id', $tenantId)],
            'serial_id' => ['nullable', 'ulid', Rule::exists('inventory_serials', 'id')->where('tenant_id', $tenantId)],
            'requested_quantity_base' => ['required', 'integer', 'min:1'],
            'purpose_type' => ['required', 'string', 'max:48'],
            'purpose_id' => ['required', 'ulid'],
            'idempotency_key' => ['required', 'string', 'max:160'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'allow_partial' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{
     *   item_id: string, warehouse_id: string, bin_id: string|null, lot_id: string|null,
     *   serial_id: string|null, requested_quantity_base: int, purpose_type: string,
     *   purpose_id: string, idempotency_key: string, expires_at: string|null, allow_partial: bool
     * }
     */
    public function payload(): array
    {
        $data = $this->validated();

        return [
            'item_id' => (string) $data['item_id'],
            'warehouse_id' => (string) $data['warehouse_id'],
            'bin_id' => isset($data['bin_id']) ? (string) $data['bin_id'] : null,
            'lot_id' => isset($data['lot_id']) ? (string) $data['lot_id'] : null,
            'serial_id' => isset($data['serial_id']) ? (string) $data['serial_id'] : null,
            'requested_quantity_base' => (int) $data['requested_quantity_base'],
            'purpose_type' => (string) $data['purpose_type'],
            'purpose_id' => (string) $data['purpose_id'],
            'idempotency_key' => (string) $data['idempotency_key'],
            'expires_at' => isset($data['expires_at']) ? (string) $data['expires_at'] : null,
            'allow_partial' => (bool) ($data['allow_partial'] ?? false),
        ];
    }
}
