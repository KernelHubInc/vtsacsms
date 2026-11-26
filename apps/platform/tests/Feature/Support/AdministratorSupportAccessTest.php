<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Filament\Operator\Resources\SupportTickets\Pages\ListSupportTickets;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Organizations\Application\AdministratorBootstrap;
use App\Modules\Organizations\Application\AdministratorSupportAccess;
use App\Modules\Organizations\Domain\Models\Role;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Support\Application\SupportTicketService;
use App\Modules\Support\Domain\Models\SupportTicket;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Domain\TenantStatus;
use App\Policies\SupportTicketPolicy;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\TenantSecurityTestCase;

final class AdministratorSupportAccessTest extends TenantSecurityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->withoutVite();
        config(['support.enabled' => true]);
        Mail::fake();
    }

    public function test_repaired_admin_and_existing_superadmin_can_update_reply_and_escalate_in_both_panels(): void
    {
        $tenant = $this->createTenant('administrator-support');
        $admin = $this->createUser();
        $superadmin = $this->createUser();

        $this->withinTenant($tenant, $admin, function () use ($tenant, $admin, $superadmin): void {
            $adminRole = $this->createRole($tenant, 'production-administrator', app(AdministratorBootstrap::class)->administratorPermissions());
            $superRole = $this->createRole($tenant, 'production-super-administrator', [PermissionKey::PlatformPanelAccess, PermissionKey::SupportView, PermissionKey::SupportManage]);
            $superRole->update(['is_system' => true]);
            $this->assignDirectly($tenant, $this->createMembership($tenant, $admin), $adminRole);
            $this->assignDirectly($tenant, $this->createMembership($tenant, $superadmin), $superRole);
            $ticket = $this->ticket();
            $this->actingAs($admin);
            Filament::setCurrentPanel('admin');
            Livewire::test(ListSupportTickets::class)->assertCanSeeTableRecords([$ticket])
                ->assertTableActionHidden('changeStatus', $ticket)
                ->assertTableActionHidden('respond', $ticket)
                ->assertTableActionHidden('escalate', $ticket);

            self::assertSame(0, $this->runRepair(['--tenant' => $tenant->getKey(), '--apply' => true, '--approval-reference' => 'Owner-approved support fix']));

            foreach ([$admin, $superadmin] as $actor) {
                $this->actingAs($actor);
                foreach (['admin', 'operator'] as $panel) {
                    Filament::setCurrentPanel($panel);
                    $case = $this->ticket();
                    Livewire::test(ListSupportTickets::class)
                        ->assertTableActionVisible('changeStatus', $case)
                        ->assertTableActionVisible('respond', $case)
                        ->assertTableActionVisible('escalate', $case)
                        ->callTableAction('changeStatus', $case, data: ['status' => 'in_progress'])->assertHasNoActionErrors();
                    self::assertSame('in_progress', $case->fresh()->status);
                    $key = (string) Str::ulid();
                    Livewire::test(ListSupportTickets::class)->callTableAction('respond', $case,
                        data: ['body' => 'We are reviewing your support request.', 'is_internal' => false, 'idempotency_key' => $key])->assertHasNoActionErrors();
                    Livewire::test(ListSupportTickets::class)->callTableAction('respond', $case,
                        data: ['body' => 'We are reviewing your support request.', 'is_internal' => false, 'idempotency_key' => $key])->assertHasNoActionErrors();
                    Livewire::test(ListSupportTickets::class)->callTableAction('respond', $case,
                        data: ['body' => 'Private investigation note.', 'is_internal' => true, 'idempotency_key' => (string) Str::ulid()])->assertHasNoActionErrors();
                    Livewire::test(ListSupportTickets::class)->callTableAction('escalate', $case, data: ['support_level' => 3])->assertHasNoActionErrors();
                    self::assertSame('escalated', $case->fresh()->status);
                    self::assertSame(3, $case->fresh()->support_level);
                    self::assertSame(2, $case->messages()->count());
                }
            }
            $this->assertDatabaseCount('support_email_deliveries', 4);
            $this->assertDatabaseHas('audit_events', ['action' => 'identity.role.support_management_enabled', 'target_id' => $adminRole->getKey()]);
            self::assertSame(1, DB::table('audit_events')->where('action', 'identity.role.support_management_enabled')->count());
        });
    }

    public function test_preview_is_read_only_and_repair_is_idempotent_and_tenant_scoped(): void
    {
        $tenant = $this->createTenant('support-repair-target');
        $other = $this->createTenant('support-repair-other');
        $actor = $this->createUser();
        $otherRole = $this->withinTenant($other, $actor, fn (): Role => $this->createRole($other, 'production-administrator', [PermissionKey::PlatformPanelAccess, PermissionKey::SupportView]));
        $this->withinTenant($tenant, $actor, function () use ($tenant, $otherRole): void {
            $role = $this->createRole($tenant, 'production-administrator', [PermissionKey::PlatformPanelAccess, PermissionKey::SupportView]);
            $viewer = $this->createRole($tenant, 'support-viewer', [PermissionKey::PlatformPanelAccess, PermissionKey::SupportView]);
            self::assertSame(0, $this->runRepair(['--tenant' => $tenant->getKey()]));
            self::assertFalse($role->permissions()->where('permissions.key', 'support.manage')->exists());
            self::assertSame(0, DB::table('audit_events')->where('action', 'identity.role.support_management_enabled')->count());
            for ($attempt = 0; $attempt < 2; $attempt++) {
                self::assertSame(0, $this->runRepair(['--tenant' => $tenant->getKey(), '--apply' => true, '--approval-reference' => 'Support repair acceptance']));
            }
            self::assertTrue($role->permissions()->where('permissions.key', 'support.manage')->exists());
            self::assertFalse($viewer->permissions()->where('permissions.key', 'support.manage')->exists());
            self::assertFalse(DB::table('role_permissions')->where('tenant_id', $otherRole->tenant_id)->where('role_id', $otherRole->getKey())->where('permission_key', 'support.manage')->exists());
            self::assertSame(1, DB::table('audit_events')->where('action', 'identity.role.support_management_enabled')->count());
        });
    }

    public function test_site_scope_is_preserved_and_a_read_only_user_cannot_forge_ticket_actions(): void
    {
        $tenant = $this->createTenant('support-repair-scope');
        $actor = $this->createUser();
        $viewer = $this->createUser();
        $this->withinTenant($tenant, $actor, function () use ($tenant, $actor, $viewer): void {
            $site = Site::factory()->create();
            $otherSite = Site::factory()->create();
            $role = $this->createRole($tenant, 'platform-admin', [PermissionKey::PlatformPanelAccess, PermissionKey::SupportView]);
            $assignment = $this->assignDirectly($tenant, $this->createMembership($tenant, $actor), $role, new ResourceScope(ScopeType::Site, (string) $site->getKey()));
            $viewerRole = $this->createRole($tenant, 'support-viewer', [PermissionKey::SupportView]);
            $this->assignDirectly($tenant, $this->createMembership($tenant, $viewer), $viewerRole);
            $mine = $this->ticket(['site_id' => $site->getKey()]);
            $theirs = $this->ticket(['site_id' => $otherSite->getKey()]);
            $this->runRepair(['--tenant' => $tenant->getKey(), '--apply' => true, '--approval-reference' => 'Scoped support repair']);
            self::assertSame(ScopeType::Site, $assignment->fresh()->scope_type);
            self::assertSame($site->getKey(), $assignment->fresh()->scope_id);
            self::assertTrue(app(SupportTicketPolicy::class)->update($actor, $mine));
            self::assertFalse(app(SupportTicketPolicy::class)->update($actor, $theirs));
            self::assertFalse(app(SupportTicketPolicy::class)->update($viewer, $mine));
            $this->expectException(AuthorizationException::class);
            app(SupportTicketService::class)->escalate($viewer, $mine, 3);
        });
    }

    public function test_repair_requires_an_active_tenant(): void
    {
        $tenant = $this->createTenant('support-repair-inactive', TenantStatus::Suspended);
        $this->expectException(ValidationException::class);
        $this->runRepair(['--tenant' => $tenant->getKey(), '--apply' => true, '--approval-reference' => 'Inactive tenant must remain denied']);
    }

    public function test_apply_without_owner_reference_is_rejected(): void
    {
        $tenant = $this->createTenant('support-repair-reference');
        $this->expectException(ValidationException::class);
        $this->runRepair(['--tenant' => $tenant->getKey(), '--apply' => true]);
    }

    public function test_repair_does_not_accept_a_role_name_without_existing_administration_and_support_grants(): void
    {
        $tenant = $this->createTenant('support-repair-prerequisites');
        $actor = $this->createUser();
        $this->withinTenant($tenant, $actor, function () use ($tenant): void {
            $this->createRole($tenant, 'production-administrator', [PermissionKey::SupportView]);
            $this->expectException(ValidationException::class);
            $this->runRepair(['--tenant' => $tenant->getKey(), '--apply' => true, '--approval-reference' => 'Reject incomplete role']);
        });
    }

    public function test_human_context_cannot_call_the_operational_repair_service(): void
    {
        $tenant = $this->createTenant('support-repair-human');
        $actor = $this->createUser();
        $this->withinTenant($tenant, $actor, function (): void {
            $this->expectException(AuthorizationException::class);
            app(AdministratorSupportAccess::class)->repair(true, 'Cannot use from a web request');
        });
    }

    /** @param array<string, mixed> $arguments */
    private function runRepair(array $arguments): int
    {
        // A deployment command starts outside any authenticated request context.
        $tenant = app(CurrentTenant::class);
        $previous = $tenant->getOrNull();
        $tenant->clear();
        try {
            return Artisan::call('security:enable-administrator-support', $arguments);
        } finally {
            if ($previous !== null) {
                $tenant->establish($previous);
            }
        }
    }

    /** @param array<string, mixed> $values */
    private function ticket(array $values = []): SupportTicket
    {
        return SupportTicket::query()->create([...[
            'ticket_number' => 'SUP-'.Str::ulid(), 'subject' => 'Support acceptance', 'description' => 'Synthetic support request.',
            'source' => 'website', 'status' => 'open', 'priority' => 'normal', 'category' => 'general',
            'contact_email' => 'support-test@example.test', 'contact_verified_at' => now('UTC'),
        ], ...$values]);
    }
}
