<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Organizations;

use App\Filament\Platform\Resources\Organizations\Pages\CreateOrganization;
use App\Filament\Platform\Resources\Organizations\Pages\EditOrganization;
use App\Filament\Platform\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Application\OrganizationLifecycleService;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\OrganizationType;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

final class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Platform governance';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(180),
            TextInput::make('code')
                ->required()
                ->maxLength(80)
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule): Unique => $rule->where(
                        'tenant_id',
                        app(CurrentTenant::class)->get()->tenantId,
                    ),
                ),
            Select::make('type')->options(collect(OrganizationType::cases())->mapWithKeys(fn ($type) => [$type->value => str($type->value)->headline()]))->required(),
            Select::make('parent_id')
                ->relationship('parent', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_active', true))
                ->searchable()
                ->preload(),
            Checkbox::make('is_active')->default(true)->disabledOn('edit'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('code')->searchable(),
            TextColumn::make('type')->badge(),
            TextColumn::make('parent.name')->placeholder('Root'),
            IconColumn::make('is_active')->boolean(),
        ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Lifecycle')
                    ->trueLabel('Active')
                    ->falseLabel('Inactive')
                    ->placeholder('All organizations'),
            ])
            ->recordActions([
                RecordViewAction::make([
                    'name' => 'Name',
                    'code' => 'Code',
                    'type' => 'Organization type',
                    'parent.name' => 'Parent organization',
                    'is_active' => 'Active',
                    'created_at' => 'Created at (UTC)',
                    'updated_at' => 'Updated at (UTC)',
                ]),
                EditAction::make(),
                Action::make('deactivate')
                    ->color('danger')
                    ->visible(fn (Organization $record): bool => $record->is_active)
                    ->schema([
                        Textarea::make('reason')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription('The organization will remain in historical records but cannot be used for new operational assignments.')
                    ->action(function (Organization $record, array $data): void {
                        Gate::authorize('update', $record);
                        app(OrganizationLifecycleService::class)->setActive($record, false, (string) $data['reason']);
                    }),
                Action::make('reactivate')
                    ->color('success')
                    ->visible(fn (Organization $record): bool => ! $record->is_active)
                    ->requiresConfirmation()
                    ->modalDescription('Reactivation makes the organization available for new operational assignments.')
                    ->action(function (Organization $record): void {
                        Gate::authorize('update', $record);
                        app(OrganizationLifecycleService::class)->setActive($record, true, 'administrator_reactivation');
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrganizations::route('/'),
            'create' => CreateOrganization::route('/create'),
            'edit' => EditOrganization::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->allows($user, PermissionKey::OrganizationView);
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->allows($user, PermissionKey::OrganizationManage);
    }

    public static function canEdit(Model $record): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->allows($user, PermissionKey::OrganizationManage);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
