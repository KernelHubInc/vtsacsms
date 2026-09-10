<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\PaymentProviders\Pages;

use App\Filament\Platform\Resources\PaymentProviders\PaymentProviderResource;
use Filament\Resources\Pages\ListRecords;

final class ListPaymentProviders extends ListRecords
{
    protected static string $resource = PaymentProviderResource::class;
}
