<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Support\Application\SupportTicketService;
use App\Modules\Support\Domain\Models\SupportTicket;
use App\Policies\SupportTicketPolicy;
use Tests\Support\TenantSecurityTestCase;

final class SupportPortalTest extends TenantSecurityTestCase
{
    public function test_support_queries_and_policy_are_tenant_scoped(): void
    {
        $agent = $this->createUser();
        $tenantA = $this->createTenant('support-a');
        $tenantB = $this->createTenant('support-b');

        $ticketA = $this->withinTenant($tenantA, $agent, function () use ($tenantA, $agent): SupportTicket {
            $membership = $this->createMembership($tenantA, $agent);
            $role = $this->createRole($tenantA, 'support-agent-a', [
                PermissionKey::SupportView,
                PermissionKey::SupportManage,
            ]);
            $this->assignDirectly($tenantA, $membership, $role);

            return SupportTicket::query()->create([
                'tenant_id' => $tenantA->getKey(),
                'ticket_number' => 'SUP-A',
                'requester_user_id' => $agent->getKey(),
                'status' => 'open',
                'priority' => 'normal',
                'category' => 'general',
                'subject' => 'Visible ticket',
                'description' => 'Tenant A',
            ]);
        });

        $ticketB = $this->withinTenant($tenantB, $agent, fn (): SupportTicket => SupportTicket::query()->create([
            'tenant_id' => $tenantB->getKey(),
            'ticket_number' => 'SUP-B',
            'status' => 'open',
            'priority' => 'normal',
            'category' => 'general',
            'subject' => 'Hidden ticket',
            'description' => 'Tenant B',
        ]));

        $this->withinTenant($tenantA, $agent, function () use ($agent, $ticketA, $ticketB): void {
            $this->assertSame([(string) $ticketA->getKey()], SupportTicket::query()->pluck('id')->all());
            $policy = app(SupportTicketPolicy::class);
            $this->assertTrue($policy->view($agent, $ticketA));
            $this->assertFalse($policy->view($agent, $ticketB));
        });
    }

    public function test_support_response_and_escalation_are_audited(): void
    {
        $agent = $this->createUser();
        $tenant = $this->createTenant('support-actions');

        $this->withinTenant($tenant, $agent, function () use ($tenant, $agent): void {
            $membership = $this->createMembership($tenant, $agent);
            $role = $this->createRole($tenant, 'support-agent', [
                PermissionKey::SupportView,
                PermissionKey::SupportManage,
            ]);
            $this->assignDirectly($tenant, $membership, $role);
            $ticket = SupportTicket::query()->create([
                'tenant_id' => $tenant->getKey(),
                'ticket_number' => 'SUP-001',
                'requester_user_id' => $agent->getKey(),
                'status' => 'open',
                'priority' => 'high',
                'category' => 'accessibility',
                'subject' => 'Assistance requested',
                'description' => 'Test ticket',
            ]);

            $service = app(SupportTicketService::class);
            $message = $service->respond($agent, $ticket, 'We are reviewing this request.', false);
            $escalated = $service->escalate($agent, $ticket);

            $this->assertSame($agent->getKey(), $message->author_user_id);
            $this->assertSame('escalated', $escalated->status);
            $this->assertSame(1, $escalated->escalation_level);
            $this->assertDatabaseHas('audit_events', [
                'tenant_id' => $tenant->getKey(),
                'action' => 'support.ticket.responded',
                'target_id' => $ticket->getKey(),
            ]);
            $this->assertDatabaseHas('audit_events', [
                'tenant_id' => $tenant->getKey(),
                'action' => 'support.ticket.escalated',
                'target_id' => $ticket->getKey(),
            ]);
        });
    }
}
