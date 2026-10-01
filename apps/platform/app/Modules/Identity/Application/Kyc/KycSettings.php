<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Kyc;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Identity\Domain\Models\KycSetting;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class KycSettings
{
    public function mode(): string
    {
        return KycSetting::query()->value('review_mode') ?? 'manual';
    }

    public function update(User $actor, string $mode): void
    {
        abort_unless(config('kyc.enabled') && app(AuthorizationService::class)->allows($actor, PermissionKey::KycSettingsManage), 403);
        if (! in_array($mode, ['manual', 'automatic'], true)) {
            throw ValidationException::withMessages(['mode' => 'Choose a valid review mode.']);
        }
        if ($mode === 'automatic' && ! config('kyc.automatic_verification_enabled')) {
            throw ValidationException::withMessages(['mode' => 'Automatic verification requires an accepted document, face matching and liveness deployment.']);
        }
        DB::transaction(function () use ($mode): void {
            KycSetting::query()->firstOrCreate([], ['review_mode' => 'manual']);
            $setting = KycSetting::query()->lockForUpdate()->firstOrFail();
            $before = $setting->review_mode;
            $setting->update(['review_mode' => $mode]);
            app(AuditRecorder::class)->record(new AuditEntry(
                'identity.kyc.settings_updated', 'kyc_setting', (string) $setting->getKey(), AuditResult::Succeeded,
                before: ['review_mode' => $before], after: ['review_mode' => $mode],
            ));
        });
    }
}
