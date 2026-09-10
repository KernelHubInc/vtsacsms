<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\ConnectorStatuses\Pages;

use App\Filament\Operator\Resources\ConnectorStatuses\ConnectorStatusResource;
use Filament\Resources\Pages\ListRecords;

final class ListConnectorStatuses extends ListRecords
{
    protected static string $resource = ConnectorStatusResource::class;
}
