<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

enum MovementType: string
{
    case Receipt = 'receipt';
    case Issue = 'issue';
    case TransferDispatch = 'transfer_dispatch';
    case TransferReceive = 'transfer_receive';
    case CustomerReturn = 'customer_return';
    case SupplierReturn = 'supplier_return';
    case WorkOrderIssue = 'work_order_issue';
    case WorkOrderReturn = 'work_order_return';
    case AdjustmentIncrease = 'adjustment_increase';
    case AdjustmentDecrease = 'adjustment_decrease';
    case Reversal = 'reversal';
}
