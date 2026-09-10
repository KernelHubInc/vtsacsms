<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\ReconciliationLines\Pages;

use App\Filament\Operator\Resources\ReconciliationLines\ReconciliationLineResource;
use Filament\Resources\Pages\ListRecords;

final class ListReconciliationLines extends ListRecords
{
    protected static string $resource = ReconciliationLineResource::class;
}
