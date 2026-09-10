<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\PurchaseRequests\Pages;

use App\Filament\Operator\Resources\PurchaseRequests\PurchaseRequestResource;
use Filament\Resources\Pages\ListRecords;

final class ListPurchaseRequests extends ListRecords
{
    protected static string $resource = PurchaseRequestResource::class;
}
