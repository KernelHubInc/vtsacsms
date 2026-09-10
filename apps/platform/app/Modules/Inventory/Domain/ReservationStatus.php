<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

enum ReservationStatus: string
{
    case Requested = 'requested';
    case PartiallyReserved = 'partially_reserved';
    case Reserved = 'reserved';
    case PartiallyConsumed = 'partially_consumed';
    case Consumed = 'consumed';
    case Released = 'released';
    case Expired = 'expired';
    case Rejected = 'rejected';
    case Canceled = 'canceled';

    public function holdsStock(): bool
    {
        return in_array($this, [
            self::PartiallyReserved,
            self::Reserved,
            self::PartiallyConsumed,
        ], true);
    }
}
