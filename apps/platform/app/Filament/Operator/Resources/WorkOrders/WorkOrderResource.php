<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\WorkOrders;

use App\Filament\Operator\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Maintenance\Application\AccessibleMaintenanceSitesQuery;
use App\Modules\Maintenance\Application\WorkOrderWorkflow;
use App\Modules\Maintenance\Domain\Models\WorkOrder;
use App\Modules\Maintenance\Domain\WorkOrderState;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use UnitEnum;

final class WorkOrderResource extends Resource
{
    protected static ?string $model = WorkOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'Maintenance';

    protected static ?string $recordTitleAttribute = 'work_order_number';

    /** @return Builder<WorkOrder> */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return WorkOrder::query()->whereRaw('1 = 0');
        }

        return WorkOrder::query()->whereIn(
            'site_id',
            app(AccessibleMaintenanceSitesQuery::class)->for($user)->select('id'),
        );
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('work_order_number')->label('Work order')->searchable()->sortable(),
            TextColumn::make('title')->wrap()->searchable(),
            TextColumn::make('site.name')->label('Site')->sortable(),
            TextColumn::make('work_type')->badge(),
            TextColumn::make('priority.name')->label('Priority')->badge(),
            TextColumn::make('state')->badge(),
            TextColumn::make('resolve_target_at')->label('SLA target')->dateTime()->sortable(),
            TextColumn::make('actual_cost_minor')->label('Cost (minor)')->numeric(),
        ])
            ->filters([
                SelectFilter::make('state')
                    ->options(collect(WorkOrderState::cases())
                        ->mapWithKeys(fn (WorkOrderState $state): array => [
                            $state->value => str($state->value)->headline()->toString(),
                        ])
                        ->all()),
                SelectFilter::make('work_type')
                    ->options([
                        'corrective' => 'Corrective',
                        'preventive' => 'Preventive',
                        'inspection' => 'Inspection',
                        'vendor_repair' => 'Vendor repair',
                    ]),
            ])
            ->recordActions([
                RecordViewAction::make([
                    'work_order_number' => 'Work order',
                    'title' => 'Title',
                    'description' => 'Description',
                    'site.name' => 'Site',
                    'asset_type' => 'Asset type',
                    'asset_id' => 'Asset identifier',
                    'work_type' => 'Work type',
                    'priority.name' => 'Priority',
                    'state' => 'State',
                    'resolve_target_at' => 'SLA resolution target (UTC)',
                    'actual_cost_minor' => 'Actual cost (minor units)',
                    'currency' => 'Currency',
                ]),
                Action::make('transition')
                    ->label('Change state')
                    ->visible(fn (WorkOrder $record): bool => ! $record->state->isTerminal())
                    ->schema(fn (WorkOrder $record): array => [
                        Select::make('target_state')
                            ->label('Next state')
                            ->options(collect(WorkOrderState::cases())
                                ->filter(fn (WorkOrderState $state): bool => $record->state->canTransitionTo($state))
                                ->mapWithKeys(fn (WorkOrderState $state): array => [
                                    $state->value => str($state->value)->headline()->toString(),
                                ])
                                ->all())
                            ->required(),
                        Textarea::make('reason')
                            ->helperText('This reason is written to the immutable work-order transition and audit history.')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription('Only state-machine transitions are offered. Checklist, assignment, evidence, and separation-of-duty rules remain enforced by the maintenance workflow.')
                    ->action(function (WorkOrder $record, array $data): void {
                        $target = WorkOrderState::from((string) $data['target_state']);
                        $ability = match ($target) {
                            WorkOrderState::InProgress,
                            WorkOrderState::OnHold,
                            WorkOrderState::AwaitingParts,
                            WorkOrderState::AwaitingAccess,
                            WorkOrderState::AwaitingExternal,
                            WorkOrderState::AwaitingSafetyClearance,
                            WorkOrderState::Completed => 'perform',
                            WorkOrderState::VerificationRequired,
                            WorkOrderState::Verified,
                            WorkOrderState::Closed => 'verify',
                            default => 'dispatch',
                        };

                        Gate::authorize($ability, $record);

                        try {
                            app(WorkOrderWorkflow::class)->transition(
                                $record,
                                $target,
                                'admin_transition',
                                (string) $data['reason'],
                            );
                        } catch (DomainException $exception) {
                            throw ValidationException::withMessages([
                                'data.target_state' => $exception->getMessage(),
                            ]);
                        }
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListWorkOrders::route('/')];
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
