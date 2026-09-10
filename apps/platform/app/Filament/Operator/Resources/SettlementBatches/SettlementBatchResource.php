<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\SettlementBatches;

use App\Filament\Operator\Resources\SettlementBatches\Pages\ListSettlementBatches;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Settlements\Domain\Models\SettlementBatch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class SettlementBatchResource extends Resource
{
    protected static ?string $model = SettlementBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Settlements';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('reference')->copyable()->searchable(), TextColumn::make('status')->badge()->sortable(), TextColumn::make('currency'),
            TextColumn::make('period_start')->dateTime(), TextColumn::make('period_end')->dateTime(),
            TextColumn::make('prepared_by')->label('Prepared by')->copyable()->placeholder('Draft'),
            TextColumn::make('approved_by')->label('Approved by')->copyable()->placeholder('Not approved'),
        ])
            ->filters([
                SelectFilter::make('status')
                    ->options(fn (): array => SettlementBatch::query()
                        ->select('status')
                        ->distinct()
                        ->orderBy('status')
                        ->pluck('status', 'status')
                        ->all()),
            ])
            ->recordActions([
                RecordViewAction::make([
                    'reference' => 'Reference',
                    'status' => 'Status',
                    'currency' => 'Currency',
                    'period_start' => 'Period start (UTC)',
                    'period_end' => 'Period end (UTC)',
                    'prepared_by' => 'Prepared by',
                    'prepared_at' => 'Prepared at (UTC)',
                    'approved_by' => 'Approved by',
                    'approved_at' => 'Approved at (UTC)',
                    'submitted_at' => 'Submitted at (UTC)',
                ]),
            ])
            ->defaultSort('period_end', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListSettlementBatches::route('/')];
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
