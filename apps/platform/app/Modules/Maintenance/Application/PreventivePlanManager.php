<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Assets\Application\Contracts\AssetMaintenanceContract;
use App\Modules\Maintenance\Domain\Models\PreventivePlan;
use App\Modules\Maintenance\Domain\PreventiveTriggerType;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final readonly class PreventivePlanManager
{
    public function __construct(private CurrentTenant $tenant, private AuditRecorder $audit, private AssetMaintenanceContract $assets) {}

    /** @param array<string, mixed> $input */
    public function save(User $user, array $input, ?PreventivePlan $plan = null): PreventivePlan
    {
        $tenantId = $this->tenant->get()->tenantId;
        $data = Validator::make($input, [
            'site_id' => ['required', 'ulid', Rule::exists('sites', 'id')->where('tenant_id', $tenantId)],
            'asset_type' => ['required', Rule::in(['station', 'evse', 'connector', 'component'])],
            'asset_id' => ['required', 'ulid'],
            'name' => ['required', 'string', 'max:200'],
            'priority_id' => ['required', 'ulid', Rule::exists('maintenance_priorities', 'id')->where('tenant_id', $tenantId)->where('is_active', true)],
            'trigger_type' => ['required', Rule::enum(PreventiveTriggerType::class)],
            'interval_value' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'next_due_at' => ['nullable', 'required_if:trigger_type,date', 'date'],
        ])->validate();
        $this->authorize($user, (string) $data['site_id']);

        return DB::transaction(function () use ($user, $data, $plan): PreventivePlan {
            $record = $plan === null ? new PreventivePlan : PreventivePlan::query()->lockForUpdate()->whereKey($plan->getKey())->firstOrFail();
            if ($record->exists) {
                $this->authorize($user, (string) $record->site_id);
                // Historical occurrences and usage baselines belong to the original asset and trigger.
                abort_unless($record->site_id === $data['site_id'] && $record->asset_type === $data['asset_type']
                    && $record->asset_id === $data['asset_id'] && $record->trigger_type->value === $data['trigger_type'], 422, 'Create a new plan to change its asset or trigger.');
            }
            $this->assets->assertBelongsToSite((string) $data['asset_type'], (string) $data['asset_id'], (string) $data['site_id']);
            abort_if($data['asset_type'] === 'component' && $data['trigger_type'] !== 'date', 422, 'Components support date-based plans.');
            $before = $record->exists ? $record->attributesToArray() : null;
            $record->fill($data);
            $record->next_due_at = $data['trigger_type'] === 'date' ? $data['next_due_at'] : null;
            if (! $record->exists) {
                $record->plan_number = 'PM-'.Str::ulid();
                $record->is_active = true;
            }
            $record->save();
            $this->audit->record(new AuditEntry('maintenance.preventive_plan.'.($before === null ? 'created' : 'updated'), 'preventive_plan', (string) $record->getKey(), AuditResult::Succeeded, before: $before, after: $record->attributesToArray()));

            return $record;
        });
    }

    public function setActive(User $user, PreventivePlan $plan, bool $active, string $reason): void
    {
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:500']])->validate();
        DB::transaction(function () use ($user, $plan, $active, $reason): void {
            $record = PreventivePlan::query()->lockForUpdate()->whereKey($plan->getKey())->firstOrFail();
            $this->authorize($user, (string) $record->site_id);
            if ($record->is_active === $active) {
                return;
            }
            $record->forceFill(['is_active' => $active])->save();
            $this->audit->record(new AuditEntry('maintenance.preventive_plan.'.($active ? 'resumed' : 'paused'), 'preventive_plan', (string) $record->getKey(), AuditResult::Succeeded, reason: $reason));
        });
    }

    private function authorize(User $user, string $siteId): void
    {
        abort_unless(app(AccessibleMaintenanceSitesQuery::class)->for($user, PermissionKey::MaintenanceDispatch)->where('id', $siteId)->exists(), 403);
    }
}
