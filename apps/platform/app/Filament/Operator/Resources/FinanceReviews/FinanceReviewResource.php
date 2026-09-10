<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\FinanceReviews;

use App\Filament\Operator\Resources\FinanceReviews\Pages\ListFinanceReviews;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Payments\Domain\Models\FinanceReview;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class FinanceReviewResource extends Resource
{
    protected static ?string $model = FinanceReview::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Finance review queue';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('severity')->badge(), TextColumn::make('reason_code')->label('Reason')->searchable(),
            TextColumn::make('source_type')->label('Source'), TextColumn::make('source_id')->label('Source ID')->copyable(),
            TextColumn::make('status')->badge()->sortable(), TextColumn::make('opened_at')->dateTime()->sortable(),
        ])
            ->filters([
                SelectFilter::make('status')->options([
                    'open' => 'Open',
                    'in_review' => 'In review',
                    'resolved' => 'Resolved',
                    'dismissed' => 'Dismissed',
                ]),
                SelectFilter::make('severity')->options([
                    'low' => 'Low',
                    'medium' => 'Medium',
                    'high' => 'High',
                    'critical' => 'Critical',
                ]),
            ])
            ->recordActions([
                RecordViewAction::make([
                    'severity' => 'Severity',
                    'reason_code' => 'Reason',
                    'source_type' => 'Source type',
                    'source_id' => 'Source identifier',
                    'status' => 'Status',
                    'notes' => 'Notes',
                    'opened_at' => 'Opened at (UTC)',
                    'resolved_at' => 'Resolved at (UTC)',
                ]),
            ])
            ->defaultSort('opened_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListFinanceReviews::route('/')];
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
