<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\VendorInvoices;

use App\Filament\Operator\Resources\VendorInvoices\Pages\ListVendorInvoices;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Modules\Procurement\Domain\Models\VendorInvoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class VendorInvoiceResource extends Resource
{
    protected static ?string $model = VendorInvoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Procurement';

    protected static ?string $recordTitleAttribute = 'invoice_reference';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice_reference')->label('Vendor invoice')->searchable()->sortable(),
                TextColumn::make('purchaseOrder.po_number')->label('PO')->searchable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('total_minor')
                    ->label('Total (minor)')
                    ->numeric()
                    ->description(fn (VendorInvoice $record): string => $record->currency),
                TextColumn::make('accounting_export_state')->badge(),
                TextColumn::make('invoice_date')->date()->sortable(),
                TextColumn::make('due_date')->date(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'draft' => 'Draft',
                    'matching' => 'Matching',
                    'matched' => 'Matched',
                    'discrepancy' => 'Discrepancy',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                    'exported' => 'Exported',
                ]),
                SelectFilter::make('accounting_export_state')->options([
                    'pending' => 'Pending',
                    'ready' => 'Ready',
                    'exported' => 'Exported',
                    'failed' => 'Failed',
                ]),
            ])
            ->recordActions([
                RecordViewAction::make([
                    'invoice_reference' => 'Vendor invoice',
                    'purchaseOrder.po_number' => 'Purchase order',
                    'status' => 'Status',
                    'subtotal_minor' => 'Subtotal (minor units)',
                    'tax_minor' => 'Tax (minor units)',
                    'total_minor' => 'Total (minor units)',
                    'currency' => 'Currency',
                    'accounting_export_state' => 'Accounting export',
                    'invoice_date' => 'Invoice date',
                    'due_date' => 'Due date',
                ]),
            ])
            ->defaultSort('invoice_date', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListVendorInvoices::route('/')];
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
