<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Domain;

enum WorkOrderState: string
{
    case Reported = 'reported';
    case Triaged = 'triaged';
    case Planned = 'planned';
    case Scheduled = 'scheduled';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    case AwaitingParts = 'awaiting_parts';
    case AwaitingAccess = 'awaiting_access';
    case AwaitingExternal = 'awaiting_external';
    case AwaitingSafetyClearance = 'awaiting_safety_clearance';
    case Completed = 'completed';
    case VerificationRequired = 'verification_required';
    case Verified = 'verified';
    case Closed = 'closed';
    case Canceled = 'canceled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Closed, self::Canceled], true);
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, match ($this) {
            self::Reported => [self::Triaged, self::Canceled],
            self::Triaged => [self::Planned, self::Assigned, self::Canceled],
            self::Planned => [
                self::Scheduled, self::Assigned, self::AwaitingParts, self::AwaitingAccess,
                self::AwaitingExternal, self::Canceled,
            ],
            self::Scheduled => [self::Assigned, self::Planned, self::AwaitingParts, self::AwaitingAccess, self::Canceled],
            self::Assigned => [self::InProgress, self::Scheduled, self::AwaitingParts, self::AwaitingAccess, self::Canceled],
            self::InProgress => [
                self::OnHold, self::AwaitingParts, self::AwaitingAccess, self::AwaitingExternal,
                self::AwaitingSafetyClearance, self::Completed,
            ],
            self::OnHold => [self::InProgress, self::Planned, self::Canceled],
            self::AwaitingParts => [self::InProgress, self::Planned, self::Canceled],
            self::AwaitingAccess => [self::InProgress, self::Scheduled, self::Canceled],
            self::AwaitingExternal => [self::InProgress, self::Planned, self::Canceled],
            self::AwaitingSafetyClearance => [self::InProgress, self::Completed],
            self::Completed => [self::VerificationRequired, self::Verified, self::InProgress],
            self::VerificationRequired => [self::Verified, self::InProgress],
            self::Verified => [self::Closed, self::InProgress],
            self::Closed, self::Canceled => [],
        }, true);
    }

    public function pausesSla(): bool
    {
        return in_array($this, [
            self::OnHold,
            self::AwaitingParts,
            self::AwaitingAccess,
            self::AwaitingExternal,
            self::AwaitingSafetyClearance,
        ], true);
    }
}
