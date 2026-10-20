<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\ChargingStations\Pages;

use App\Filament\Operator\Resources\ChargingStations\ChargingStationResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListChargingStations extends ListRecords
{
    protected static string $resource = ChargingStationResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('setupGuide')->label('Setup guide')->color('gray')
                ->modalHeading('Set up a charging station')
                ->modalAutofocus(false)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->modalContent(view('filament.operator.partials.station-setup-guide'))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close guide'),
            CreateAction::make(),
        ];
    }
}
