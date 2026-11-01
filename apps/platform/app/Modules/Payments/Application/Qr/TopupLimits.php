<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Qr;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Payments\Domain\Models\WalletTopupSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class TopupLimits
{
    /** @return array{minimum_minor:int,maximum_minor:int,preset_amounts_minor:list<int>,allow_custom_amount:bool} */
    public function current(): array
    {
        $settings = $this->settings();

        return [
            'minimum_minor' => $settings['minimum_minor'],
            'maximum_minor' => $settings['maximum_minor'],
            'preset_amounts_minor' => array_values(array_map(
                fn (array $preset): int => $preset['amount_minor'],
                array_filter($settings['presets'], fn (array $preset): bool => $preset['enabled']
                    && $preset['amount_minor'] >= $settings['minimum_minor']
                    && $preset['amount_minor'] <= $settings['maximum_minor']),
            )),
            'allow_custom_amount' => $settings['allow_custom_amount'],
        ];
    }

    /** @return array{minimum_minor:int,maximum_minor:int,presets:list<array{amount_minor:int,enabled:bool}>,allow_custom_amount:bool} */
    public function settings(): array
    {
        $setting = WalletTopupSetting::query()->first();
        $minimum = $setting->minimum_minor ?? (int) config('wallet.minimum_minor');
        $maximum = $setting->maximum_minor ?? (int) config('wallet.maximum_minor');

        return [
            'minimum_minor' => $minimum,
            'maximum_minor' => $maximum,
            'presets' => $setting->presets ?? array_values(array_map(
                fn (int $amount): array => ['amount_minor' => $amount, 'enabled' => true],
                array_filter([20000, 50000, 100000, 200000], fn (int $amount): bool => $amount >= $minimum && $amount <= $maximum),
            )),
            'allow_custom_amount' => $setting->allow_custom_amount ?? true,
        ];
    }

    /** @param list<array{amount_minor:int,enabled:bool}>|null $presets */
    public function update(User $actor, int $minimum, int $maximum, ?array $presets = null, ?bool $allowCustom = null): void
    {
        abort_unless(app(AuthorizationService::class)->allows($actor, PermissionKey::TenantSettingsManage), 403);
        if ($minimum < 1 || $maximum < $minimum || $maximum > 100000000) {
            throw ValidationException::withMessages(['topupMinimum' => 'Choose a positive minimum no greater than the maximum; the maximum cannot exceed PHP 1,000,000.00.']);
        }

        DB::transaction(function () use ($minimum, $maximum, $presets, $allowCustom): void {
            $setting = WalletTopupSetting::query()->firstOrCreate([], [
                'minimum_minor' => (int) config('wallet.minimum_minor'),
                'maximum_minor' => (int) config('wallet.maximum_minor'),
            ]);
            $setting = WalletTopupSetting::query()->whereKey($setting->getKey())->lockForUpdate()->firstOrFail();
            $current = $this->settings();
            $presets ??= $current['presets'];
            $allowCustom ??= $current['allow_custom_amount'];
            Validator::make(['topupPresets' => $presets], [
                'topupPresets' => ['present', 'array', 'list'],
                'topupPresets.*' => ['required', 'array:amount_minor,enabled'],
                'topupPresets.*.amount_minor' => ['required', 'integer', 'distinct', 'between:'.$minimum.','.$maximum],
                'topupPresets.*.enabled' => ['required', 'boolean'],
            ], [
                'topupPresets.*.amount_minor.distinct' => 'Each preset amount must be unique.',
                'topupPresets.*.amount_minor.between' => 'Each preset must be within the minimum and maximum top-up amounts.',
            ])->validate();
            if (! $allowCustom && ! collect($presets)->contains(fn (array $preset): bool => (bool) $preset['enabled'])) {
                throw ValidationException::withMessages(['topupPresets' => 'Enable at least one preset or allow Other amount.']);
            }
            $before = $setting->only(['minimum_minor', 'maximum_minor', 'presets', 'allow_custom_amount']);
            $after = ['minimum_minor' => $minimum, 'maximum_minor' => $maximum, 'presets' => $presets, 'allow_custom_amount' => $allowCustom];
            if ($before === $after) {
                return;
            }
            $setting->update($after);
            app(AuditRecorder::class)->record(new AuditEntry(
                'payments.wallet_topup_limits.updated', 'wallet_topup_setting', (string) $setting->getKey(), AuditResult::Succeeded,
                before: $before, after: $after,
            ));
        });
    }
}
