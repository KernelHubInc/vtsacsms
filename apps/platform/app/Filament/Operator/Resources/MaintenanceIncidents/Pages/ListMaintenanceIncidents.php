<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\MaintenanceIncidents\Pages;

use App\Filament\Operator\Resources\MaintenanceIncidents\MaintenanceIncidentResource;
use Filament\Resources\Pages\ListRecords;

final class ListMaintenanceIncidents extends ListRecords
{
    protected static string $resource = MaintenanceIncidentResource::class;
}
