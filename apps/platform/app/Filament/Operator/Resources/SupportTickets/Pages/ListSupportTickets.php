<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\SupportTickets\Pages;

use App\Filament\Operator\Resources\SupportTickets\SupportTicketResource;
use Filament\Resources\Pages\ListRecords;

final class ListSupportTickets extends ListRecords
{
    protected static string $resource = SupportTicketResource::class;
}
