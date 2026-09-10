<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\GoodsReceipts\Pages;

use App\Filament\Operator\Resources\GoodsReceipts\GoodsReceiptResource;
use Filament\Resources\Pages\ListRecords;

final class ListGoodsReceipts extends ListRecords
{
    protected static string $resource = GoodsReceiptResource::class;
}
