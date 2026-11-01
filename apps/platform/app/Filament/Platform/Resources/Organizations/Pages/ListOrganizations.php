<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Organizations\Pages;

use App\Filament\Platform\Pages\OnboardOperator;
use App\Filament\Platform\Resources\Organizations\OrganizationResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListOrganizations extends ListRecords
{
    protected static string $resource = OrganizationResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('onboardOperator')->label('Onboard operator')->url(OnboardOperator::getUrl())->visible(fn (): bool => OnboardOperator::canAccess()), CreateAction::make()];
    }
}
