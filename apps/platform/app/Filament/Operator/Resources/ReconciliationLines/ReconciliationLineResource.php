<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\ReconciliationLines;

use App\Filament\Operator\Resources\ReconciliationLines\Pages\ListReconciliationLines;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Settlements\Domain\Models\ReconciliationLine;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class ReconciliationLineResource extends Resource
{
    protected static ?string $model = ReconciliationLine::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Reconciliation review';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('outcome')->badge()->sortable(), TextColumn::make('provider_reference')->label('Provider reference')->copyable()->searchable(),
            TextColumn::make('platform_captured_minor')->label('Platform captured')->numeric(), TextColumn::make('provider_net_minor')->label('Provider net')->numeric(),
            TextColumn::make('difference_minor')->label('Difference')->numeric()->sortable(), TextColumn::make('currency'),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ])
            ->filters([
                SelectFilter::make('outcome')
                    ->options(fn (): array => ReconciliationLine::query()
                        ->select('outcome')
                        ->distinct()
                        ->orderBy('outcome')
                        ->pluck('outcome', 'outcome')
                        ->all()),
            ])
            ->recordActions([
                RecordViewAction::make([
                    'outcome' => 'Outcome',
                    'provider_reference' => 'Provider reference',
                    'platform_captured_minor' => 'Platform captured (minor units)',
                    'provider_gross_minor' => 'Provider gross (minor units)',
                    'provider_fee_minor' => 'Provider fee (minor units)',
                    'provider_net_minor' => 'Provider net (minor units)',
                    'difference_minor' => 'Difference (minor units)',
                    'currency' => 'Currency',
                    'created_at' => 'Created at (UTC)',
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListReconciliationLines::route('/')];
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->allows($user, PermissionKey::SettlementView);
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
