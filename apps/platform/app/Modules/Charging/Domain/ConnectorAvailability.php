<?php

declare(strict_types=1);

namespace App\Modules\Charging\Domain;

enum ConnectorAvailability: string
{
    case Available = 'available';
    case Occupied = 'occupied';
    case Reserved = 'reserved';
    case Unavailable = 'unavailable';
    case Faulted = 'faulted';
    case Offline = 'offline';
    case Unknown = 'unknown';

    public function canTransitionTo(self $next): bool
    {
        if ($next === $this) {
            return true;
        }

        return in_array($next, match ($this) {
            self::Available => [self::Reserved, self::Occupied, self::Unavailable, self::Faulted, self::Offline, self::Unknown],
            self::Reserved => [self::Available, self::Occupied, self::Unavailable, self::Faulted, self::Offline, self::Unknown],
            self::Occupied => [self::Available, self::Reserved, self::Unavailable, self::Faulted, self::Offline, self::Unknown],
            self::Unavailable => [self::Available, self::Reserved, self::Faulted, self::Offline, self::Unknown],
            self::Faulted => [self::Available, self::Unavailable, self::Offline, self::Unknown],
            self::Offline => [self::Available, self::Reserved, self::Occupied, self::Unavailable, self::Faulted, self::Unknown],
            self::Unknown => self::cases(),
        }, true);
    }
}
