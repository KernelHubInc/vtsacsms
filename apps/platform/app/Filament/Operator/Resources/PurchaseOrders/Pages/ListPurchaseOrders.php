<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\PurchaseOrders\Pages;

use App\Filament\Operator\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Shared\Actions\ProcurementActions;
use Filament\Resources\Pages\ListRecords;

final class ListPurchaseOrders extends ListRecords
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [ProcurementActions::createOrder()];
    }
}
