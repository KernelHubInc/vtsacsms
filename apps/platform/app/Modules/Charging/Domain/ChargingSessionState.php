<?php

declare(strict_types=1);

namespace App\Modules\Charging\Domain;

enum ChargingSessionState: string
{
    case Requested = 'requested';
    case Authorizing = 'authorizing';
    case Authorized = 'authorized';
    case Starting = 'starting';
    case Charging = 'charging';
    case SuspendedByEv = 'suspended_by_ev';
    case SuspendedByEvse = 'suspended_by_evse';
    case Stopping = 'stopping';
    case Finalizing = 'finalizing';
    case ReviewRequired = 'review_required';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled, self::Expired], true);
    }

    public function isPhysicallyActive(): bool
    {
        return in_array($this, [self::Charging, self::SuspendedByEv, self::SuspendedByEvse, self::Stopping], true);
    }

    public function canTransitionTo(self $next): bool
    {
        if ($next === $this) {
            return true;
        }

        return in_array($next, match ($this) {
            self::Requested => [self::Authorizing, self::Starting, self::Cancelled, self::Expired],
            self::Authorizing => [self::Authorized, self::Starting, self::Failed, self::Cancelled, self::Expired],
            self::Authorized => [self::Starting, self::Cancelled, self::Expired],
            self::Starting => [self::Charging, self::SuspendedByEv, self::SuspendedByEvse, self::Stopping, self::Finalizing, self::Failed, self::Cancelled, self::Expired],
            self::Charging => [self::SuspendedByEv, self::SuspendedByEvse, self::Stopping, self::Finalizing],
            self::SuspendedByEv => [self::Charging, self::SuspendedByEvse, self::Stopping, self::Finalizing],
            self::SuspendedByEvse => [self::Charging, self::SuspendedByEv, self::Stopping, self::Finalizing],
            self::Stopping => [self::Charging, self::SuspendedByEv, self::SuspendedByEvse, self::Finalizing],
            self::Finalizing => [self::ReviewRequired, self::Completed],
            self::ReviewRequired => [self::Finalizing, self::Completed],
            self::Completed, self::Failed, self::Cancelled, self::Expired => [],
        }, true);
    }
}
