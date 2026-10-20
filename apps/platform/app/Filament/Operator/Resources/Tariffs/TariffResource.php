<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\Tariffs;

use App\Filament\Operator\Resources\Tariffs\Pages\CreateTariff;
use App\Filament\Operator\Resources\Tariffs\Pages\ListTariffs;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Assets\Application\ConnectorSnapshotQuery;
use App\Modules\Locations\Application\LocationScopeQuery;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tariffs\Application\PortalPricing;
use App\Modules\Tariffs\Domain\Models\Tariff;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use UnitEnum;

final class TariffResource extends Resource
{
    protected static ?string $model = Tariff::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Charging network';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(160),
            TextInput::make('currency')->required()->length(3)->default('PHP'),
            Textarea::make('description')->columnSpanFull()->maxLength(1000),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('status')->badge(),
            TextColumn::make('currency'),
            TextColumn::make('versions_count')->counts('versions')->label('Versions'),
            TextColumn::make('updated_at')->dateTime()->sortable(),
        ])
            ->filters([
                SelectFilter::make('status')->options([
                    'draft' => 'Draft',
                    'published' => 'Published',
                    'retired' => 'Retired',
                ]),
            ])
            ->recordActions([
                Action::make('publishPricing')->label('Set pricing')->icon(Heroicon::OutlinedBanknotes)
                    ->visible(fn (Tariff $record): bool => Gate::allows('update', $record) && Gate::allows('publish', $record))
                    ->modalHeading('Publish a new price')
                    ->modalDescription('Applies immediately to new sessions in the selected scope. Existing sessions keep their original price. Time pricing counts only metered energy-flow periods. This replaces all components for this scope; no session, parking or idle fee is added.')
                    ->fillForm(function (Tariff $record): array {
                        $version = $record->versions()->with('components')->where('status', 'published')->latest('version')->first();
                        $line = $version?->components->first();
                        $minor = (int) ($line->price_minor ?? 100);

                        return [
                            'name' => $record->name,
                            'mode' => $line?->dimension->value ?? 'energy',
                            'price' => intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT),
                            'time_unit' => (int) ($line->unit_quantity ?? 60) === 3600 ? 'hour' : 'minute',
                            'connector_id' => $version?->connector_id, 'site_id' => $version?->site_id,
                            'timezone' => $version->timezone ?? 'Asia/Manila',
                            'tax_treatment' => $version?->tax_treatment->value ?? 'inclusive',
                            'tax_rate_basis_points' => $version->tax_rate_basis_points ?? 0,
                        ];
                    })
                    ->schema([
                        TextInput::make('name')->label('Tariff name')->required()->maxLength(160),
                        Select::make('mode')->label('Charge by')->options(['energy' => 'Energy (per kWh)', 'session' => 'Session (once)', 'time' => 'Energy-flow time'])->default('energy')->required(),
                        TextInput::make('price')->label('Price in tariff currency')->helperText('For PHP: 1.00 means one peso. Up to two decimal places.')->default('1.00')->required()->rules(['regex:/^\d{1,6}(\.\d{1,2})?$/']),
                        Select::make('time_unit')->label('Time price unit (used only for time pricing)')->options(['minute' => 'Per minute', 'hour' => 'Per hour'])->default('minute')->required(),
                        Select::make('connector_id')->label('Connector')->options(fn (): array => app(ConnectorSnapshotQuery::class)->pricingOptions())->searchable()->helperText('Choose the specific connector, or leave blank for the selected site / entire tenant.'),
                        Select::make('site_id')->label('Site')->options(fn (): array => app(LocationScopeQuery::class)->pricingOptions())->searchable(),
                        TextInput::make('timezone')->default('Asia/Manila')->required()->rules(['timezone:all']),
                        Select::make('tax_treatment')->options(['inclusive' => 'Tax included', 'exclusive' => 'Tax added'])->default('inclusive')->required(),
                        TextInput::make('tax_rate_basis_points')->label('Tax rate in basis points')->helperText('100 = 1%. Set the applicable rate; zero adds no tax.')->integer()->minValue(0)->maxValue(10000)->default(0)->required(),
                    ])
                    ->action(function (Tariff $record, array $data): void {
                        Gate::authorize('update', $record);
                        Gate::authorize('publish', $record);
                        try {
                            app(PortalPricing::class)->publish($record, $data);
                        } catch (DomainException $exception) {
                            throw ValidationException::withMessages(['price' => $exception->getMessage()]);
                        }
                        Notification::make()->title('Pricing published for new sessions')->success()->send();
                    }),
                RecordViewAction::make([
                    'name' => 'Name',
                    'description' => 'Description',
                    'status' => 'Status',
                    'currency' => 'Currency',
                    'versions_count' => 'Versions',
                    'created_at' => 'Created at (UTC)',
                    'updated_at' => 'Updated at (UTC)',
                ]),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListTariffs::route('/'), 'create' => CreateTariff::route('/create')];
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->allows($user, PermissionKey::TariffView);
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->allows($user, PermissionKey::TariffManage);
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
