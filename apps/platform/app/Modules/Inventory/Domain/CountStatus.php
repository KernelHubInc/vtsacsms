<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

enum CountStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case RecountRequired = 'recount_required';
    case Review = 'review';
    case Approved = 'approved';
    case Posted = 'posted';
    case Closed = 'closed';
    case Canceled = 'canceled';
}
