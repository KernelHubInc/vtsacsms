<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Modules\Assets\Application\DeleteDraftAsset;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Assets\Domain\Models\Connector;
use App\Modules\Assets\Domain\Models\Evse;
use App\Modules\Locations\Application\DeleteUnusedLocation;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Locations\Domain\Models\SiteOperatingHour;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

final class DeleteNetworkRecordAction
{
    public static function make(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription('Delete this unused record permanently? Linked history is protected. Equipment and sites must be drafts without dependent records. For equipment already in service, use the Retired lifecycle setting instead.')
            ->using(function (ChargingStation|Evse|Connector|Site|SiteOperatingHour $record, DeleteAction $action): bool {
                try {
                    if ($record instanceof Site || $record instanceof SiteOperatingHour) {
                        app(DeleteUnusedLocation::class)->delete(auth()->user(), $record);
                    } else {
                        app(DeleteDraftAsset::class)->delete(auth()->user(), $record);
                    }
                } catch (ValidationException $error) {
                    Notification::make()->danger()->title('Record cannot be deleted')->body(implode(' ', $error->validator->errors()->all()))->persistent()->send();
                    $action->halt();
                } catch (QueryException $error) {
                    if (! in_array((string) $error->getCode(), ['23000', '23503'], true)) {
                        throw $error;
                    }
                    Notification::make()->danger()->title('Record is still in use')->body('A linked record prevents deletion. Refresh the page and retain or retire this record.')->send();
                    $action->halt();
                }

                return true;
            });
    }
}
