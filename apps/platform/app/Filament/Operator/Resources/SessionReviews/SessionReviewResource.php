<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\SessionReviews;

use App\Filament\Operator\Resources\SessionReviews\Pages\ListSessionReviews;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Charging\Application\AccessibleChargingSessionsQuery;
use App\Modules\Charging\Domain\Models\ChargingSessionReview;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class SessionReviewResource extends Resource
{
    protected static ?string $model = ChargingSessionReview::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Charging network';

    protected static ?string $navigationLabel = 'Session review queue';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('session_id')->label('Session')->copyable()->searchable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('reason_code')->label('Reason')->searchable(),
            TextColumn::make('decision')->badge()->placeholder('Pending'),
            TextColumn::make('opened_at')->dateTime()->sortable(),
            TextColumn::make('resolved_at')->dateTime()->placeholder('Open'),
        ])
            ->filters([
                SelectFilter::make('status')->options([
                    'open' => 'Open',
                    'resolved' => 'Resolved',
                    'dismissed' => 'Dismissed',
                ]),
            ])
            ->recordActions([
                RecordViewAction::make([
                    'session_id' => 'Session',
                    'status' => 'Status',
                    'reason_code' => 'Reason',
                    'decision' => 'Decision',
                    'notes' => 'Notes',
                    'opened_at' => 'Opened at (UTC)',
                    'resolved_at' => 'Resolved at (UTC)',
                    'resolved_by' => 'Resolved by',
                ]),
            ])
            ->defaultSort('opened_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListSessionReviews::route('/')];
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery();

        return $user instanceof User
            ? $query->whereIn(
                'session_id',
                app(AccessibleChargingSessionsQuery::class)->for($user)->select('charging_sessions.id'),
            )
            : $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AuthorizationService::class)->holdsAnywhere($user, PermissionKey::ChargingSessionReview);
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
