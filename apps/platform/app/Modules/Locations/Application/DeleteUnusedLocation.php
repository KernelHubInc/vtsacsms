<?php

declare(strict_types=1);

namespace App\Modules\Locations\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Locations\Domain\Models\SiteOperatingHour;
use App\Modules\Locations\Domain\SiteLifecycleStatus;
use App\Modules\Reporting\Application\NetworkReferencesQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class DeleteUnusedLocation
{
    public function delete(User $actor, Site|SiteOperatingHour $location): void
    {
        Gate::forUser($actor)->authorize('delete', $location);
        DB::transaction(function () use ($actor, $location): void {
            $record = $location->newQuery()->whereKey($location->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('delete', $record);
            if ($record instanceof Site) {
                if ($record->lifecycle_status !== SiteLifecycleStatus::Draft) {
                    throw ValidationException::withMessages(['delete' => 'Only unused draft sites can be deleted. Retire an existing site using its lifecycle setting.']);
                }
                $references = app(NetworkReferencesQuery::class)->forRecord($record->tenant_id, (string) $record->getKey());
                if ($references !== []) {
                    throw ValidationException::withMessages(['delete' => 'This site is still linked to '.implode(', ', $references).'. Remove unused draft children first, or retain the site and retire it.']);
                }
            }
            $type = $record instanceof Site ? 'site' : 'site_operating_hour';
            app(AuditRecorder::class)->record(new AuditEntry(
                'locations.'.$type.'.deleted', $type, (string) $record->getKey(), AuditResult::Succeeded,
                before: $record->attributesToArray(), metadata: ['actor_user_id' => $actor->public_id],
            ));
            $record->delete();
        });
    }
}
