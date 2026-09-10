<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\AuditEvents\Pages;

use App\Filament\Platform\Resources\AuditEvents\AuditEventResource;
use Filament\Resources\Pages\ListRecords;

final class ListAuditEvents extends ListRecords
{
    protected static string $resource = AuditEventResource::class;
}
