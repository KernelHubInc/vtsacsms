<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Charging\Domain\Models\ChargingBudgetSetting;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class ChargingBudgets
{
    /** @return array{minimum_minor:int,default_minor:int,maximum_minor:int,presets:list<int>,allow_custom_amount:bool} */
    public function current(): array
    {
        $setting = ChargingBudgetSetting::query()->first();
        // Existing installations retain their deployment limit until an administrator saves a policy.
        $maximum = max(1, (int) config('mobile_charging.maximum_reservation_minor'));

        return $setting === null ? [
            'minimum_minor' => 1, 'default_minor' => min(50000, $maximum),
            'maximum_minor' => $maximum, 'presets' => array_values(array_filter([20000, 50000, 100000, 200000], fn (int $amount): bool => $amount <= $maximum)),
            'allow_custom_amount' => true,
        ] : [
            'minimum_minor' => $setting->minimum_minor, 'default_minor' => $setting->default_minor,
            'maximum_minor' => $setting->maximum_minor, 'presets' => $setting->presets, 'allow_custom_amount' => $setting->allow_custom_amount,
        ];
    }

    /** @param array<string, mixed> $policy */
    public function update(User $actor, array $policy): void
    {
        abort_unless(app(AuthorizationService::class)->allows($actor, PermissionKey::TenantSettingsManage), 403);
        Validator::make(['budgetSettings' => $policy], [
            'budgetSettings' => ['required', 'array:minimum_minor,default_minor,maximum_minor,presets,allow_custom_amount'],
            'budgetSettings.minimum_minor' => ['required', 'integer', 'min:1'],
            'budgetSettings.maximum_minor' => ['required', 'integer', 'gte:budgetSettings.minimum_minor', 'max:100000000'],
            'budgetSettings.default_minor' => ['required', 'integer', 'gte:budgetSettings.minimum_minor', 'lte:budgetSettings.maximum_minor'],
            'budgetSettings.presets' => ['present', 'array', 'list'],
            'budgetSettings.presets.*' => ['required', 'integer', 'distinct', 'gte:budgetSettings.minimum_minor', 'lte:budgetSettings.maximum_minor'],
            'budgetSettings.allow_custom_amount' => ['required', 'boolean'],
        ])->validate();
        if (! $policy['allow_custom_amount'] && ! in_array($policy['default_minor'], $policy['presets'], true)) {
            throw ValidationException::withMessages(['budgetSettings.default_minor' => 'The default must be an allowed preset when Other amount is disabled.']);
        }
        DB::transaction(function () use ($policy): void {
            $setting = ChargingBudgetSetting::query()->firstOrCreate([], $this->current());
            $setting = ChargingBudgetSetting::query()->whereKey($setting->getKey())->lockForUpdate()->firstOrFail();
            $before = $this->current();
            $setting->update($policy);
            app(AuditRecorder::class)->record(new AuditEntry('charging.budget_settings.updated', 'charging_budget_setting', (string) $setting->getKey(), AuditResult::Succeeded, before: $before, after: $policy));
        });
    }

    public function select(int $available, ?int $requested): int
    {
        $policy = $this->current();
        $limit = min($available, $policy['maximum_minor']);
        $budget = $requested ?? min($limit, $policy['default_minor']);
        if (! $policy['allow_custom_amount'] && $requested === null) {
            $eligible = array_filter($policy['presets'], fn (int $amount): bool => $amount <= $budget);
            $budget = $eligible === [] ? 0 : max($eligible);
        }
        if ($budget < $policy['minimum_minor'] || $budget > $limit
            || (! $policy['allow_custom_amount'] && ! in_array($budget, $policy['presets'], true))) {
            throw new DomainException('Choose an allowed charging budget within your available balance and account limits. Add money if your balance is below the minimum.');
        }

        return $budget;
    }
}
