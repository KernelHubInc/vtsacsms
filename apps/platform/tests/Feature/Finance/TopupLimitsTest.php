<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Filament\Platform\Pages\SystemSettings;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Payments\Application\Qr\QrTopups;
use App\Modules\Payments\Application\Qr\TopupLimits;
use App\Modules\Payments\Application\Qr\WalletException;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\TenantSecurityTestCase;

final class TopupLimitsTest extends TenantSecurityTestCase
{
    public function test_admin_limits_are_tenant_scoped_audited_and_enforced_for_new_topups(): void
    {
        config()->set(['wallet.minimum_minor' => 100, 'wallet.maximum_minor' => 100,
            'wallet.mode' => 'simulated', 'features.simulated_payments' => true,
            'kyc.required_for_wallet' => false]);
        $tenant = $this->createTenant('topup-limits');
        $other = $this->createTenant('other-topup-limits');
        $user = $this->createUser();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->withinTenant($tenant, $user, function () use ($tenant, $user): void {
            $membership = $this->createMembership($tenant, $user);
            $role = $this->createRole($tenant, 'settings-manager', [PermissionKey::TenantSettingsView, PermissionKey::TenantSettingsManage]);
            $this->assignDirectly($tenant, $membership, $role);

            Livewire::test(SystemSettings::class)
                ->assertSet('topupMinimum', '1.00')
                ->assertSet('topupMaximum', '1.00')
                ->set('topupMinimum', '100.00')
                ->set('topupMaximum', '500.00')
                ->call('saveTopupLimits')
                ->assertHasNoErrors();

            $this->assertSame(['minimum_minor' => 10000, 'maximum_minor' => 50000], app(TopupLimits::class)->current());
            $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'action' => 'payments.wallet_topup_limits.updated']);

            try {
                app(QrTopups::class)->create($user->public_id, 100, (string) Str::ulid());
                $this->fail('A top-up below the configured minimum was accepted.');
            } catch (WalletException $exception) {
                $this->assertSame('INVALID_TOPUP_AMOUNT', $exception->errorCode);
            }
            $key = (string) Str::ulid();
            $topup = app(QrTopups::class)->create($user->public_id, 10000, $key);
            $this->assertSame(10000, $topup->amount_minor);
            app(TopupLimits::class)->update($user, 20000, 50000);
            $this->assertSame($topup->getKey(), app(QrTopups::class)->create($user->public_id, 10000, $key)->getKey());
            try {
                app(QrTopups::class)->create($user->public_id, 10000, (string) Str::ulid());
                $this->fail('A new request below the updated minimum was accepted.');
            } catch (WalletException $exception) {
                $this->assertSame('INVALID_TOPUP_AMOUNT', $exception->errorCode);
            }
        });

        $this->withinTenant($other, $user, function (): void {
            $this->assertSame(['minimum_minor' => 100, 'maximum_minor' => 100], app(TopupLimits::class)->current());
            $this->assertFalse(SystemSettings::canAccess());
        });
    }

    public function test_invalid_limits_and_unprivileged_updates_are_rejected(): void
    {
        $tenant = $this->createTenant('invalid-topup-limits');
        $user = $this->createUser();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->withinTenant($tenant, $user, function () use ($tenant, $user): void {
            $membership = $this->createMembership($tenant, $user);
            $role = $this->createRole($tenant, 'settings-manager', [PermissionKey::TenantSettingsView, PermissionKey::TenantSettingsManage]);
            $this->assignDirectly($tenant, $membership, $role);

            Livewire::test(SystemSettings::class)
                ->set('topupMinimum', '2.00')
                ->set('topupMaximum', '1.00')
                ->call('saveTopupLimits')
                ->assertHasErrors(['topupMinimum']);
            $this->assertSame(100, app(TopupLimits::class)->current()['minimum_minor']);

            Livewire::test(SystemSettings::class)
                ->set('topupMinimum', '1.001')
                ->call('saveTopupLimits')
                ->assertHasErrors(['topupMinimum']);
        });

        $other = $this->createUser();
        $this->withinTenant($tenant, $other, function () use ($other): void {
            $this->expectException(HttpException::class);
            app(TopupLimits::class)->update($other, 10000, 50000);
        });
    }
}
