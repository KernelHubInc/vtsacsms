<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\VendorInvoices\Pages;

use App\Filament\Operator\Resources\VendorInvoices\VendorInvoiceResource;
use App\Filament\Shared\Actions\ProcurementActions;
use Filament\Resources\Pages\ListRecords;

final class ListVendorInvoices extends ListRecords
{
    protected static string $resource = VendorInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [ProcurementActions::createInvoice()];
    }
}
