<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\ReorderPoints\Pages;

use App\Filament\Operator\Resources\ReorderPoints\ReorderPointResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListReorderPoints extends ListRecords
{
    protected static string $resource = ReorderPointResource::class;

    /** @return array<CreateAction> */
    protected function getHeaderActions(): array
    {
        return [ReorderPointResource::auditedCreateAction()];
    }
}
