<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\StockTransfers\Pages;

use App\Filament\Operator\Resources\StockTransfers\StockTransferResource;
use App\Filament\Shared\Actions\InventoryActions;
use Filament\Resources\Pages\ListRecords;

final class ListStockTransfers extends ListRecords
{
    protected static string $resource = StockTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [InventoryActions::createTransfer()];
    }
}
