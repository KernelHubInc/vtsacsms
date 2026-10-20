<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\OperatingHours\Pages;

use App\Filament\Operator\Resources\OperatingHours\OperatingHourResource;
use App\Filament\Shared\Actions\ActionValidation;
use App\Modules\Locations\Application\ConfigureSiteAccess;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListOperatingHours extends ListRecords
{
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add operating day')
            ->using(fn (array $data, $livewire) => ActionValidation::run($livewire, fn () => app(ConfigureSiteAccess::class)->hours(auth()->user(), $data)))];
    }

    protected static string $resource = OperatingHourResource::class;
}
