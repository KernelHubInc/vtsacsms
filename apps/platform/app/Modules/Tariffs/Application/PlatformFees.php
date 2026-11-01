<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tariffs\Domain\Models\PlatformFeePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class PlatformFees
{
    /** @return array<string, mixed> */
    public function current(): array
    {
        $policy = PlatformFeePolicy::query()->where('effective_at', '<=', now('UTC'))->orderByDesc('effective_at')->orderByDesc('id')->first();

        return $policy === null ? ['id' => null, 'enabled' => false, 'amount_minor' => 500, 'waiver_threshold_minor' => 10000,
            'tax_rate_basis_points' => null, 'beneficiary' => null, 'effective_at' => null]
            : $policy->only(['id', 'enabled', 'amount_minor', 'waiver_threshold_minor', 'tax_rate_basis_points', 'beneficiary', 'effective_at']);
    }

    /** @return list<array<string, mixed>> */
    public function scheduled(): array
    {
        return array_values(PlatformFeePolicy::query()->where('effective_at', '>', now('UTC'))->orderBy('effective_at')->get()
            ->map(fn (PlatformFeePolicy $policy): array => $policy->only(['enabled', 'amount_minor', 'effective_at']))->all());
    }

    /** @param array<string, mixed> $policy */
    public function publish(User $actor, array $policy): void
    {
        abort_unless(app(AuthorizationService::class)->allows($actor, PermissionKey::TenantSettingsManage), 403);
        Validator::make(['feeSettings' => $policy], [
            'feeSettings' => ['required', 'array:enabled,amount_minor,waiver_threshold_minor,tax_rate_basis_points,beneficiary,effective_at'],
            'feeSettings.enabled' => ['required', 'boolean'],
            'feeSettings.amount_minor' => ['required', 'integer', 'between:1,100000000'],
            'feeSettings.waiver_threshold_minor' => ['required', 'integer', 'between:0,100000000'],
            'feeSettings.tax_rate_basis_points' => ['nullable', 'integer', 'between:0,10000'],
            'feeSettings.beneficiary' => ['nullable', 'string', 'max:120'],
            'feeSettings.effective_at' => ['required', 'date'],
        ])->validate();
        if ($policy['enabled'] && ($policy['tax_rate_basis_points'] === null || trim((string) $policy['beneficiary']) === '')) {
            throw ValidationException::withMessages(['feeSettings.enabled' => 'Confirm the fee beneficiary and tax rate before enabling. The displayed fee includes any configured tax.']);
        }
        $policy['effective_at'] = CarbonImmutable::parse($policy['effective_at'])->utc()->max(CarbonImmutable::now('UTC'));
        DB::transaction(function () use ($policy): void {
            $before = $this->current();
            $record = PlatformFeePolicy::query()->create($policy);
            app(AuditRecorder::class)->record(new AuditEntry('tariffs.platform_fee.published', 'platform_fee_policy', (string) $record->getKey(), AuditResult::Succeeded, before: $before, after: $policy));
        });
    }
}
