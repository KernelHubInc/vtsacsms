<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\Evses\Pages;

use App\Filament\Operator\Resources\Evses\EvseResource;
use Filament\Resources\Pages\ListRecords;

final class ListEvses extends ListRecords
{
    protected static string $resource = EvseResource::class;
}
