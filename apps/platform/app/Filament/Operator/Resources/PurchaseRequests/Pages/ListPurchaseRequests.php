<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\PurchaseRequests\Pages;

use App\Filament\Operator\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Shared\Actions\PurchaseRequestActions;
use Filament\Resources\Pages\ListRecords;

final class ListPurchaseRequests extends ListRecords
{
    protected static string $resource = PurchaseRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [PurchaseRequestActions::create()];
    }
}
