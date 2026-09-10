<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\Connectors\Pages;

use App\Filament\Operator\Resources\Connectors\ConnectorResource;
use Filament\Resources\Pages\ListRecords;

final class ListConnectors extends ListRecords
{
    protected static string $resource = ConnectorResource::class;
}
