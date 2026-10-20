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
use Illuminate\Validation\ValidationException;

final readonly class TopupLimits
{
    /** @return array{minimum_minor:int,maximum_minor:int} */
    public function current(): array
    {
        $setting = WalletTopupSetting::query()->first();

        if ($setting === null) {
            return [
                'minimum_minor' => (int) config('wallet.minimum_minor'),
                'maximum_minor' => (int) config('wallet.maximum_minor'),
            ];
        }

        return [
            'minimum_minor' => $setting->minimum_minor,
            'maximum_minor' => $setting->maximum_minor,
        ];
    }

    public function update(User $actor, int $minimum, int $maximum): void
    {
        abort_unless(app(AuthorizationService::class)->allows($actor, PermissionKey::TenantSettingsManage), 403);
        if ($minimum < 1 || $maximum < $minimum || $maximum > 100000000) {
            throw ValidationException::withMessages(['topupMinimum' => 'Choose a positive minimum no greater than the maximum; the maximum cannot exceed PHP 1,000,000.00.']);
        }

        DB::transaction(function () use ($minimum, $maximum): void {
            $setting = WalletTopupSetting::query()->firstOrCreate([], [
                'minimum_minor' => (int) config('wallet.minimum_minor'),
                'maximum_minor' => (int) config('wallet.maximum_minor'),
            ]);
            $setting = WalletTopupSetting::query()->whereKey($setting->getKey())->lockForUpdate()->firstOrFail();
            $before = $setting->only(['minimum_minor', 'maximum_minor']);
            $after = ['minimum_minor' => $minimum, 'maximum_minor' => $maximum];
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
