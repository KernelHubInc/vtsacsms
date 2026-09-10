<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\Tariffs;

use App\Filament\Operator\Resources\Tariffs\Pages\CreateTariff;
use App\Filament\Operator\Resources\Tariffs\Pages\ListTariffs;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tariffs\Domain\Models\Tariff;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
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
