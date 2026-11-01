<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\PreventivePlans\Pages;

use App\Filament\Operator\Resources\PreventivePlans\PreventivePlanResource;
use App\Filament\Shared\Actions\MaintenanceActions;
use Filament\Resources\Pages\ListRecords;

final class ListPreventivePlans extends ListRecords
{
    protected static string $resource = PreventivePlanResource::class;

    protected function getHeaderActions(): array
    {
        return [MaintenanceActions::createPlan()];
    }
}
