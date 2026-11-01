<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Invitations\Pages;

use App\Filament\Platform\Pages\OnboardOperator;
use App\Filament\Platform\Resources\Invitations\UserInvitationResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

final class ListUserInvitations extends ListRecords
{
    protected static string $resource = UserInvitationResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('onboard')->label('Onboard operator')->url(OnboardOperator::getUrl())->visible(fn (): bool => OnboardOperator::canAccess())];
    }
}
