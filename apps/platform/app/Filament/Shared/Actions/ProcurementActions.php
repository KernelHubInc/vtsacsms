<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Procurement\Application\SourcingWorkflow;
use App\Modules\Procurement\Application\VendorInvoiceWorkflow;
use App\Modules\Procurement\Domain\Models\DocumentApproval;
use App\Modules\Procurement\Domain\Models\PurchaseOrder;
use App\Modules\Procurement\Domain\Models\QuotationComparison;
use App\Modules\Procurement\Domain\Models\VendorInvoice;
use App\Modules\Procurement\Domain\PurchaseOrderStatus;
use App\Modules\Procurement\Domain\VendorInvoiceStatus;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class ProcurementActions
{
    private static function manages(): bool
    {
        return auth()->user() instanceof User && app(AuthorizationService::class)->allows(auth()->user(), PermissionKey::ProcurementManage);
    }

    public static function createOrder(): Action
    {
        return Action::make('createOrder')->label('Create from approved comparison')->visible(fn (): bool => self::manages())
            ->schema([
                Select::make('comparison_id')->label('Approved quotation comparison')->required()->searchable()
                    ->options(fn () => QuotationComparison::query()->where('status', 'approved')->whereNotIn('selected_quotation_id', PurchaseOrder::query()->whereNotNull('selected_quotation_id')->select('selected_quotation_id'))->pluck('id', 'id')->all()),
                Textarea::make('terms')->maxLength(5000),
            ])->action(function (array $data): void {
                abort_unless(self::manages(), 403);
                WorkflowAction::run(fn () => DB::transaction(function () use ($data): void {
                    $comparison = QuotationComparison::query()->where('status', 'approved')->whereKey($data['comparison_id'])->lockForUpdate()->firstOrFail();
                    if (PurchaseOrder::query()->where('selected_quotation_id', $comparison->selected_quotation_id)->exists()) {
                        throw new \DomainException('An order already exists for this quotation. Open the existing order.');
                    }
                    app(SourcingWorkflow::class)->createPurchaseOrder($comparison, 'PO-'.Str::ulid(), null, $data['terms'] ?? null);
                }));
            });
    }

    /** @return list<Action> */
    public static function orders(): array
    {
        $actions = [];
        foreach (['submitPurchaseOrder' => PurchaseOrderStatus::Draft, 'approvePurchaseOrder' => PurchaseOrderStatus::Submitted, 'issuePurchaseOrder' => PurchaseOrderStatus::Approved] as $method => $status) {
            $ability = $method === 'approvePurchaseOrder' ? 'approve' : 'update';
            $actions[] = Action::make($method)->label(match ($method) {
                'submitPurchaseOrder' => 'Submit for approval', 'approvePurchaseOrder' => 'Approve', default => 'Issue order'
            })->requiresConfirmation()
                ->visible(fn (PurchaseOrder $record): bool => $record->status === $status && Gate::allows($ability, $record))
                ->action(function (PurchaseOrder $record) use ($method, $ability): void {
                    Gate::authorize($ability, $record);
                    WorkflowAction::run(function () use ($record, $method): void {
                        $workflow = app(SourcingWorkflow::class);
                        if ($method === 'approvePurchaseOrder') {
                            $role = DocumentApproval::query()->where('document_type', 'purchase_order')->where('document_id', $record->getKey())->where('document_revision', $record->revision)->where('status', 'pending')->orderBy('sequence')->value('approver_role_key');
                            $workflow->approvePurchaseOrder($record, (string) $role);
                        } else {
                            $workflow->{$method}($record);
                        }
                    });
                });
        }

        return $actions;
    }

    public static function createInvoice(): Action
    {
        return Action::make('createVendorInvoice')->label('Record vendor invoice')->visible(fn (): bool => Gate::allows('create', VendorInvoice::class))
            ->schema([
                Select::make('purchase_order_id')->label('Purchase order')->required()->searchable()->live()->options(fn () => PurchaseOrder::query()->whereIn('status', ['issued', 'partially_received', 'received'])->pluck('po_number', 'id')->all()),
                TextInput::make('invoice_reference')->required()->maxLength(100), DatePicker::make('invoice_date')->required(), DatePicker::make('due_date'),
                TextInput::make('tax_minor')->label('Tax from supplier invoice (minor units)')->integer()->minValue(0)->default(0)->required(),
                Repeater::make('lines')->minItems(1)->maxItems(100)->schema([
                    Select::make('purchase_order_line_id')->label('Order line')->required()->options(fn (Get $get) => PurchaseOrder::query()->whereKey($get('../../purchase_order_id'))->first()?->lines()->pluck('description', 'id')->all() ?? []),
                    TextInput::make('quantity_base')->label('Invoiced quantity')->integer()->minValue(1)->required(),
                    TextInput::make('unit_price_minor')->label('Invoiced unit price (minor units)')->integer()->minValue(0)->required(),
                ]),
            ])->action(function (array $data): void {
                Gate::authorize('create', VendorInvoice::class);
                $data = Validator::make($data, ['purchase_order_id' => 'required|ulid', 'invoice_reference' => 'required|string|max:100', 'invoice_date' => 'required|date_format:Y-m-d', 'due_date' => 'nullable|date_format:Y-m-d', 'tax_minor' => 'required|integer|min:0|max:100000000', 'lines' => 'required|array|min:1|max:100', 'lines.*.purchase_order_line_id' => 'required|ulid|distinct', 'lines.*.quantity_base' => 'required|integer|min:1|max:1000000', 'lines.*.unit_price_minor' => 'required|integer|min:0|max:100000000'])->validate();
                $order = PurchaseOrder::query()->whereIn('status', ['issued', 'partially_received', 'received'])->whereKey($data['purchase_order_id'])->firstOrFail();
                $payload = ['invoice_reference' => $data['invoice_reference'], 'invoice_date' => $data['invoice_date'], 'due_date' => $data['due_date'] ?? null, 'currency' => $order->currency, 'tax_minor' => (int) $data['tax_minor'], 'lines' => array_values(array_map(fn (array $line): array => ['purchase_order_line_id' => $line['purchase_order_line_id'], 'quantity_base' => (int) $line['quantity_base'], 'unit_price_minor' => (int) $line['unit_price_minor']], $data['lines']))];
                WorkflowAction::run(fn () => app(VendorInvoiceWorkflow::class)->create($order, $payload));
            });
    }

    /** @return list<Action> */
    public static function invoices(): array
    {
        return [
            Action::make('matchInvoice')->label('Run three-way match')->requiresConfirmation()
                ->visible(fn (VendorInvoice $record): bool => self::manages() && in_array($record->status, [VendorInvoiceStatus::Submitted, VendorInvoiceStatus::Discrepancy], true))
                ->action(function (VendorInvoice $record): void {
                    abort_unless(self::manages(), 403);
                    WorkflowAction::run(fn () => app(VendorInvoiceWorkflow::class)->match($record));
                }),
            Action::make('approveInvoice')->label('Approve matched invoice')->requiresConfirmation()
                ->visible(fn (VendorInvoice $record): bool => $record->status === VendorInvoiceStatus::Matched && Gate::allows('approve', $record))
                ->action(function (VendorInvoice $record): void {
                    Gate::authorize('approve', $record);
                    WorkflowAction::run(fn () => app(VendorInvoiceWorkflow::class)->approve($record));
                }),
        ];
    }
}
