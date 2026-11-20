<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Filament\Operator\Resources\SupportTickets\Pages\ListSupportTickets;
use App\Models\User;
use App\Modules\Assets\Domain\Models\ChargingCurrentType;
use App\Modules\Assets\Domain\Models\ConnectorStandard;
use App\Modules\Charging\Domain\Models\ChargingQuote;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Charging\Domain\Models\CustomerChargingSession;
use App\Modules\CMS\Domain\Models\CmsPage;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Notifications\Mail\SupportVerificationMail;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\Role;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Support\Application\AccessibleSupportTickets;
use App\Modules\Support\Application\SupportContactRetention;
use App\Modules\Support\Application\SupportImageRetention;
use App\Modules\Support\Application\SupportImages;
use App\Modules\Support\Application\SupportTicketService;
use App\Modules\Support\Application\WebsiteSupportRequests;
use App\Modules\Support\Domain\Models\SupportTicket;
use App\Modules\Support\Domain\Models\SupportTicketImage;
use App\Modules\Tariffs\Domain\Models\TariffVersion;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Policies\SupportTicketPolicy;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\TenantSecurityTestCase;

final class SupportIntakeTest extends TenantSecurityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->withoutVite();
        config(['support.enabled' => true]);
        Mail::fake();
        ConnectorStandard::query()->create(['code' => 'TYPE2', 'name' => 'Type 2']);
        ChargingCurrentType::query()->create(['code' => 'AC', 'name' => 'AC']);
    }

    public function test_existing_mobile_payload_creates_one_linked_ticket_and_acknowledgement(): void
    {
        [$tenant, $actor, $session] = $this->customer();
        $token = $this->login($actor, $tenant);
        $body = ['session_id' => $session->getKey(), 'category' => 'charger', 'description' => 'The connector did not release after my charging session.'];
        $this->withToken($token)->withHeader('Idempotency-Key', 'mobile-issue-once')->postJson('/api/v1/mobile/support/issues', $body)
            ->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonStructure(['data' => ['id', 'ticket_number']]);
        $this->postJson('/api/v1/mobile/support/issues', $body)->assertCreated();
        $this->withHeader('Idempotency-Key', 'manual-retry-new-key')->postJson('/api/v1/mobile/support/issues', $body)->assertCreated();
        $this->assertDatabaseCount('support_tickets', 1);
        $this->assertDatabaseCount('support_email_deliveries', 1);
        $this->assertDatabaseHas('support_tickets', ['tenant_id' => $tenant->getKey(), 'site_id' => $session->site_id, 'session_id' => $session->getKey(), 'requester_user_id' => $actor->getKey()]);
        $this->postJson('/api/v1/mobile/support/issues', [...$body, 'description' => 'A different issue with the same reference.'])->assertUnprocessable();
        Mail::assertNothingSent();
    }

    public function test_manual_retry_alias_stays_idempotent_after_resolution_and_new_reports_can_be_opened(): void
    {
        [$tenant, $actor, $session] = $this->customer();
        $token = $this->login($actor, $tenant);
        $body = ['session_id' => $session->getKey(), 'category' => 'charger', 'description' => 'The connector did not release after my charging session.'];
        $first = $this->withToken($token)->withHeader('Idempotency-Key', 'first-report')->postJson('/api/v1/mobile/support/issues', $body)->assertCreated()->json('data.id');
        $this->withHeader('Idempotency-Key', 'manual-retry-alias')->postJson('/api/v1/mobile/support/issues', $body)->assertCreated()->assertJsonPath('data.id', $first);
        $agent = $this->createUser();
        $this->withinTenant($tenant, $agent, function () use ($tenant, $agent, $first): void {
            $this->staff($tenant, $agent);
            app(SupportTicketService::class)->changeStatus($agent, SupportTicket::query()->whereKey($first)->firstOrFail(), 'resolved');
        });
        $this->postJson('/api/v1/mobile/support/issues', $body)->assertCreated()->assertJsonPath('data.id', $first);
        $second = $this->withHeader('Idempotency-Key', 'new-report-after-resolution')->postJson('/api/v1/mobile/support/issues', $body)->assertCreated()->json('data.id');
        self::assertNotSame($first, $second);
        $this->assertDatabaseCount('support_tickets', 2);
        $this->assertDatabaseCount('support_intake_requests', 3);
        $this->assertDatabaseCount('support_email_deliveries', 2);
        $this->withinTenant($tenant, $agent, function () use ($agent, $first): void {
            $this->expectException(ValidationException::class);
            app(SupportTicketService::class)->changeStatus($agent, SupportTicket::query()->whereKey($first)->firstOrFail(), 'open');
        });
    }

    public function test_mobile_submission_rejects_another_driver_or_tenant_session(): void
    {
        [$tenant, $actor, $session] = $this->customer();
        $other = $this->createUser();
        $this->withinTenant($tenant, $other, fn () => $this->createMembership($tenant, $other));
        $this->withToken($this->login($other, $tenant))->withHeader('Idempotency-Key', 'foreign-driver-issue')
            ->postJson('/api/v1/mobile/support/issues', ['session_id' => $session->getKey(), 'category' => 'payment', 'description' => 'Attempt to report another driver session.'])->assertNotFound();
        [$second, $secondUser] = $this->customer();
        $this->withToken($this->login($secondUser, $second))->postJson('/api/v1/mobile/support/issues',
            ['session_id' => $session->getKey(), 'category' => 'payment', 'description' => 'Attempt to report a cross tenant session.'])->assertNotFound();
        $this->assertDatabaseCount('support_tickets', 0);
    }

    public function test_mobile_auth_validation_feature_gate_and_safe_errors(): void
    {
        $url = '/api/v1/mobile/support/issues';
        $this->postJson($url, [])->assertUnauthorized()->assertJsonStructure(['error' => ['code', 'message', 'correlation_id']]);
        [$tenant, $actor] = $this->customer();
        $token = $this->login($actor, $tenant);
        $this->withToken($token)->postJson($url, ['category' => 'account_deletion', 'description' => 'x'])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['fields', 'correlation_id']]);
        config(['support.enabled' => false]);
        $this->postJson($url, [])->assertNotFound();
        $this->assertDatabaseCount('support_tickets', 0);
    }

    public function test_visitor_can_verify_email_without_creating_an_app_account(): void
    {
        $tenant = $this->publicTenant();
        $page = $this->get('/support')->assertOk()->assertSee('No app account is required.');
        self::assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
        $this->travel(4)->seconds();
        $this->post('/support/code', $this->webData())->assertRedirect('/support')->assertSessionHas('support.challenge');
        $code = $this->lastCode();
        $challenge = session('support.challenge');
        self::assertStringNotContainsString('visitor@example.test', json_encode(Cache::get('support:challenge:'.$challenge), JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('support_tickets', 0);
        $this->post('/support/confirm', ['support_code' => $code])->assertRedirect('/support')->assertSessionHas('support.receipt');
        $this->get('/support')->assertSee('Your request is with us.')->assertSee('SUP-');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseHas('support_tickets', ['tenant_id' => $tenant->getKey(), 'source' => 'website', 'requester_user_id' => null]);
        $raw = DB::table('support_tickets')->first();
        self::assertNotSame('visitor@example.test', $raw->contact_email);
        $this->assertDatabaseCount('support_email_deliveries', 1);
    }

    public function test_verification_is_browser_bound_and_resists_replay_and_tenant_changes(): void
    {
        $this->publicTenant();
        $requests = app(WebsiteSupportRequests::class);
        $challenge = $requests->start($this->webData(), null);
        $code = $this->lastCode();
        $this->post('/support/confirm', ['support_code' => $code])->assertSessionHasErrors('support_code');
        $receipt = $requests->confirm($challenge, $code, (string) Str::ulid());
        self::assertNotNull($receipt);
        self::assertSame($receipt, $requests->confirm($challenge, $code, (string) Str::ulid()));
        $other = $this->createTenant('support-public-other');
        config(['support.public_tenant_id' => $other->getKey()]);
        self::assertNull($requests->confirm($challenge, $code, (string) Str::ulid()));
        $this->assertDatabaseCount('support_tickets', 1);
    }

    public function test_codes_expire_and_five_wrong_codes_invalidate_the_challenge(): void
    {
        $this->publicTenant();
        $requests = app(WebsiteSupportRequests::class);
        $challenge = $requests->start($this->webData(), null);
        $code = $this->lastCode();
        for ($i = 0; $i < 5; $i++) {
            self::assertNull($requests->confirm($challenge, '00000000', (string) Str::ulid()));
        }
        self::assertNull($requests->confirm($challenge, $code, (string) Str::ulid()));
        $challenge = $requests->start($this->webData(), null);
        $this->travel(16)->minutes();
        self::assertNull($requests->confirm($challenge, $this->lastCode(), (string) Str::ulid()));
        $this->assertDatabaseCount('support_tickets', 0);
    }

    public function test_website_honeypot_timing_and_per_email_limits_are_enforced(): void
    {
        $this->publicTenant();
        $this->get('/support')->assertOk();
        $this->post('/support/code', $this->webData())->assertStatus(429);
        $this->travel(4)->seconds();
        $this->post('/support/code', [...$this->webData(), 'company_website' => 'https://spam.example'])->assertSessionHasErrors('company_website');
        $requests = app(WebsiteSupportRequests::class);
        for ($i = 0; $i < 3; $i++) {
            $requests->start($this->webData(), null);
        }
        $this->post('/support/code', $this->webData())->assertStatus(429);
        $this->assertDatabaseCount('support_tickets', 0);
    }

    public function test_unknown_station_does_not_create_a_ticket_or_grant_private_session_access(): void
    {
        $this->publicTenant();
        $requests = app(WebsiteSupportRequests::class);
        $challenge = $requests->start([...$this->webData(), 'station_id' => (string) Str::ulid()], null);
        $this->expectException(ModelNotFoundException::class);
        $requests->confirm($challenge, $this->lastCode(), (string) Str::ulid());
    }

    public function test_operator_scope_filters_list_details_replies_and_revoked_grants(): void
    {
        $tenant = $this->createTenant('support-scopes');
        $agent = $this->createUser();
        $this->withinTenant($tenant, $agent, function () use ($tenant, $agent): void {
            $first = Site::factory()->create();
            $second = Site::factory()->create();
            $membership = $this->createMembership($tenant, $agent);
            $role = $this->createRole($tenant, 'support-site-agent', [PermissionKey::SupportView, PermissionKey::SupportManage]);
            $this->assignDirectly($tenant, $membership, $role, new ResourceScope(ScopeType::Site, (string) $first->getKey()));
            $mine = $this->ticket(['site_id' => $first->getKey()]);
            $other = $this->ticket(['site_id' => $second->getKey()]);
            $general = $this->ticket();
            $deletion = $this->ticket(['site_id' => $first->getKey(), 'category' => SupportTicket::ACCOUNT_DELETION]);
            $query = app(AccessibleSupportTickets::class);
            self::assertSame([(string) $mine->getKey()], $query->for($agent)->pluck('id')->all());
            $policy = app(SupportTicketPolicy::class);
            foreach ([$other, $general, $deletion] as $denied) {
                self::assertFalse($policy->view($agent, $denied));
                self::assertFalse($policy->update($agent, $denied));
            }
            self::assertTrue($policy->update($agent, $mine));
            DB::table('role_permissions')->where('role_id', $role->getKey())->delete();
            self::assertFalse($policy->update($agent, $mine));
        });
    }

    public function test_public_reply_is_idempotent_and_internal_notes_never_generate_email(): void
    {
        $tenant = $this->createTenant('support-replies');
        $agent = $this->createUser();
        $this->withinTenant($tenant, $agent, function () use ($tenant, $agent): void {
            $this->staff($tenant, $agent);
            $ticket = $this->ticket(['source' => 'website', 'contact_email' => 'visitor@example.test', 'contact_verified_at' => now('UTC')]);
            $service = app(SupportTicketService::class);
            $note = $service->respond($agent, $ticket, 'Private troubleshooting evidence for the team.', true, 'note-once');
            self::assertTrue($note->is_internal);
            $this->assertDatabaseCount('support_email_deliveries', 0);
            $reply = $service->respond($agent, $ticket, 'We are investigating the connector issue.', false, 'reply-once');
            self::assertSame($reply->getKey(), $service->respond($agent, $ticket, $reply->body, false, 'reply-once')->getKey());
            self::assertSame('pending_customer', $ticket->fresh()->status);
            $this->assertDatabaseCount('support_ticket_messages', 2);
            $this->assertDatabaseCount('support_email_deliveries', 1);
        });
    }

    public function test_guest_contact_retention_preserves_open_cases_and_does_not_delete_case_history(): void
    {
        $tenant = $this->createTenant('support-retention');
        $agent = $this->createUser();
        $this->withinTenant($tenant, $agent, function () use ($tenant, $agent): void {
            $this->staff($tenant, $agent);
            $ticket = $this->ticket(['source' => 'website', 'contact_email' => 'visitor@example.test', 'contact_verified_at' => now('UTC')]);
            $open = $this->ticket(['source' => 'website', 'contact_email' => 'open@example.test', 'contact_verified_at' => now('UTC')]);
            app(SupportTicketService::class)->changeStatus($agent, $ticket, 'resolved');
            $this->travel(89)->days();
            self::assertSame(0, app(SupportContactRetention::class)->purgeDue());
            $this->travel(2)->days();
            self::assertSame(1, app(SupportContactRetention::class)->purgeDue());
            self::assertNull($ticket->fresh()->contact_email);
            self::assertSame('open@example.test', $open->fresh()->contact_email);
            $this->assertDatabaseCount('support_tickets', 2);
        });
    }

    public function test_operator_portal_filters_records_and_distinguishes_private_notes_from_public_replies(): void
    {
        $tenant = $this->createTenant('support-portal');
        $agent = $this->createUser();
        $this->actingAs($agent);
        Filament::setCurrentPanel('operator');
        $this->withinTenant($tenant, $agent, function () use ($tenant, $agent): void {
            $first = Site::factory()->create();
            $second = Site::factory()->create();
            $membership = $this->createMembership($tenant, $agent);
            $role = $this->createRole($tenant, 'support-site-agent', [PermissionKey::SupportView, PermissionKey::SupportManage]);
            $this->assignDirectly($tenant, $membership, $role, new ResourceScope(ScopeType::Site, (string) $first->getKey()));
            $mine = $this->ticket(['site_id' => $first->getKey(), 'source' => 'website', 'contact_email' => 'visitor@example.test', 'contact_verified_at' => now('UTC')]);
            $other = $this->ticket(['site_id' => $second->getKey()]);
            Livewire::test(ListSupportTickets::class)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$other])
                ->callTableAction('respond', $mine, data: ['body' => 'Private troubleshooting note.', 'is_internal' => true])->assertHasNoActionErrors();
            $this->assertDatabaseCount('support_email_deliveries', 0);
            Livewire::test(ListSupportTickets::class)->callTableAction('respond', $mine,
                data: ['body' => 'Our team is reviewing this issue.', 'is_internal' => false])->assertHasNoActionErrors();
            $this->assertDatabaseCount('support_email_deliveries', 1);
            self::assertSame('pending_customer', $mine->fresh()->status);
        });
    }

    public function test_configured_operator_organization_scope_does_not_include_other_operators(): void
    {
        $tenant = $this->createTenant('support-operator-scope');
        $agent = $this->createUser();
        $this->withinTenant($tenant, $agent, function () use ($tenant, $agent): void {
            $first = Site::factory()->create();
            $second = Site::factory()->create();
            $membership = $this->createMembership($tenant, $agent);
            $role = $this->createRole($tenant, 'operator-support', [PermissionKey::SupportView, PermissionKey::SupportManage]);
            $this->assignDirectly($tenant, $membership, $role, new ResourceScope(ScopeType::Organization, $first->operator_organization_id));
            $mine = $this->ticket(['site_id' => $first->getKey()]);
            $other = $this->ticket(['site_id' => $second->getKey()]);
            self::assertSame([(string) $mine->getKey()], app(AccessibleSupportTickets::class)->for($agent)->pluck('id')->all());
            self::assertFalse(app(SupportTicketPolicy::class)->update($agent, $other));
            $membership->update(['status' => 'suspended']);
            self::assertSame(0, app(AccessibleSupportTickets::class)->for($agent)->count());
        });
    }

    public function test_feature_off_preserves_the_existing_public_support_page(): void
    {
        $this->publicTenant();
        config(['support.enabled' => false]);
        $this->get('/support')->assertOk()->assertDontSee('No app account is required.');
        $this->post('/support/code', $this->webData())->assertNotFound();
        Mail::assertNothingSent();
    }

    public function test_mobile_multiple_images_are_atomic_encrypted_and_idempotent_with_level_two_default(): void
    {
        [$tenant, $actor, $session] = $this->customer();
        $body = ['session_id' => $session->getKey(), 'category' => 'charger', 'description' => 'Two images show the connector issue.',
            'images' => [UploadedFile::fake()->image('one.jpg', 40, 40), UploadedFile::fake()->image('two.png', 40, 40)], 'support_level' => 3];
        $this->withToken($this->login($actor, $tenant))->withHeader('Accept', 'application/json')->withHeader('Idempotency-Key', 'mobile-images-once')
            ->post('/api/v1/mobile/support/issues', $body)->assertCreated();
        $this->post('/api/v1/mobile/support/issues', $body)->assertCreated();
        $this->assertDatabaseCount('support_tickets', 1);
        $this->assertDatabaseCount('support_ticket_images', 2);
        $this->assertDatabaseHas('support_tickets', ['support_level' => 2]);
        $raw = DB::table('support_ticket_images')->first();
        self::assertNotSame(file_get_contents($body['images'][0]->getPathname()), $raw->content);
        $this->withinTenant($tenant, $actor, function (): void {
            $image = SupportTicketImage::query()->firstOrFail();
            self::assertArrayNotHasKey('content', $image->toArray());
            self::assertNotFalse(getimagesizefromstring($image->content));
        });
        $this->post('/api/v1/mobile/support/issues', [...$body, 'images' => [UploadedFile::fake()->image('different.jpg', 50, 50)]])->assertUnprocessable();
        $this->assertDatabaseCount('support_ticket_images', 2);
    }

    public function test_invalid_images_never_create_a_ticket_or_durable_upload(): void
    {
        [$tenant, $actor, $session] = $this->customer();
        $body = ['session_id' => $session->getKey(), 'category' => 'charger', 'description' => 'An image validation regression test.'];
        $this->withToken($this->login($actor, $tenant))->withHeader('Accept', 'application/json')->withHeader('Idempotency-Key', 'mobile-images-invalid');
        foreach ([
            [UploadedFile::fake()->createWithContent('payload.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
            [UploadedFile::fake()->image('large.jpg')->size(2049)],
            [UploadedFile::fake()->image('wide.png', 3001, 1)],
            array_fill(0, 6, UploadedFile::fake()->image('many.png', 10, 10)),
        ] as $files) {
            $this->post('/api/v1/mobile/support/issues', [...$body, 'images' => $files])->assertUnprocessable();
        }
        $this->assertDatabaseCount('support_tickets', 0);
        $this->assertDatabaseCount('support_ticket_images', 0);
    }

    public function test_website_images_are_accepted_only_after_verification_and_payload_metadata_is_removed(): void
    {
        $this->publicTenant();
        $requests = app(WebsiteSupportRequests::class);
        $challenge = $requests->start($this->webData(), null);
        $original = UploadedFile::fake()->image('photo.jpg', 50, 50);
        $image = UploadedFile::fake()->createWithContent('photo.jpg', file_get_contents($original->getPathname()).'PRIVATE_APPENDED_CONTENT');
        $this->withSession(['support.challenge' => $challenge])->post('/support/confirm', ['support_code' => '00000000', 'images' => [$image]])
            ->assertSessionHasErrors('support_code');
        $this->assertDatabaseCount('support_ticket_images', 0);
        $this->post('/support/confirm', ['support_code' => $this->lastCode(), 'images' => [$image]])->assertRedirect('/support')->assertSessionHas('support.receipt');
        $this->assertDatabaseCount('support_ticket_images', 1);
        self::assertStringNotContainsString('PRIVATE_APPENDED_CONTENT', Crypt::decryptString(DB::table('support_ticket_images')->value('content')));
    }

    public function test_support_levels_are_validated_audited_and_do_not_grant_cross_site_access(): void
    {
        $tenant = $this->createTenant('support-levels');
        $actor = $this->createUser();
        $this->withinTenant($tenant, $actor, function () use ($tenant, $actor): void {
            $this->staff($tenant, $actor);
            $ticket = $this->ticket();
            self::assertSame(2, (int) $ticket->fresh()->support_level);
            foreach ([2, 3, 1] as $level) {
                app(SupportTicketService::class)->escalate($actor, $ticket, $level);
                self::assertSame($level, (int) $ticket->fresh()->support_level);
            }
            $this->assertDatabaseHas('audit_events', ['action' => 'support.ticket.escalated', 'target_id' => $ticket->getKey()]);
            $this->expectException(ValidationException::class);
            app(SupportTicketService::class)->escalate($actor, $ticket, 4);
        });
    }

    public function test_image_download_checks_authentication_tenant_and_reveals_only_safe_image_bytes(): void
    {
        $tenant = $this->createTenant('support-image-reader');
        $actor = $this->createUser();
        $imageId = $this->withinTenant($tenant, $actor, function () use ($tenant, $actor): string {
            Organization::factory()->create(['type' => 'platform']);
            $this->assignDirectly($tenant, $this->createMembership($tenant, $actor), $this->createRole($tenant, 'image-reader', [PermissionKey::PlatformPanelAccess, PermissionKey::SupportView]));
            $ticket = $this->ticket();
            app(SupportImages::class)->save($ticket, app(SupportImages::class)->prepare([UploadedFile::fake()->image('private.jpg', 30, 30)]));

            return (string) $ticket->images()->firstOrFail()->getKey();
        });
        $url = '/support-images/admin/'.$imageId;
        $this->get($url)->assertUnauthorized();
        $this->actingAs($actor)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'image/jpeg');
        $other = $this->createTenant('support-image-outside');
        $outsider = $this->createUser();
        $this->withinTenant($other, $outsider, function () use ($other, $outsider): void {
            Organization::factory()->create(['type' => 'platform']);
            $this->assignDirectly($other, $this->createMembership($other, $outsider), $this->createRole($other, 'image-reader', PermissionKey::cases()));
        });
        $this->actingAs($outsider)->get($url)->assertNotFound();
    }

    public function test_image_retention_is_tenant_scoped_and_keeps_reopened_cases(): void
    {
        $tenant = $this->createTenant('support-image-retention');
        $actor = $this->createUser();
        $this->withinTenant($tenant, $actor, function (): void {
            $ticket = $this->ticket(['status' => 'resolved', 'resolved_at' => now()->subDays(89)]);
            app(SupportImages::class)->save($ticket, app(SupportImages::class)->prepare([UploadedFile::fake()->image('closed.jpg', 30, 30)]));
            self::assertSame(0, app(SupportImageRetention::class)->purgeDue());
            $ticket->update(['status' => 'open', 'resolved_at' => now()->subDays(91)]);
            self::assertSame(0, app(SupportImageRetention::class)->purgeDue());
            $ticket->update(['status' => 'resolved']);
            self::assertSame(1, app(SupportImageRetention::class)->purgeDue());
            $this->assertDatabaseHas('support_ticket_images', ['content' => null]);
            self::assertSame(0, app(SupportImageRetention::class)->purgeDue());
        });
    }

    private function publicTenant(): Tenant
    {
        $tenant = $this->createTenant('website-support');
        config(['support.public_tenant_id' => $tenant->getKey()]);
        CmsPage::query()->create(['slug' => 'support', 'title' => 'Support', 'template' => 'support', 'status' => 'published', 'legal_review_required' => false, 'published_at' => now('UTC')]);

        return $tenant;
    }

    /** @return array{email:string,subject:string,category:string,description:string} */
    private function webData(): array
    {
        return ['email' => 'visitor@example.test', 'subject' => 'Help with charging', 'category' => 'general', 'description' => 'I would like help using the charging station.'];
    }

    private function lastCode(): string
    {
        return (string) Mail::sent(SupportVerificationMail::class)->last()->code;
    }

    /** @param array<string,mixed> $values */
    private function ticket(array $values = []): SupportTicket
    {
        return SupportTicket::query()->create([...['ticket_number' => 'SUP-'.Str::ulid(), 'subject' => 'Support request', 'description' => 'Customer needs assistance.'], ...$values]);
    }

    private function staff(Tenant $tenant, User $agent): Role
    {
        $membership = $this->createMembership($tenant, $agent);
        $role = $this->createRole($tenant, 'support-agent', [PermissionKey::SupportView, PermissionKey::SupportManage, PermissionKey::SupportSensitiveReveal]);
        $this->assignDirectly($tenant, $membership, $role);

        return $role;
    }

    /** @return array{Tenant,User,ChargingSession} */
    private function customer(): array
    {
        $tenant = $this->createTenant('driver-'.Str::lower((string) Str::ulid()));
        $user = $this->createUser();
        $session = $this->withinTenant($tenant, $user, function () use ($tenant, $user): ChargingSession {
            $this->createMembership($tenant, $user);
            $session = ChargingSession::factory()->create();
            $version = TariffVersion::factory()->create();
            $quote = ChargingQuote::query()->create(['subject_id' => $user->public_id, 'connector_id' => $session->connector_id,
                'tariff_version_id' => $version->getKey(), 'budget_minor' => 100, 'disclosure' => [], 'expires_at' => now('UTC')->addHour()]);
            CustomerChargingSession::query()->create(['subject_id' => $user->public_id, 'session_id' => $session->getKey(), 'quote_id' => $quote->getKey(),
                'budget_minor' => 100, 'idempotency_hash' => hash('sha256', (string) Str::ulid())]);

            return $session;
        });

        return [$tenant, $user, $session];
    }

    private function login(User $user, Tenant $tenant): string
    {
        return (string) $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'tenant_id' => $tenant->getKey(), 'device_name' => 'Support test'])
            ->assertOk()->json('data.token');
    }
}
