<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\Invoices;

use App\Filament\Operator\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Billing\Domain\Models\Invoice;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('document_reference')->label('Reference')->copyable()->searchable(), TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('total_minor')->label('Total minor')->numeric(), TextColumn::make('amount_paid_minor')->label('Paid minor')->numeric(),
            TextColumn::make('currency'), IconColumn::make('legal_review_required')->label('Legal review')->boolean(),
            TextColumn::make('issued_at')->dateTime()->sortable(),
        ])
            ->filters([
                SelectFilter::make('status')
                    ->options(fn (): array => Invoice::query()
                        ->select('status')
                        ->distinct()
                        ->orderBy('status')
                        ->pluck('status', 'status')
                        ->all()),
            ])
            ->recordActions([
                RecordViewAction::make([
                    'document_reference' => 'Reference',
                    'status' => 'Status',
                    'subtotal_minor' => 'Subtotal (minor units)',
                    'tax_minor' => 'Tax (minor units)',
                    'total_minor' => 'Total (minor units)',
                    'amount_paid_minor' => 'Paid (minor units)',
                    'currency' => 'Currency',
                    'issued_at' => 'Issued at (UTC)',
                    'due_at' => 'Due at (UTC)',
                    'legal_review_required' => 'Legal review required',
                ]),
            ])
            ->defaultSort('issued_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListInvoices::route('/')];
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->allows($user, PermissionKey::BillingView);
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
