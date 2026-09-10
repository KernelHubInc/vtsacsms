<?php

declare(strict_types=1);

namespace App\Modules\Charging\Domain;

enum ChargerCommandState: string
{
    case Requested = 'requested';
    case Dispatched = 'dispatched';
    case Acknowledged = 'acknowledged';
    case Rejected = 'rejected';
    case TimedOut = 'timed_out';
    case DeliveryUnknown = 'delivery_unknown';
    case Expired = 'expired';

    public function isTerminal(): bool
    {
        return ! in_array($this, [self::Requested, self::Dispatched], true);
    }

    public function canTransitionTo(self $next): bool
    {
        if ($next === $this) {
            return true;
        }

        return in_array($next, match ($this) {
            self::Requested => [self::Dispatched, self::Expired, self::Rejected],
            self::Dispatched => [self::Acknowledged, self::Rejected, self::TimedOut, self::DeliveryUnknown],
            self::Acknowledged, self::Rejected, self::TimedOut, self::DeliveryUnknown, self::Expired => [],
        }, true);
    }
}
