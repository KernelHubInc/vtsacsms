<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

enum ProviderAttemptState: string
{
    case Prepared = 'prepared';
    case Submitted = 'submitted';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case OutcomeUnknown = 'outcome_unknown';
    case ManualReview = 'manual_review';
    case Canceled = 'canceled';
}
