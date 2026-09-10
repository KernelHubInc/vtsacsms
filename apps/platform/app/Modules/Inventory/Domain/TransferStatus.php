<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

enum TransferStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Picking = 'picking';
    case Dispatched = 'dispatched';
    case PartiallyReceived = 'partially_received';
    case Discrepancy = 'discrepancy';
    case Received = 'received';
    case Closed = 'closed';
    case Rejected = 'rejected';
    case Canceled = 'canceled';
}
