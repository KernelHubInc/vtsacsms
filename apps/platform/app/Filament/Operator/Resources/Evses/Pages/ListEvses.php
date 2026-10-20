<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\Evses\Pages;

use App\Filament\Operator\Resources\Evses\EvseResource;
use App\Filament\Shared\Actions\ActionValidation;
use App\Modules\Assets\Application\ProvisionChargingEquipment;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListEvses extends ListRecords
{
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add EVSE')
            ->using(fn (array $data, $livewire) => ActionValidation::run($livewire, fn () => app(ProvisionChargingEquipment::class)->evse(auth()->user(), $data)))];
    }

    protected static string $resource = EvseResource::class;
}
