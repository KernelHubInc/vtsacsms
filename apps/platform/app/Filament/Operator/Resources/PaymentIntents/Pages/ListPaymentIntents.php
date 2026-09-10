<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\PaymentIntents\Pages;

use App\Filament\Operator\Resources\PaymentIntents\PaymentIntentResource;
use Filament\Resources\Pages\ListRecords;

final class ListPaymentIntents extends ListRecords
{
    protected static string $resource = PaymentIntentResource::class;
}
