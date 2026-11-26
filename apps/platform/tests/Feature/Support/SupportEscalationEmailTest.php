<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\User;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Notifications\Application\SupportEscalationEmails;
use App\Modules\Notifications\Domain\Models\SupportEscalationDelivery as Delivery;
use App\Modules\Notifications\Mail\SupportEscalationMail;
use App\Modules\Organizations\Application\SupportSuperAdministrators;
use App\Modules\Organizations\Domain\Models\Membership;
use App\Modules\Organizations\Domain\Models\Role;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Support\Application\SupportTicketService;
use App\Modules\Support\Domain\Models\SupportTicket;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Mail\PendingMail;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Tests\Support\TenantSecurityTestCase;

final class SupportEscalationEmailTest extends TenantSecurityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config(['support.enabled' => true, 'mail.default' => 'array', 'app.url' => 'https://staging.example.test']);
    }

    public function test_only_real_level_three_transitions_queue_one_alert_per_superadmin_and_never_send_inside_escalation(): void
    {
        $this->scenario(function (Tenant $tenant, User $actor): void {
            $first = $this->superadmin($tenant);
            $second = $this->superadmin($tenant, 'platform-super-admin');
            $this->assignDirectly($tenant, Membership::query()->where('user_id', $first->getKey())->firstOrFail(), Role::query()->where('key', 'platform-super-admin')->firstOrFail());
            Mail::shouldReceive('to')->never();
            $ticket = $this->ticket();
            $service = app(SupportTicketService::class);
            $service->escalate($actor, $ticket, 1);
            $service->escalate($actor, $ticket, 2);
            $this->assertDatabaseCount('support_escalation_deliveries', 0);
            $service->escalate($actor, $ticket, 3);
            $service->escalate($actor, $ticket->fresh(), 3);
            $this->assertDatabaseCount('support_escalation_deliveries', 2);
            self::assertEqualsCanonicalizing([$first->public_id, $second->public_id], Delivery::query()->pluck('recipient_public_id')->all());
            self::assertSame(1, Delivery::query()->distinct()->count('escalation_id'));
            self::assertSame('escalated', $ticket->fresh()->status);
            self::assertSame(3, $ticket->fresh()->support_level);
            $service->escalate($actor, $ticket->fresh(), 2);
            $service->escalate($actor, $ticket->fresh(), 3);
            $this->assertDatabaseCount('support_escalation_deliveries', 4);
            $this->assertDatabaseCount('support_email_deliveries', 0);
        });
    }

    public function test_intents_are_idempotent_and_rollback_with_the_ticket_transaction(): void
    {
        $this->scenario(function (Tenant $tenant, User $actor): void {
            $this->superadmin($tenant);
            $ticket = $this->ticket();
            DB::beginTransaction();
            try {
                app(SupportTicketService::class)->escalate($actor, $ticket, 3);
                $this->assertDatabaseCount('support_escalation_deliveries', 1);
            } finally {
                DB::rollBack();
            }
            self::assertSame(2, $ticket->fresh()->support_level);
            $this->assertDatabaseCount('support_escalation_deliveries', 0);
            $key = (string) Str::ulid();
            app(SupportEscalationEmails::class)->enqueue((string) $ticket->getKey(), $key);
            app(SupportEscalationEmails::class)->enqueue((string) $ticket->getKey(), $key);
            $this->assertDatabaseCount('support_escalation_deliveries', 1);
        });
    }

    public function test_existing_worker_sends_private_minimal_email_once_with_a_stable_message_id_and_protected_link(): void
    {
        $context = $this->scenario(function (Tenant $tenant, User $actor): array {
            $recipient = $this->superadmin($tenant);
            $ticket = $this->ticket();
            $ticket->messages()->create(['body' => 'Private internal investigation', 'is_internal' => true]);
            app(SupportTicketService::class)->escalate($actor, $ticket, 3);
            $row = Delivery::query()->firstOrFail();

            return compact('tenant', 'actor', 'recipient', 'ticket', 'row');
        });
        self::assertSame(0, Artisan::call('notifications:send-support-emails'));
        self::assertSame(0, Artisan::call('notifications:send-support-emails'));
        $this->withinTenant($context['tenant'], $context['actor'], function () use ($context): void {
            ['row' => $row, 'recipient' => $recipient, 'ticket' => $ticket] = $context;
            $row->refresh();
            self::assertNotNull($row->sent_at);
            self::assertSame(1, $row->attempt_count);
            self::assertSame($row->getKey().'.support-escalation@staging.example.test', $row->message_id);
            $transport = Mail::mailer('array')->getSymfonyTransport();
            self::assertInstanceOf(ArrayTransport::class, $transport);
            self::assertCount(1, $transport->messages());
            $message = $transport->messages()->first()->getOriginalMessage();
            self::assertInstanceOf(Email::class, $message);
            $html = $message->getHtmlBody();
            $text = $message->getTextBody();
            $subject = $message->getSubject();
            self::assertIsString($html);
            self::assertIsString($text);
            self::assertIsString($subject);
            self::assertSame($recipient->email, $message->getTo()[0]->getAddress());
            self::assertStringContainsString('Level 3', $subject);
            self::assertStringContainsString($ticket->ticket_number, $text);
            self::assertStringContainsString('/admin/support-tickets?tableSearch='.$ticket->ticket_number, $html);
            $mail = new SupportEscalationMail(['ticket_number' => (string) $ticket->ticket_number, 'priority' => 'normal'], (string) $row->getKey());
            parse_str((string) parse_url($mail->ticketUrl, PHP_URL_QUERY), $parameters);
            self::assertSame('1', $parameters['page']);
            self::assertSame(array_fill_keys(['support_level', 'category', 'status', 'priority'], ['value' => '']), $parameters['tableFilters']);
            self::assertStringNotContainsString($ticket->description, $html);
            self::assertStringNotContainsString($ticket->subject, $html);
            self::assertStringNotContainsString('Private internal investigation', $html);
            self::assertStringNotContainsString('visitor@example.test', $html);
            self::assertCount(0, $message->getAttachments());
        });
        $this->get('/admin/support-tickets?tableSearch='.$context['ticket']->ticket_number)->assertRedirect();
    }

    public function test_recipients_require_live_tenant_wide_superadmin_membership_and_all_support_permissions(): void
    {
        $other = $this->createTenant('other-support-alerts');
        $otherUser = $this->createUser();
        $this->withinTenant($other, $otherUser, fn () => $this->superadmin($other));
        $this->scenario(function (Tenant $tenant): void {
            $valid = $this->superadmin($tenant);
            $role = Role::query()->where('key', 'production-super-administrator')->firstOrFail();
            foreach (['disabled', 'unverified', 'inactive', 'membership_expired', 'assignment_expired', 'site_scope'] as $case) {
                $user = $this->createUser();
                $membership = $this->createMembership($tenant, $user);
                $scope = $case === 'site_scope' ? new ResourceScope(ScopeType::Site, (string) Site::factory()->create()->getKey()) : null;
                $assignment = $this->assignDirectly($tenant, $membership, $role, $scope);
                match ($case) {
                    'disabled' => $user->forceFill(['disabled_at' => now('UTC')])->save(),
                    'unverified' => $user->forceFill(['email_verified_at' => null])->save(),
                    'inactive' => $membership->forceFill(['status' => 'suspended'])->save(),
                    'membership_expired' => $membership->forceFill(['expires_at' => now('UTC')])->save(),
                    'assignment_expired' => $assignment->forceFill(['expires_at' => now('UTC')])->save(),
                    default => null,
                };
            }
            $this->superadmin($tenant, 'platform-super-admin', [PermissionKey::PlatformPanelAccess, PermissionKey::SupportView]);
            self::assertSame([$valid->public_id], app(SupportSuperAdministrators::class)->ids());
            $ticket = $this->ticket();
            app(SupportEscalationEmails::class)->enqueue((string) $ticket->getKey(), (string) Str::ulid());
            DB::table('role_permissions')->where('tenant_id', $tenant->getKey())->where('role_id', $role->getKey())->where('permission_key', 'support.manage')->delete();
            Mail::shouldReceive('to')->never();
            app(SupportEscalationEmails::class)->deliverDue();
            self::assertNotNull(Delivery::query()->firstOrFail()->cancelled_at);
            self::assertNull(Delivery::query()->firstOrFail()->sent_at);
        });
    }

    public function test_cross_tenant_ticket_and_delivery_ids_never_expose_content_or_send_email(): void
    {
        $other = $this->createTenant('other-escalation-owner');
        $user = $this->createUser();
        $otherTicket = $this->withinTenant($other, $user, fn () => $this->ticket());
        $context = $this->scenario(function (Tenant $tenant, User $actor) use ($otherTicket): array {
            $this->superadmin($tenant);
            app(SupportEscalationEmails::class)->enqueue((string) $otherTicket->getKey(), (string) Str::ulid());
            $row = Delivery::query()->firstOrFail();

            return compact('tenant', 'actor', 'row');
        });
        $row = $context['row'];
        $this->withinTenant($other, $user, function () use ($row): void {
            self::assertNull(Delivery::query()->find($row->getKey()));
            app(SupportEscalationEmails::class)->deliverDue();
        });
        $this->withinTenant($context['tenant'], $context['actor'], function () use ($row): void {
            Mail::shouldReceive('to')->never();
            app(SupportEscalationEmails::class)->deliverDue();
            self::assertNotNull($row->fresh()->cancelled_at);
            self::assertNull($row->fresh()->sent_at);
        });
    }

    public function test_transport_failure_retries_with_bounded_backoff_and_stops_after_eight_attempts(): void
    {
        $this->scenario(function (Tenant $tenant): void {
            $this->superadmin($tenant);
            app(SupportEscalationEmails::class)->enqueue((string) $this->ticket()->getKey(), (string) Str::ulid());
            $pending = $this->createMock(PendingMail::class);
            Mail::shouldReceive('to')->times(8)->andReturn($pending);
            $pending->expects(self::exactly(8))->method('send')->willThrowException(new \RuntimeException('SMTP private credential'));
            $worker = app(SupportEscalationEmails::class);
            $row = Delivery::query()->firstOrFail();
            $worker->deliverDue();
            $worker->deliverDue();
            self::assertSame(1, $row->fresh()->attempt_count);
            self::assertNull($row->fresh()->sent_at);
            for ($i = 1; $i < 8; $i++) {
                $this->travel(3601)->seconds();
                $worker->deliverDue();
            }
            self::assertNotNull($row->fresh()->failed_at);
            self::assertSame('mail_delivery_failed', $row->fresh()->last_error_code);
            $worker->deliverDue();
            self::assertSame(8, $row->fresh()->attempt_count);
        });
    }

    public function test_unexpired_claim_is_skipped_and_an_expired_claim_can_be_recovered(): void
    {
        $this->scenario(function (Tenant $tenant): void {
            $this->superadmin($tenant);
            app(SupportEscalationEmails::class)->enqueue((string) $this->ticket()->getKey(), (string) Str::ulid());
            $row = Delivery::query()->firstOrFail();
            $row->update(['claimed_until' => now('UTC')->addMinutes(5)]);
            app(SupportEscalationEmails::class)->deliverDue();
            self::assertSame(0, $row->fresh()->attempt_count);
            $this->travel(301)->seconds();
            app(SupportEscalationEmails::class)->deliverDue();
            self::assertNotNull($row->fresh()->sent_at);
        });
    }

    public function test_missing_recipients_leave_durable_failure_evidence_instead_of_claiming_email_acceptance(): void
    {
        $this->scenario(function (Tenant $tenant, User $actor): void {
            $ticket = $this->ticket();
            app(SupportTicketService::class)->escalate($actor, $ticket, 3);
            $row = Delivery::query()->firstOrFail();
            self::assertSame('no_eligible_superadmin', $row->last_error_code);
            self::assertNotNull($row->failed_at);
            self::assertNull($row->recipient_public_id);
            self::assertNull($row->sent_at);
            Mail::shouldReceive('to')->never();
            app(SupportEscalationEmails::class)->deliverDue();
            self::assertSame(3, $ticket->fresh()->support_level);
        });
    }

    public function test_targeted_delivery_does_not_consume_another_pending_alert(): void
    {
        $this->scenario(function (Tenant $tenant): void {
            $this->superadmin($tenant);
            $ticket = $this->ticket();
            foreach ([1, 2] as $ignored) {
                app(SupportEscalationEmails::class)->enqueue((string) $ticket->getKey(), (string) Str::ulid());
            }
            $rows = Delivery::query()->orderBy('id')->get();
            app(SupportEscalationEmails::class)->deliverDue(1, (string) $rows[1]->getKey());
            self::assertNull($rows[0]->fresh()->sent_at);
            self::assertSame(0, $rows[0]->fresh()->attempt_count);
            self::assertNotNull($rows[1]->fresh()->sent_at);
        });
    }

    public function test_disabled_support_never_queues_an_escalation_alert(): void
    {
        config(['support.enabled' => false]);
        $this->scenario(function (Tenant $tenant, User $actor): void {
            $this->superadmin($tenant);
            app(SupportTicketService::class)->escalate($actor, $this->ticket(), 3);
            $this->assertDatabaseCount('support_escalation_deliveries', 0);
        });
    }

    private function scenario(\Closure $operation): mixed
    {
        $tenant = $this->createTenant('support-escalation');
        $actor = $this->createUser();

        return $this->withinTenant($tenant, $actor, function () use ($tenant, $actor, $operation): mixed {
            $role = $this->createRole($tenant, 'production-administrator', $this->permissions());
            $this->assignDirectly($tenant, $this->createMembership($tenant, $actor), $role);

            return $operation($tenant, $actor);
        });
    }

    /** @param list<PermissionKey>|null $permissions */
    private function superadmin(Tenant $tenant, string $key = 'production-super-administrator', ?array $permissions = null): User
    {
        $user = $this->createUser();
        $role = $this->createRole($tenant, $key, $permissions ?? $this->permissions());
        $this->assignDirectly($tenant, $this->createMembership($tenant, $user), $role);

        return $user;
    }

    /** @return list<PermissionKey> */
    private function permissions(): array
    {
        return [PermissionKey::PlatformPanelAccess, PermissionKey::SupportView, PermissionKey::SupportManage];
    }

    private function ticket(): SupportTicket
    {
        return SupportTicket::query()->create(['ticket_number' => 'SUP-'.Str::ulid(), 'source' => 'website', 'category' => 'general',
            'subject' => 'Private customer subject', 'description' => 'Private customer report with sensitive details.',
            'contact_email' => 'visitor@example.test', 'contact_verified_at' => now('UTC')]);
    }
}
