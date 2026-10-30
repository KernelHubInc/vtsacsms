<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\AuditEvents;

use App\Filament\Platform\Resources\AuditEvents\Pages\ListAuditEvents;
use App\Foundation\Audit\AuditLogQuery;
use App\Foundation\Audit\Models\AuditEvent;
use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class AuditEventResource extends Resource
{
    protected static ?string $model = AuditEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Identity and audit';

    protected static ?string $navigationLabel = 'Activity log';

    protected static ?string $pluralModelLabel = 'Activity log';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('occurred_at')->label('Time (UTC)')->dateTime(timezone: 'UTC')->sortable(),
            TextColumn::make('actor_type')->badge(),
            TextColumn::make('actor_id')->copyable()->placeholder('Not authenticated / service'),
            TextColumn::make('action')->wrap(),
            TextColumn::make('target_type'),
            TextColumn::make('target_id')->copyable()->placeholder('—'),
            TextColumn::make('result')->badge(),
            TextColumn::make('source_ip')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('correlation_id')->copyable()->toggleable(isToggledHiddenByDefault: true),
        ])
            ->filters([
                Filter::make('activity')->schema(AuditFilters::fields())
                    ->query(function (Builder $query, array $data): Builder {
                        $user = auth()->user();
                        abort_unless($user instanceof User, 403);

                        return $query->whereIn('id', app(AuditLogQuery::class)->for($user, $data)->select('id'));
                    }),
            ])
            ->recordActions([
                ViewAction::make()->schema([
                    TextEntry::make('occurred_at')->label('Time (UTC)'),
                    TextEntry::make('action'), TextEntry::make('result'),
                    TextEntry::make('actor_id')->label('Actor ID')->placeholder('Not authenticated / service'),
                    TextEntry::make('target_type'), TextEntry::make('target_id'),
                    TextEntry::make('correlation_id')->copyable(),
                    TextEntry::make('details')->state(fn (AuditEvent $record): string => AuditLogQuery::details($record))
                        ->label('Recorded wallet / change details')->columnSpanFull(),
                ])->modalDescription('Read-only audit evidence. Amounts are integer minor units: PHP 100 = 1 peso. Reserved funds are a hold; released funds are not new income.'),
            ])
            ->defaultSort('occurred_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditEvents::route('/')];
    }

    public static function getEloquentQuery(): Builder
    {
        $tenantId = app(CurrentTenant::class)->get()->tenantId;

        return parent::getEloquentQuery()->where('tenant_id', $tenantId);
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->allows($user, PermissionKey::AuditView);
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
