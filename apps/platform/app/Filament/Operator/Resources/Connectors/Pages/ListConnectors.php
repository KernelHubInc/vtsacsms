<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\Connectors\Pages;

use App\Filament\Operator\Resources\Connectors\ConnectorResource;
use App\Filament\Shared\Actions\ActionValidation;
use App\Modules\Assets\Application\ProvisionChargingEquipment;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListConnectors extends ListRecords
{
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add connector')
            ->using(fn (array $data, $livewire) => ActionValidation::run($livewire, fn () => app(ProvisionChargingEquipment::class)->connector(auth()->user(), $data)))];
    }

    protected static string $resource = ConnectorResource::class;
}
