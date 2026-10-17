<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Modules\Locations\Application\ConfigureSiteAccess;
use App\Modules\Locations\Domain\Models\Site;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class PublishSiteAction
{
    public static function make(): Action
    {
        return Action::make('publication')
            ->label(fn (Site $record): string => $record->published_at === null ? 'Publish location' : 'Unpublish location')
            ->visible(fn (Site $record): bool => Gate::allows('update', $record))
            ->requiresConfirmation()
            ->modalDescription('Publication controls mobile and public map visibility. Save your location changes first. Publication does not make equipment online or available.')
            ->action(function (Site $record): void {
                try {
                    app(ConfigureSiteAccess::class)->publish(auth()->user(), $record, $record->published_at === null);
                } catch (ValidationException $exception) {
                    Notification::make()->title('Location needs attention')->body(implode(' ', $exception->validator->errors()->all()))->danger()->send();

                    return;
                }
                Notification::make()->title('Location publication updated')->success()->send();
            });
    }
}
