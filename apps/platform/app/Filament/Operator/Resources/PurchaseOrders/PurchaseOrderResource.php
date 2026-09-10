<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\PurchaseOrders;

use App\Filament\Operator\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Modules\Procurement\Domain\Models\PurchaseOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Procurement';

    protected static ?string $recordTitleAttribute = 'po_number';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('po_number')->label('Purchase order')->searchable()->sortable(),
                TextColumn::make('supplier.name')->searchable()->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('revision')->numeric(),
                TextColumn::make('total_minor')
                    ->label('Total (minor)')
                    ->numeric()
                    ->description(fn (PurchaseOrder $record): string => $record->currency),
                TextColumn::make('issued_at')->dateTime()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'draft' => 'Draft',
                    'pending_approval' => 'Pending approval',
                    'approved' => 'Approved',
                    'issued' => 'Issued',
                    'partially_received' => 'Partially received',
                    'received' => 'Received',
                    'closed' => 'Closed',
                    'canceled' => 'Canceled',
                ]),
            ])
            ->recordActions([
                RecordViewAction::make([
                    'po_number' => 'Purchase order',
                    'supplier.name' => 'Supplier',
                    'status' => 'Status',
                    'revision' => 'Revision',
                    'subtotal_minor' => 'Subtotal (minor units)',
                    'tax_minor' => 'Tax (minor units)',
                    'total_minor' => 'Total (minor units)',
                    'currency' => 'Currency',
                    'issued_at' => 'Issued at (UTC)',
                    'approved_at' => 'Approved at (UTC)',
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListPurchaseOrders::route('/')];
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
