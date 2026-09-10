<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\Invoices\Pages;

use App\Filament\Operator\Resources\Invoices\InvoiceResource;
use Filament\Resources\Pages\ListRecords;

final class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;
}
