<?php

declare(strict_types=1);

namespace App\Modules\Assets\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Assets\Domain\AssetLifecycleStatus;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Assets\Domain\Models\Connector;
use App\Modules\Assets\Domain\Models\Evse;
use App\Modules\Reporting\Application\NetworkReferencesQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class DeleteDraftAsset
{
    public function delete(User $actor, ChargingStation|Evse|Connector $asset): void
    {
        Gate::forUser($actor)->authorize('delete', $asset);
        DB::transaction(function () use ($actor, $asset): void {
            $record = $asset->newQuery()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('delete', $record);
            if ($record->lifecycle_status !== AssetLifecycleStatus::Draft) {
                throw ValidationException::withMessages(['delete' => 'Only unused draft equipment can be deleted. Retire equipment that has been in service using its lifecycle setting.']);
            }
            $references = app(NetworkReferencesQuery::class)->forRecord($record->tenant_id, (string) $record->getKey());
            if ($references !== []) {
                throw ValidationException::withMessages(['delete' => 'This equipment is still linked to '.implode(', ', $references).'. Remove unused draft children first, or retain the record and retire it.']);
            }
            $type = str(class_basename($record))->snake()->toString();
            app(AuditRecorder::class)->record(new AuditEntry(
                'assets.'.$type.'.deleted', $type, (string) $record->getKey(), AuditResult::Succeeded,
                before: $record->attributesToArray(), metadata: ['actor_user_id' => $actor->public_id],
            ));
            $record->delete();
        });
    }
}
