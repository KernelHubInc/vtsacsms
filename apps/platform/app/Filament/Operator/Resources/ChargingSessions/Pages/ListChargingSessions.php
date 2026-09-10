<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\ChargingSessions\Pages;

use App\Filament\Operator\Resources\ChargingSessions\ChargingSessionResource;
use Filament\Resources\Pages\ListRecords;

final class ListChargingSessions extends ListRecords
{
    protected static string $resource = ChargingSessionResource::class;
}
