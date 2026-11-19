<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\ChargingStations\Pages;

use App\Filament\Operator\Resources\ChargingStations\ChargingStationResource;
use App\Filament\Operator\Resources\Connectors\ConnectorResource;
use App\Filament\Operator\Resources\Evses\EvseResource;
use App\Filament\Operator\Resources\OperatingHours\OperatingHourResource;
use App\Filament\Operator\Resources\Sites\SiteResource;
use App\Filament\Operator\Resources\Tariffs\TariffResource;
use App\Modules\Assets\Application\ConnectorSnapshotQuery;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Charging\Application\StationRecovery;
use App\Modules\Charging\Domain\ChargerCommandType;
use App\Modules\Charging\Domain\Models\ChargerCommand;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Reporting\Application\StationSetupQuery;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            $this->recoveryAction(ChargerCommandType::UnlockConnector, 'Unlock connector'),
            $this->recoveryAction(ChargerCommandType::RestartCharger, 'Restart charger'),
        ];
    }

    private function recoveryAction(ChargerCommandType $type, string $label): Action
    {
        return Action::make($type->value)
            ->label($label)
            ->color('warning')
            ->visible(fn (): bool => config('features.remote_charging') && config('features.ocpp')
                && app(AuthorizationService::class)->allows(auth()->user(), $type->permission(),
                    new ResourceScope(ScopeType::Site, $this->getRecord()->getAttribute('site_id'))))
            ->requiresConfirmation()
            ->modalHeading($label.' at '.$this->getRecord()->getAttribute('name').'?')
            ->modalDescription($type === ChargerCommandType::UnlockConnector
                ? 'Confirm locally that the vehicle has stopped receiving power. An online charger and a fresh idle status are required. The charger may refuse to unlock the cable.'
                : 'This requests a soft restart of the whole charger. All connectors must be idle and all sessions finalized. The device will disconnect temporarily. Confirm its new heartbeat and connector reports afterwards.')
            ->schema([
                Select::make('connector_id')->label('Connector')
                    ->options(fn (): array => collect(app(ConnectorSnapshotQuery::class)->forStation((string) $this->getRecord()->getKey()))
                        ->mapWithKeys(fn ($connector): array => [$connector->connectorId => 'EVSE '.$connector->evseNumber.' / Connector '.$connector->connectorNumber])->all())
                    ->required()->visible($type === ChargerCommandType::UnlockConnector),
                Textarea::make('reason')->label('Reason for recovery')->required()->minLength(10)->maxLength(500),
                Toggle::make('idle_confirmed')->label('I confirmed that the vehicle has stopped receiving power.')->accepted()->required(),
            ])
            ->action(function (array $data, Action $action) use ($type): void {
                try {
                    $command = app(StationRecovery::class)->request(auth()->user(), (string) $this->getRecord()->getKey(), $type, $data, 'portal:'.Str::ulid());
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('Recovery needs attention')->body(implode(' ', $exception->validator->errors()->all()))->persistent()->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title('Recovery requested')->body('Command '.$command->getKey().'. Check its result below and confirm the physical device status.')->send();
            });
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

        $recoveryCommands = ChargerCommand::query()->where('charging_station_id', $station->getKey())
            ->whereIn('type', ['unlock_connector', 'restart_charger'])->latest('id')->limit(5)->get();

        return compact('setup', 'links', 'recoveryCommands');
    }
}
