<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain;

enum PurchaseRequestStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderApproval = 'under_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case RevisionRequested = 'revision_requested';
    case Sourcing = 'sourcing';
    case Ordered = 'ordered';
    case Closed = 'closed';
    case Canceled = 'canceled';
}
