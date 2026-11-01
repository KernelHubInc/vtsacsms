<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Http\Requests\Api\StorePurchaseRequestRequest;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Inventory\Domain\Models\UnitOfMeasure;
use App\Modules\Procurement\Application\PurchaseRequestWorkflow;
use App\Modules\Procurement\Domain\Models\CostCenter;
use App\Modules\Procurement\Domain\Models\Department;
use App\Modules\Procurement\Domain\Models\DocumentApproval;
use App\Modules\Procurement\Domain\Models\PurchaseRequest;
use App\Modules\Procurement\Domain\PurchaseRequestStatus;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class PurchaseRequestActions
{
    /** @return list<Component> */
    private static function form(): array
    {
        return [
            Select::make('department_id')->label('Department')->options(fn () => Department::query()->where('is_active', true)->pluck('name', 'id')->all())->required()->searchable(),
            Select::make('cost_center_id')->label('Cost center')->options(fn () => CostCenter::query()->where('is_active', true)->pluck('name', 'id')->all())->required()->searchable(),
            TextInput::make('currency')->default('PHP')->required()->length(3), DatePicker::make('needed_by'),
            Textarea::make('business_reason')->required()->maxLength(5000),
            Repeater::make('lines')->minItems(1)->maxItems(100)->schema([
                Select::make('item_id')->label('Catalog item (optional)')->searchable()->options(fn () => InventoryItem::query()->pluck('name', 'id')->all()),
                Select::make('uom_id')->label('Unit (optional)')->searchable()->options(fn () => UnitOfMeasure::query()->pluck('name', 'id')->all()),
                TextInput::make('description')->required()->maxLength(500),
                TextInput::make('quantity_base')->label('Quantity (base units)')->integer()->minValue(1)->maxValue(1000000)->required(),
                TextInput::make('estimated_unit_minor')->label('Unit price (centavos)')->helperText('For PHP, enter 10000 for ₱100.00.')->integer()->minValue(0)->maxValue(100000000)->required(),
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{request_number:string,department_id:string,cost_center_id:string,currency:string,needed_by:string|null,business_reason:string,lines:list<array{item_id:string|null,uom_id:string|null,description:string,quantity_base:int,estimated_unit_minor:int}>}
     */
    private static function payload(array $data, ?PurchaseRequest $record = null): array
    {
        $rules = (new StorePurchaseRequestRequest)->rules();
        if ($record !== null) {
            unset($rules['request_number']);
        }
        $rules['lines'] = ['required', 'array', 'min:1', 'max:100'];
        $rules['lines.*.quantity_base'][] = 'max:1000000';
        $rules['lines.*.estimated_unit_minor'][] = 'max:100000000';
        $data['request_number'] = $record->request_number ?? 'PR-'.Str::ulid();
        $validated = Validator::make($data, $rules)->validate();
        $validated['lines'] = array_values(array_map(static fn (array $line): array => [
            'item_id' => isset($line['item_id']) ? (string) $line['item_id'] : null,
            'uom_id' => isset($line['uom_id']) ? (string) $line['uom_id'] : null,
            'description' => (string) $line['description'], 'quantity_base' => (int) $line['quantity_base'], 'estimated_unit_minor' => (int) $line['estimated_unit_minor'],
        ], $validated['lines']));

        return [
            'request_number' => (string) $data['request_number'],
            'department_id' => (string) $validated['department_id'],
            'cost_center_id' => (string) $validated['cost_center_id'],
            'currency' => strtoupper((string) $validated['currency']),
            'needed_by' => isset($validated['needed_by']) ? (string) $validated['needed_by'] : null,
            'business_reason' => (string) $validated['business_reason'],
            'lines' => $validated['lines'],
        ];
    }

    public static function create(): Action
    {
        return Action::make('createRequest')->label('New purchase request')->visible(fn (): bool => Gate::allows('create', PurchaseRequest::class))
            ->schema(self::form())->action(function (array $data): void {
                Gate::authorize('create', PurchaseRequest::class);
                $payload = self::payload($data);
                WorkflowAction::run(fn () => app(PurchaseRequestWorkflow::class)->create($payload));
            });
    }

    /** @return list<Action> */
    public static function records(): array
    {
        return [
            Action::make('editDraft')->label('Edit draft')->visible(fn (PurchaseRequest $record): bool => Gate::allows('update', $record) && in_array($record->status, [PurchaseRequestStatus::Draft, PurchaseRequestStatus::RevisionRequested], true))
                ->fillForm(fn (PurchaseRequest $record): array => $record->load('lines')->toArray())->schema(self::form())
                ->action(function (PurchaseRequest $record, array $data): void {
                    Gate::authorize('update', $record);
                    $payload = self::payload($data, $record);
                    WorkflowAction::run(fn () => app(PurchaseRequestWorkflow::class)->updateDraft($record, $payload));
                }),
            Action::make('submitRequest')->label('Submit for approval')->requiresConfirmation()
                ->visible(fn (PurchaseRequest $record): bool => Gate::allows('update', $record) && in_array($record->status, [PurchaseRequestStatus::Draft, PurchaseRequestStatus::RevisionRequested, PurchaseRequestStatus::Rejected], true))
                ->action(function (PurchaseRequest $record): void {
                    Gate::authorize('update', $record);
                    WorkflowAction::run(fn () => app(PurchaseRequestWorkflow::class)->submit($record));
                }),
            Action::make('decideRequest')->label('Review approval')->requiresConfirmation()
                ->visible(fn (PurchaseRequest $record): bool => Gate::allows('approve', $record) && $record->status === PurchaseRequestStatus::UnderApproval)
                ->schema([Select::make('decision')->options(['approve' => 'Approve', 'reject' => 'Reject', 'revise' => 'Return for revision'])->required(), Textarea::make('reason')->required()->maxLength(500)])
                ->action(function (PurchaseRequest $record, array $data): void {
                    Gate::authorize('approve', $record);
                    $role = DocumentApproval::query()->where('document_type', 'purchase_request')->where('document_id', $record->getKey())->where('document_revision', $record->revision)->where('status', 'pending')->orderBy('sequence')->value('approver_role_key');
                    WorkflowAction::run(function () use ($record, $data, $role): void {
                        $workflow = app(PurchaseRequestWorkflow::class);
                        match ($data['decision']) {
                            'approve' => $workflow->approve($record, (string) $role, (string) $data['reason']),
                            'reject' => $workflow->reject($record, (string) $role, (string) $data['reason']),
                            'revise' => $workflow->requestRevision($record, (string) $data['reason']),
                            default => throw new \DomainException('Choose a valid review decision.'),
                        };
                    });
                }),
            Action::make('cancelRequest')->label('Cancel request')->color('danger')->requiresConfirmation()
                ->visible(fn (PurchaseRequest $record): bool => Gate::allows('update', $record) && in_array($record->status, [PurchaseRequestStatus::Draft, PurchaseRequestStatus::RevisionRequested, PurchaseRequestStatus::Rejected], true))
                ->schema([Textarea::make('reason')->required()->maxLength(500)])
                ->action(function (PurchaseRequest $record, array $data): void {
                    Gate::authorize('update', $record);
                    WorkflowAction::run(fn () => app(PurchaseRequestWorkflow::class)->cancelDraft($record, (string) $data['reason']));
                }),
        ];
    }
}
