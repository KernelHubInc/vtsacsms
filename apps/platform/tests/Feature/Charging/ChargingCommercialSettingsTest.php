<?php

declare(strict_types=1);

namespace Tests\Feature\Charging;

use App\Filament\Platform\Pages\SystemSettings;
use App\Modules\Charging\Application\ChargingBudgets;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tariffs\Application\PlatformFees;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\TenantSecurityTestCase;

final class ChargingCommercialSettingsTest extends TenantSecurityTestCase
{
    public function test_settings_are_authorized_tenant_scoped_validated_and_audited(): void
    {
        $this->freezeTime();
        $tenant = $this->createTenant('commercial-settings');
        $other = $this->createTenant('other-commercial');
        $user = $this->createUser();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withinTenant($tenant, $user, function () use ($tenant, $user): void {
            $membership = $this->createMembership($tenant, $user);
            $this->assignDirectly($tenant, $membership, $this->createRole($tenant, 'settings', [PermissionKey::TenantSettingsView, PermissionKey::TenantSettingsManage]));
            $page = Livewire::test(SystemSettings::class)
                ->set('budgetSettings', ['minimum' => '100', 'default' => '500', 'maximum' => '2000', 'presets' => ['200', '500', '1000', '2000'], 'allow_custom_amount' => true])
                ->call('saveChargingBudgets')->assertHasNoErrors();
            self::assertSame(50000, app(ChargingBudgets::class)->select(100000, null));
            self::assertSame(20000, app(ChargingBudgets::class)->select(20000, null));
            self::assertSame(75000, app(ChargingBudgets::class)->select(100000, 75000));
            foreach ([9900, 200001, 100001] as $amount) {
                try {
                    app(ChargingBudgets::class)->select(100000, $amount);
                    self::fail('Invalid budget accepted');
                } catch (\DomainException) {
                }
            }
            $page->set('budgetSettings.default', '2001')->call('saveChargingBudgets')->assertHasErrors(['budgetSettings.default_minor']);
            $page->set('budgetSettings.default', '500')->set('budgetSettings.presets', ['500', '500.00'])
                ->call('saveChargingBudgets')->assertHasErrors(['budgetSettings.presets.0']);
            $page->set('budgetSettings.presets', ['200'])->set('budgetSettings.allow_custom_amount', false)
                ->call('saveChargingBudgets')->assertHasErrors(['budgetSettings.default_minor']);
            $page->set('budgetSettings.presets', ['200', '500'])->call('saveChargingBudgets')->assertHasNoErrors();
            self::assertSame(20000, app(ChargingBudgets::class)->select(40000, null));
            $page->set('feeSettings.enabled', true)->call('publishPlatformFee')->assertHasErrors(['feeSettings.enabled']);
            self::assertFalse(app(PlatformFees::class)->current()['enabled']);
            $page->set('feeSettings.tax_rate', '12.00')->set('feeSettings.beneficiary', 'Test platform')
                ->set('feeSettings.effective_at', now('Asia/Manila')->addHour()->format('Y-m-d\TH:i'))
                ->call('publishPlatformFee')->assertHasNoErrors();
            self::assertFalse(app(PlatformFees::class)->current()['enabled']);
            self::assertCount(1, app(PlatformFees::class)->scheduled());
            $this->travel(61)->minutes();
            self::assertTrue(app(PlatformFees::class)->current()['enabled']);
            self::assertSame(1200, app(PlatformFees::class)->current()['tax_rate_basis_points']);
            $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->id, 'action' => 'charging.budget_settings.updated']);
            $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->id, 'action' => 'tariffs.platform_fee.published']);
        });
        $this->withinTenant($other, $user, function (): void {
            self::assertFalse(app(PlatformFees::class)->current()['enabled']);
            self::assertCount(0, app(PlatformFees::class)->scheduled());
            self::assertSame(1, app(ChargingBudgets::class)->current()['minimum_minor']);
        });
    }

    public function test_viewers_cannot_mutate_either_policy(): void
    {
        $tenant = $this->createTenant('policy-viewer');
        $user = $this->createUser();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withinTenant($tenant, $user, function () use ($tenant, $user): void {
            $membership = $this->createMembership($tenant, $user);
            $this->assignDirectly($tenant, $membership, $this->createRole($tenant, 'viewer', [PermissionKey::TenantSettingsView]));
            foreach ([['addBudgetPreset'], ['removeBudgetPreset', 0], ['saveChargingBudgets'], ['publishPlatformFee']] as $action) {
                Livewire::test(SystemSettings::class)->call(...$action)->assertForbidden();
            }
            $this->assertDatabaseCount('charging_budget_settings', 0);
            $this->assertDatabaseCount('platform_fee_policies', 0);
        });
    }
}
