<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Filament\Operator\Resources\Memberships\Pages\ListMemberships;
use App\Modules\Identity\Application\RequestUserPasswordReset;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\PermissionKey;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\TenantSecurityTestCase;

final class UserPasswordResetTest extends TenantSecurityTestCase
{
    public function test_admin_and_super_admin_can_send_a_one_time_link_without_changing_the_password(): void
    {
        Notification::fake();
        foreach (['admin', 'super-admin'] as $key) {
            $tenant = $this->createTenant('recovery-'.$key);
            $actor = $this->createUser();
            $subject = $this->createUser();
            $this->actingAs($actor);
            Filament::setCurrentPanel(Filament::getPanel('admin'));
            $this->withinTenant($tenant, $actor, function () use ($tenant, $actor, $subject, $key): void {
                Organization::factory()->create(['type' => 'platform']);
                $permissions = $key === 'admin'
                    ? [PermissionKey::PlatformPanelAccess, PermissionKey::MembershipView, PermissionKey::MembershipManage]
                    : PermissionKey::cases();
                $this->assignDirectly($tenant, $this->createMembership($tenant, $actor), $this->createRole($tenant, $key, $permissions));
                $member = $this->createMembership($tenant, $subject);
                $password = $subject->password;
                Livewire::test(ListMemberships::class)
                    ->assertTableActionVisible('resetPassword', $member)
                    ->callTableAction('resetPassword', $member)->assertHasNoTableActionErrors();
                Notification::assertSentTo($subject, ResetPassword::class, fn (ResetPassword $notice): bool => Password::tokenExists($subject, $notice->token));
                $this->assertSame($password, $subject->fresh()->password);
                $this->assertDatabaseHas('audit_events', ['action' => 'identity.password.recovery_requested', 'target_id' => $subject->public_id]);
            });
        }
    }

    public function test_missing_admin_permission_and_cross_tenant_targets_are_denied(): void
    {
        Notification::fake();
        $tenant = $this->createTenant('recovery-scope');
        $outside = $this->createTenant('recovery-outside');
        $actor = $this->createUser();
        $subject = $this->createUser();
        $this->withinTenant($outside, $subject, fn () => $this->createMembership($outside, $subject));
        $this->withinTenant($tenant, $actor, function () use ($tenant, $actor, $subject): void {
            $member = $this->createMembership($tenant, $actor);
            $role = $this->createRole($tenant, 'recovery-admin', [PermissionKey::PlatformPanelAccess, PermissionKey::MembershipView, PermissionKey::MembershipManage]);
            $service = app(RequestUserPasswordReset::class);
            $this->assertFalse($service->canRequest($actor));
            $this->assignDirectly($tenant, $member, $role);
            try {
                $service->send($actor, $subject);
                $this->fail('Cross-tenant reset must be denied.');
            } catch (AuthorizationException) {
                Notification::assertNothingSent();
            }
        });
    }

    public function test_repeated_requests_are_throttled(): void
    {
        Notification::fake();
        $tenant = $this->createTenant('recovery-throttle');
        $actor = $this->createUser();
        $subject = $this->createUser();
        $this->withinTenant($tenant, $actor, function () use ($tenant, $actor, $subject): void {
            $this->assignDirectly($tenant, $this->createMembership($tenant, $actor), $this->createRole($tenant, 'admin', PermissionKey::cases()));
            $this->createMembership($tenant, $subject);
            $service = app(RequestUserPasswordReset::class);
            $service->send($actor, $subject);
            try {
                $service->send($actor, $subject);
                $this->fail('Repeated reset must be throttled.');
            } catch (ValidationException $error) {
                $this->assertStringContainsString('wait', $error->errors()['resetPassword'][0]);
            }
            $this->assertDatabaseHas('audit_events', ['action' => 'identity.password.recovery_requested', 'result' => 'failed']);
        });
    }

    public function test_delivery_failure_is_not_reported_as_success_and_is_redacted(): void
    {
        $tenant = $this->createTenant('recovery-delivery');
        $actor = $this->createUser();
        $subject = $this->createUser();
        $this->withinTenant($tenant, $actor, function () use ($tenant, $actor, $subject): void {
            $this->assignDirectly($tenant, $this->createMembership($tenant, $actor), $this->createRole($tenant, 'admin', PermissionKey::cases()));
            $this->createMembership($tenant, $subject);
            Password::shouldReceive('sendResetLink')->once()->andThrow(new \RuntimeException('PRIVATE_SMTP_DETAIL'));
            try {
                app(RequestUserPasswordReset::class)->send($actor, $subject);
                $this->fail('Failed delivery must be reported.');
            } catch (ValidationException $error) {
                $this->assertStringNotContainsString('PRIVATE_SMTP_DETAIL', $error->errors()['resetPassword'][0]);
            }
            $this->assertDatabaseHas('audit_events', ['action' => 'identity.password.recovery_requested', 'result' => 'failed']);
        });
    }
}
