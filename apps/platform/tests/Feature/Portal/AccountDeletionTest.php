<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Filament\Operator\Resources\SupportTickets\SupportTicketResource;
use App\Models\User;
use App\Modules\Identity\Notifications\AccountDeletionCodeNotification;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Support\Application\SupportTicketService;
use App\Modules\Support\Domain\Models\SupportTicket;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Tests\Support\TenantSecurityTestCase;

final class AccountDeletionTest extends TenantSecurityTestCase
{
    public function test_confirmed_owner_creates_one_audited_ticket_in_their_tenant(): void
    {
        Notification::fake();
        $this->withoutVite();
        $tenant = $this->createTenant('deletion-requester');
        $otherTenant = $this->createTenant('unrelated-registration');
        config(['registration.tenant_id' => $otherTenant->getKey()]);
        $user = $this->createUser();
        $this->withinTenant($tenant, $user, fn () => $this->createMembership($tenant, $user));

        $this->get('/account-deletion')
            ->assertOk()->assertSee('Request account deletion')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->post('/account-deletion/code', ['email' => strtoupper($user->email)])->assertRedirect('/account-deletion');
        $this->assertDatabaseCount('support_tickets', 0);
        $code = $this->lastCodeFor($user);
        $this->post('/account-deletion/confirm', ['account_deletion_code' => '00000000'])
            ->assertSessionHasErrors('account_deletion_code');
        $this->post('/account-deletion/confirm', ['account_deletion_code' => $code])
            ->assertRedirect('/account-deletion')->assertSessionHas('account_deletion.received', true);
        $this->assertDatabaseHas('support_tickets', [
            'tenant_id' => $tenant->getKey(), 'requester_user_id' => $user->getKey(),
            'category' => SupportTicket::ACCOUNT_DELETION, 'status' => 'open',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->getKey(), 'actor_id' => $user->public_id, 'action' => 'support.account_deletion.requested',
        ]);
        $this->post('/account-deletion/confirm', ['account_deletion_code' => $code])->assertSessionHasErrors('account_deletion_code');

        $this->post('/account-deletion/code', ['email' => $user->email])->assertRedirect('/account-deletion');
        $this->post('/account-deletion/confirm', ['account_deletion_code' => $this->lastCodeFor($user)])
            ->assertSessionHas('account_deletion.received', true);
        $this->assertDatabaseCount('support_tickets', 1);
        $this->assertDatabaseHas('users', ['id' => $user->getKey()]);
    }

    public function test_unknown_email_uses_the_same_confirmation_screen_and_codes_expire(): void
    {
        Notification::fake();
        $this->withoutVite();
        $this->post('/account-deletion/code', ['email' => 'unknown@example.test'])->assertRedirect('/account-deletion');
        $this->get('/account-deletion')->assertSee('If the email matches an eligible account');
        Notification::assertNothingSent();

        $tenant = $this->createTenant('deletion-expiry');
        $user = $this->createUser();
        $this->withinTenant($tenant, $user, fn () => $this->createMembership($tenant, $user));
        $this->post('/account-deletion/code', ['email' => $user->email])->assertRedirect('/account-deletion');
        $code = $this->lastCodeFor($user);
        $this->travel(16)->minutes();
        $this->post('/account-deletion/confirm', ['account_deletion_code' => $code])->assertSessionHasErrors('account_deletion_code');
        $this->assertDatabaseCount('support_tickets', 0);
    }

    public function test_only_tenant_wide_support_can_read_or_resolve_a_deletion_ticket(): void
    {
        $tenant = $this->createTenant('deletion-support');
        $requester = $this->createUser();
        $operator = $this->createUser();
        $admin = $this->createUser();
        $ticket = $this->withinTenant($tenant, $requester, function () use ($tenant, $requester, $operator, $admin): SupportTicket {
            $organization = Organization::factory()->create();
            $role = $this->createRole($tenant, 'deletion-review', [PermissionKey::SupportView, PermissionKey::SupportManage]);
            $this->assignDirectly($tenant, $this->createMembership($tenant, $operator), $role, new ResourceScope(ScopeType::Organization, (string) $organization->getKey()));
            $this->assignDirectly($tenant, $this->createMembership($tenant, $admin), $role);

            return SupportTicket::query()->create([
                'ticket_number' => 'PRIV-TEST',
                'requester_user_id' => $requester->getKey(),
                'category' => SupportTicket::ACCOUNT_DELETION,
                'subject' => 'Account deletion request',
                'description' => 'Verified request.',
            ]);
        });
        $this->actingAs($operator);
        $this->withinTenant($tenant, $operator, function () use ($operator, $ticket): void {
            $this->assertFalse(Gate::forUser($operator)->allows('view', $ticket));
            $this->assertFalse(Gate::forUser($operator)->allows('update', $ticket));
            $this->assertFalse(SupportTicketResource::getEloquentQuery()->whereKey($ticket->getKey())->exists());
        });
        $this->actingAs($admin);
        $this->withinTenant($tenant, $admin, function () use ($admin, $ticket): void {
            $this->assertTrue(SupportTicketResource::getEloquentQuery()->whereKey($ticket->getKey())->exists());
            app(SupportTicketService::class)->resolveAccountDeletion($admin, $ticket);
            $this->assertSame('resolved', $ticket->refresh()->status);
        });
    }

    private function lastCodeFor(User $user): string
    {
        $notification = Notification::sent($user, AccountDeletionCodeNotification::class)->last();
        $this->assertInstanceOf(AccountDeletionCodeNotification::class, $notification);

        return collect($notification->toMail($user)->introLines)
            ->sole(fn ($line): bool => is_string($line) && preg_match('/^[0-9]{8}$/', $line) === 1);
    }
}
