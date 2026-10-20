<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\ChargingStations\Pages;

use App\Filament\Operator\Resources\ChargingStations\ChargingStationResource;
use App\Filament\Operator\Resources\Connectors\ConnectorResource;
use App\Filament\Operator\Resources\Evses\EvseResource;
use App\Filament\Operator\Resources\OperatingHours\OperatingHourResource;
use App\Filament\Operator\Resources\Sites\SiteResource;
use App\Filament\Operator\Resources\Tariffs\TariffResource;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Reporting\Application\StationSetupQuery;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Gate;

final class SetupChargingStation extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ChargingStationResource::class;

    protected string $view = 'filament.operator.station-setup';

    protected static ?string $title = 'Station setup';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        Gate::authorize('view', $this->getRecord());
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $station = $this->getRecord();
        abort_unless($station instanceof ChargingStation, 404);
        $setup = app(StationSetupQuery::class)->for(auth()->user(), $station);
        $links = [];
        if (SiteResource::canEdit($station->site)) {
            $links['1. Location & publication'] = SiteResource::getUrl('edit', ['record' => $station->site_id]);
        }
        if (ChargingStationResource::canEdit($station)) {
            $links['2. Station settings'] = ChargingStationResource::getUrl('edit', ['record' => $station->getKey()]);
        }
        foreach ([EvseResource::class => '3. EVSEs', ConnectorResource::class => '4. Connectors', OperatingHourResource::class => '5. Operating hours', TariffResource::class => '6. Pricing'] as $resource => $label) {
            if ($resource::canViewAny()) {
                $parameters = match ($resource) {
                    EvseResource::class, ConnectorResource::class => ['filters' => ['station' => ['value' => $station->getKey()]]],
                    OperatingHourResource::class => ['filters' => ['site' => ['value' => $station->site_id]]],
                    default => [],
                };
                $links[$label] = $resource::getUrl('index', $parameters);
            }
        }

        return compact('setup', 'links');
    }
}
