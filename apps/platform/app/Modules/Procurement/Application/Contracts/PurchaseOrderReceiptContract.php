<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application\Contracts;

interface PurchaseOrderReceiptContract
{
    /**
     * @return array{
     *   purchase_order_id: string, purchase_order_line_id: string, supplier_id: string,
     *   item_id: string, uom_id: string, remaining_quantity_base: int,
     *   unit_cost_minor: int, currency: string
     * }
     */
    public function receivableLine(string $purchaseOrderId, string $purchaseOrderLineId): array;

    /**
     * @param list<array{
     *   purchase_order_line_id: string,
     *   received_quantity_base: int,
     *   accepted_quantity_base: int,
     *   rejected_quantity_base: int
     * }> $lines
     */
    public function registerReceipt(string $purchaseOrderId, string $receiptId, array $lines): void;
}
