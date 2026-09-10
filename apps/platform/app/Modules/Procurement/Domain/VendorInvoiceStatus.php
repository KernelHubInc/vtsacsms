<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain;

enum VendorInvoiceStatus: string
{
    case Submitted = 'submitted';
    case Matching = 'matching';
    case Matched = 'matched';
    case Discrepancy = 'discrepancy';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Exported = 'exported';
}
