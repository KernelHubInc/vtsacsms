<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\WorkOrders\Pages;

use App\Filament\Operator\Resources\WorkOrders\WorkOrderResource;
use Filament\Resources\Pages\ListRecords;

final class ListWorkOrders extends ListRecords
{
    protected static string $resource = WorkOrderResource::class;
}
