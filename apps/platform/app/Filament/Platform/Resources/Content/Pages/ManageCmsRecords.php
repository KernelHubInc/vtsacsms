<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Content\Pages;

use App\Filament\Platform\Resources\Content\CmsResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

abstract class ManageCmsRecords extends ManageRecords
{
    /** @return array<CreateAction> */
    protected function getHeaderActions(): array
    {
        $resource = static::getResource();
        if (! is_a($resource, CmsResource::class, true)) {
            throw new \LogicException('CMS management pages require a CMS resource.');
        }

        return [$resource::auditedCreateAction()];
    }
}
