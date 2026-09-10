<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\OperatingHours\Pages;

use App\Filament\Operator\Resources\OperatingHours\OperatingHourResource;
use Filament\Resources\Pages\ListRecords;

final class ListOperatingHours extends ListRecords
{
    protected static string $resource = OperatingHourResource::class;
}
