<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Filament\Platform\Pages\KycSettings as SettingsPage;
use App\Modules\Identity\Application\Kyc\KycService;
use App\Modules\Identity\Application\Kyc\KycSettings;
use App\Modules\Identity\Domain\KycStatus;
use App\Modules\Identity\Domain\Models\KycSetting;
use App\Modules\Identity\Domain\Models\KycVerification;
use App\Modules\Organizations\Domain\PermissionKey;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\TenantSecurityTestCase;

final class KycSettingsTest extends TenantSecurityTestCase
{
    public function test_settings_are_tenant_scoped_authorized_and_audited(): void
    {
        config()->set(['kyc.enabled' => true, 'kyc.automatic_verification_enabled' => true]);
        $tenant = $this->createTenant('kyc-settings');
        $other = $this->createTenant('other-kyc-settings');
        $admin = $this->createUser();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withinTenant($tenant, $admin, function () use ($tenant, $admin): void {
            $membership = $this->createMembership($tenant, $admin);
            $role = $this->createRole($tenant, 'kyc-manager', [PermissionKey::KycSettingsManage]);
            $this->assignDirectly($tenant, $membership, $role);
            Livewire::test(SettingsPage::class)->assertSet('mode', 'manual')
                ->set('mode', 'automatic')->call('save')->assertHasNoErrors();
            $this->assertSame('automatic', app(KycSettings::class)->mode());
            $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'action' => 'identity.kyc.settings_updated']);
        });
        $this->withinTenant($other, $admin, function (): void {
            $this->assertSame('manual', app(KycSettings::class)->mode());
            $this->assertFalse(SettingsPage::canAccess());
        });
    }

    public function test_review_permission_does_not_grant_settings_permission(): void
    {
        config()->set('kyc.enabled', true);
        $tenant = $this->createTenant('kyc-denied');
        $actor = $this->createUser();
        $this->withinTenant($tenant, $actor, function () use ($tenant, $actor): void {
            $membership = $this->createMembership($tenant, $actor);
            $role = $this->createRole($tenant, 'review-only', [PermissionKey::KycReview]);
            $this->assignDirectly($tenant, $membership, $role);
            $this->expectException(HttpException::class);
            app(KycSettings::class)->update($actor, 'manual');
        });
    }

    public function test_settings_tenant_reference_cannot_be_changed_by_the_browser(): void
    {
        config()->set('kyc.enabled', true);
        $tenant = $this->createTenant('kyc-locked-context');
        $actor = $this->createUser();
        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withinTenant($tenant, $actor, function () use ($tenant, $actor): void {
            $membership = $this->createMembership($tenant, $actor);
            $role = $this->createRole($tenant, 'settings', [PermissionKey::KycSettingsManage]);
            $this->assignDirectly($tenant, $membership, $role);
            $this->expectException(CannotUpdateLockedPropertyException::class);
            Livewire::test(SettingsPage::class)->set('tenantId', (string) Str::ulid());
        });
    }

    public function test_automatic_setting_requires_deployment_readiness(): void
    {
        config()->set(['kyc.enabled' => true, 'kyc.automatic_verification_enabled' => false]);
        $tenant = $this->createTenant('kyc-not-ready');
        $actor = $this->createUser();
        $this->withinTenant($tenant, $actor, function () use ($tenant, $actor): void {
            $membership = $this->createMembership($tenant, $actor);
            $role = $this->createRole($tenant, 'settings', [PermissionKey::KycSettingsManage]);
            $this->assignDirectly($tenant, $membership, $role);
            $this->expectException(ValidationException::class);
            app(KycSettings::class)->update($actor, 'automatic');
        });
    }

    public function test_manual_policy_requires_review_even_after_all_checks_pass(): void
    {
        $this->assertOutcome('manual', true, $this->checks(), false, KycStatus::NeedsReview);
    }

    public function test_automatic_policy_approves_only_complete_retained_assurance(): void
    {
        $this->assertOutcome('automatic', true, $this->checks(), false, KycStatus::Approved);
        foreach (['ocr', 'document', 'face_match', 'liveness'] as $check) {
            foreach (['failed', 'unavailable', 'mock'] as $outcome) {
                $this->assertOutcome('automatic', true, [...$this->checks(), $check => $outcome], false, KycStatus::NeedsReview);
            }
            $checks = $this->checks();
            unset($checks[$check]);
            $this->assertOutcome('automatic', true, $checks, false, KycStatus::NeedsReview);
        }
        $this->assertOutcome('automatic', false, $this->checks(), false, KycStatus::NeedsReview);
        $this->assertOutcome('automatic', true, $this->checks(), true, KycStatus::NeedsReview);
    }

    /** @return array<string, string> */
    private function checks(): array
    {
        return ['ocr' => 'passed', 'document' => 'passed', 'face_match' => 'passed', 'liveness' => 'passed'];
    }

    public function test_optical_policy_requires_its_own_checks_and_preserves_issuer_requirements(): void
    {
        $checks = ['ocr' => 'passed', 'optical_document' => 'passed', 'document_data' => 'passed', 'document' => 'unavailable', 'face_match' => 'passed', 'liveness' => 'passed'];
        $this->assertOutcome('automatic', true, $checks, false, KycStatus::Approved, 'optical_v1');
        $this->assertOutcome('manual', true, $checks, false, KycStatus::NeedsReview, 'optical_v1');
        $this->assertOutcome('automatic', false, $checks, false, KycStatus::NeedsReview, 'optical_v1');
        $this->assertOutcome('automatic', true, $checks, true, KycStatus::NeedsReview, 'optical_v1');
        $this->assertOutcome('automatic', true, $checks, false, KycStatus::NeedsReview, 'issuer_v1');
        foreach (['ocr', 'optical_document', 'document_data', 'face_match', 'liveness'] as $key) {
            foreach (['failed', 'unavailable', 'mock'] as $value) {
                $this->assertOutcome('automatic', true, [...$checks, $key => $value], false, KycStatus::NeedsReview, 'optical_v1');
            }
            $missing = $checks;
            unset($missing[$key]);
            $this->assertOutcome('automatic', true, $missing, false, KycStatus::NeedsReview, 'optical_v1');
        }
    }

    /** @param array<string, string> $checks */
    private function assertOutcome(string $mode, bool $enabled, array $checks, bool $deleted, KycStatus $expected, string $profile = 'issuer_v1'): void
    {
        config()->set('kyc.automatic_verification_enabled', $enabled);
        $tenant = $this->createTenant('policy-'.Str::lower(Str::random(8)));
        $user = $this->createUser();
        $this->withinTenant($tenant, $user, function () use ($user, $mode, $checks, $deleted, $expected, $profile): void {
            $row = KycVerification::query()->create([
                'subject_id' => $user->public_id, 'idempotency_key' => (string) Str::ulid(),
                'request_fingerprint' => hash('sha256', 'test'), 'status' => KycStatus::Processing,
                'document_type' => 'passport', 'consent_version' => 'test', 'consented_at' => now(),
                'review_mode' => $mode,
                'assurance_profile' => $profile,
            ]);
            KycSetting::query()->create(['review_mode' => $mode === 'manual' ? 'automatic' : 'manual']);
            $snapshot = ['id' => $row->id, 'tenant_id' => $row->tenant_id, 'subject_id' => $row->subject_id,
                'status' => 'APPROVED', 'version' => 3, 'evidence' => [], 'evidence_deleted' => $deleted,
                'result' => ['provider' => 'test-assurance', 'reason_code' => 'PROVIDER_VERIFIED', 'checks' => $checks, 'duration_ms' => 10]];
            $result = app(KycService::class)->apply($row, $snapshot);
            $this->assertSame($expected, $result->status);
            $this->assertSame($mode, $result->review_mode);
            $this->assertSame($expected, app(KycService::class)->apply($row, $snapshot)->status);
        });
    }
}
