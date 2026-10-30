<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\ChargingReceipts\Pages;

use App\Filament\Platform\Resources\ChargingReceipts\ChargingReceiptResource;
use Filament\Resources\Pages\ListRecords;

final class ListChargingReceipts extends ListRecords
{
    protected static string $resource = ChargingReceiptResource::class;

    public function getSubheading(): string
    {
        return 'Receipt requests for settled charging payments. Search by session reference. Mail server acceptance does not confirm inbox delivery; check Spam and provider logs when a receipt is missing. Times are UTC.';
    }
}
