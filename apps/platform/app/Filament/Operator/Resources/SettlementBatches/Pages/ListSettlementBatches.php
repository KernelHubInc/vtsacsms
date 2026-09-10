<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\SettlementBatches\Pages;

use App\Filament\Operator\Resources\SettlementBatches\SettlementBatchResource;
use Filament\Resources\Pages\ListRecords;

final class ListSettlementBatches extends ListRecords
{
    protected static string $resource = SettlementBatchResource::class;
}
