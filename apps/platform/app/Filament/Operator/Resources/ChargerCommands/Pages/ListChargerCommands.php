<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\ChargerCommands\Pages;

use App\Filament\Operator\Resources\ChargerCommands\ChargerCommandResource;
use Filament\Resources\Pages\ListRecords;

final class ListChargerCommands extends ListRecords
{
    protected static string $resource = ChargerCommandResource::class;
}
