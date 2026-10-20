<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

enum KycStatus: string
{
    case NotStarted = 'NOT_STARTED';
    case InProgress = 'IN_PROGRESS';
    case PendingUpload = 'PENDING_UPLOAD';
    case Submitted = 'SUBMITTED';
    case Processing = 'PROCESSING';
    case NeedsReview = 'NEEDS_REVIEW';
    case ActionRequired = 'ACTION_REQUIRED';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Expired = 'EXPIRED';
    case Cancelled = 'CANCELLED';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::InProgress => [self::PendingUpload, self::Cancelled],
            self::PendingUpload => [self::Submitted, self::Processing, self::NeedsReview, self::ActionRequired, self::Approved, self::Rejected, self::Cancelled, self::Expired],
            self::Submitted, self::Processing => [self::Processing, self::NeedsReview, self::ActionRequired, self::Approved, self::Rejected, self::Cancelled, self::Expired],
            self::NeedsReview => [self::Approved, self::Rejected, self::ActionRequired, self::Cancelled, self::Expired],
            self::Approved => [self::Expired, self::Cancelled],
            self::ActionRequired, self::Rejected => [self::Cancelled],
            default => [],
        }, true);
    }

    public function canResubmit(): bool
    {
        return in_array($this, [self::ActionRequired, self::Rejected, self::Expired, self::Cancelled], true);
    }
}
