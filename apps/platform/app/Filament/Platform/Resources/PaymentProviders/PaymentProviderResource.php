<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\PaymentProviders;

use App\Filament\Platform\Resources\PaymentProviders\Pages\ListPaymentProviders;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Payments\Domain\Models\PaymentProviderConfig;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class PaymentProviderResource extends Resource
{
    protected static ?string $model = PaymentProviderConfig::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Payment providers';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('provider')->badge()->sortable(),
            TextColumn::make('environment')->badge(),
            TextColumn::make('account_reference')->label('Non-secret account reference')->placeholder('Not configured'),
            TextColumn::make('api_version')->placeholder('Default'),
            TextColumn::make('configuration_version')->label('Version')->numeric(),
            IconColumn::make('is_active')->boolean(),
        ])->recordActions([
            RecordViewAction::make([
                'provider' => 'Provider',
                'environment' => 'Environment',
                'account_reference' => 'Non-secret account reference',
                'api_version' => 'API version',
                'configuration_version' => 'Configuration version',
                'is_active' => 'Active',
                'created_at' => 'Created at (UTC)',
                'updated_at' => 'Updated at (UTC)',
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPaymentProviders::route('/')];
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->allows($user, PermissionKey::PaymentView);
    }

    public static function canCreate(): bool
    {
        return false;
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
