<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Domain;

enum TariffDimension: string
{
    case Energy = 'energy';
    case Time = 'time';
    case Session = 'session';
    case Parking = 'parking';
    case Idle = 'idle';

    public function defaultUnitQuantity(): int
    {
        return match ($this) {
            self::Energy => 1000,
            self::Time, self::Parking, self::Idle => 60,
            self::Session => 1,
        };
    }
}
