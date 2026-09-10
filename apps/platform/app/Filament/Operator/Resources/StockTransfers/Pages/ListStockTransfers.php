<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\StockTransfers\Pages;

use App\Filament\Operator\Resources\StockTransfers\StockTransferResource;
use Filament\Resources\Pages\ListRecords;

final class ListStockTransfers extends ListRecords
{
    protected static string $resource = StockTransferResource::class;
}
