<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\AuditEvents;

use App\Filament\Platform\Resources\AuditEvents\Pages\ListAuditEvents;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Foundation\Audit\Models\AuditEvent;
use App\Models\User;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class AuditEventResource extends Resource
{
    protected static ?string $model = AuditEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Identity and audit';

    protected static ?string $navigationLabel = 'Immutable audit log';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('occurred_at')->dateTime()->sortable(),
            TextColumn::make('actor_type')->badge(),
            TextColumn::make('actor_id')->copyable()->placeholder('System'),
            TextColumn::make('action')->searchable(),
            TextColumn::make('target_type')->searchable(),
            TextColumn::make('target_id')->copyable()->placeholder('—'),
            TextColumn::make('result')->badge(),
            TextColumn::make('source_ip')->placeholder('—'),
            TextColumn::make('correlation_id')->copyable(),
        ])
            ->filters([
                SelectFilter::make('result')->options([
                    'succeeded' => 'Succeeded',
                    'failed' => 'Failed',
                    'denied' => 'Denied',
                ]),
                SelectFilter::make('actor_type')
                    ->options(collect(ActorType::cases())->mapWithKeys(
                        fn (ActorType $type): array => [
                            $type->value => str($type->value)->headline()->toString(),
                        ],
                    )->all()),
            ])
            ->recordActions([
                RecordViewAction::make([
                    'occurred_at' => 'Occurred at (UTC)',
                    'actor_type' => 'Actor type',
                    'actor_id' => 'Actor identifier',
                    'action' => 'Action',
                    'target_type' => 'Target type',
                    'target_id' => 'Target identifier',
                    'result' => 'Result',
                    'reason' => 'Reason',
                    'source_ip' => 'Source IP',
                    'user_agent' => 'User agent',
                    'correlation_id' => 'Correlation identifier',
                ]),
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
