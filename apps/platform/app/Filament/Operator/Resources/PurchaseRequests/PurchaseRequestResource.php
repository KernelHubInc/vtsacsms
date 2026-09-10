<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\PurchaseRequests;

use App\Filament\Operator\Resources\PurchaseRequests\Pages\ListPurchaseRequests;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Modules\Procurement\Domain\Models\PurchaseRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class PurchaseRequestResource extends Resource
{
    protected static ?string $model = PurchaseRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Procurement';

    protected static ?string $recordTitleAttribute = 'request_number';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('request_number')->label('Request')->searchable()->sortable(),
                TextColumn::make('department.name')->label('Department')->sortable(),
                TextColumn::make('costCenter.name')->label('Cost center')->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('revision')->numeric(),
                TextColumn::make('total_minor')
                    ->label('Total (minor)')
                    ->numeric()
                    ->description(fn (PurchaseRequest $record): string => $record->currency),
                TextColumn::make('needed_by')->date(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'draft' => 'Draft',
                    'submitted' => 'Submitted',
                    'pending_approval' => 'Pending approval',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                    'revision_required' => 'Revision required',
                    'sourcing' => 'Sourcing',
                    'ordered' => 'Ordered',
                    'canceled' => 'Canceled',
                ]),
            ])
            ->recordActions([
                RecordViewAction::make([
                    'request_number' => 'Request',
                    'department.name' => 'Department',
                    'costCenter.name' => 'Cost center',
                    'status' => 'Status',
                    'revision' => 'Revision',
                    'justification' => 'Justification',
                    'total_minor' => 'Total (minor units)',
                    'currency' => 'Currency',
                    'needed_by' => 'Needed by',
                    'submitted_at' => 'Submitted at (UTC)',
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListPurchaseRequests::route('/')];
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
