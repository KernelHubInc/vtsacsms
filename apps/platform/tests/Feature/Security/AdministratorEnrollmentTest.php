<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Foundation\Audit\Models\AuditEvent;
use App\Models\User;
use App\Modules\Identity\Application\PanelAccessService;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Identity\Domain\Models\WorkforceEnrollment;
use App\Modules\Identity\Notifications\WorkforceEnrollmentNotification;
use App\Modules\Organizations\Application\AdministratorBootstrap;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\Models\Role;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TenantSecurityTestCase;

final class AdministratorEnrollmentTest extends TenantSecurityTestCase
{
    private Tenant $tenant;

    /** @var array<string, WorkforceEnrollmentNotification> */
    private array $emails = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Notification::fake();
        $this->tenant = $this->createTenant('initial-admin');
        $this->withoutVite();
    }

    /** @return array{organization_id:string,super_subject_id:string,admin_subject_id:string,super_role_id:string,admin_role_id:string} */
    private function bootstrap(bool $resend = false): array
    {
        $context = new TenantContext((string) $this->tenant->getKey(), ActorType::Platform, null, (string) Str::ulid());
        $result = app(CurrentTenant::class)->run($context, fn (): array => app(AdministratorBootstrap::class)->create(
            'super.admin@example.test', 'admin@example.test', 'Power Solutions Platform', 'owner-approval-test', $resend,
        ));
        Notification::assertSentOnDemand(WorkforceEnrollmentNotification::class, function (WorkforceEnrollmentNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool {
            $this->emails[$notifiable->routes['mail']] = $notification;

            return true;
        });

        return $result;
    }

    private function token(string $email): string
    {
        $mail = $this->emails[$email]->toMail((object) []);
        $fragment = parse_url($mail->actionUrl, PHP_URL_FRAGMENT);
        self::assertIsString($fragment);
        parse_str($fragment, $fields);
        self::assertIsString($fields['token']);

        return $fields['token'];
    }

    /** @return array<string, string> */
    private function setupData(string $email): array
    {
        return ['enrollment_token' => $this->token($email), 'name' => 'Account owner', 'password' => 'Private-Password-2026!', 'password_confirmation' => 'Private-Password-2026!'];
    }

    public function test_bootstrap_is_atomic_idempotent_audited_and_accounts_are_disabled_until_private_setup(): void
    {
        $first = $this->bootstrap();
        $this->assertSame($first, $this->bootstrap());
        $this->assertDatabaseCount('organization_administrator_bootstraps', 1);
        $this->assertDatabaseCount('identity_workforce_enrollments', 2);
        $this->assertDatabaseCount('role_assignments', 2);
        Notification::assertSentOnDemandTimes(WorkforceEnrollmentNotification::class, 2);
        foreach (User::query()->get() as $user) {
            $this->assertNull($user->activated_at);
            $this->assertNull($user->email_verified_at);
            $this->assertFalse(app(PanelAccessService::class)->canAccess($user, 'admin'));
        }
        $this->withinTenant($this->tenant, User::query()->firstOrFail(), function () use ($first): void {
            $this->assertSame(67, Role::query()->findOrFail($first['super_role_id'])->permissions()->count());
            $this->assertTrue(Role::query()->findOrFail($first['super_role_id'])->is_system);
            $admin = Role::query()->findOrFail($first['admin_role_id']);
            $this->assertFalse($admin->is_system);
            $this->assertEqualsCanonicalizing(array_column(app(AdministratorBootstrap::class)->administratorPermissions(), 'value'), $admin->permissions()->pluck('key')->all());
        });
        $this->assertSame(2, AuditEvent::query()->where('action', 'identity.role_assignment.bootstrapped')->where('reason', 'owner-approval-test')->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'organizations.administrators.bootstrapped')->count());
    }

    public function test_owner_sets_password_once_then_admin_has_exact_approved_authority(): void
    {
        $this->bootstrap();
        $data = $this->setupData('admin@example.test');
        $this->postJson('/workforce/setup', $data)->assertOk()->assertJsonPath('data.code', 'workforce_enrollment_completed')->assertHeader('Cache-Control', 'no-store, private');
        $user = User::query()->where('email', 'admin@example.test')->sole();
        $this->assertTrue(Hash::check($data['password'], $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(app(PanelAccessService::class)->canAccess($user, 'admin'));
        $this->assertGuest();
        $this->withinTenant($this->tenant, $user, function () use ($user): void {
            $authorization = app(AuthorizationService::class);
            $this->assertTrue($authorization->allows($user, PermissionKey::RoleManage));
            $this->assertTrue($authorization->allows($user, PermissionKey::RoleAssign));
            foreach ([PermissionKey::PaymentRefund, PermissionKey::ChargingRemoteCommand, PermissionKey::ReportingExport, PermissionKey::KycSensitiveView] as $permission) {
                $this->assertFalse($authorization->allows($user, $permission));
            }
            $enrollment = WorkforceEnrollment::query()->where('subject_id', $user->public_id)->sole();
            $this->assertNull($enrollment->token_hash);
            $this->assertNotNull($enrollment->completed_at);
        });
        $this->postJson('/workforce/setup', $data)->assertStatus(422)->assertJsonPath('error.code', 'enrollment_unavailable');
        $this->assertSame(1, AuditEvent::query()->where('action', 'identity.workforce_enrollment.completed')->count());
    }

    public function test_setup_url_keeps_token_out_of_http_urls_and_form_html_and_mail_jobs_are_encrypted(): void
    {
        $this->bootstrap();
        $notification = $this->emails['super.admin@example.test'];
        $url = $notification->toMail((object) [])->actionUrl;
        $this->assertNull(parse_url($url, PHP_URL_QUERY));
        $this->assertSame('/workforce/setup', parse_url($url, PHP_URL_PATH));
        $this->assertInstanceOf(ShouldBeEncrypted::class, $notification);
        $this->assertSame('invitations', $notification->queue);
        $this->assertTrue($notification->afterCommit);
        $this->get('/workforce/setup')->assertOk()->assertDontSee($this->token('super.admin@example.test'))
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Content-Security-Policy');
        $this->assertStringNotContainsString($this->token('super.admin@example.test'), json_encode(DB::table('identity_workforce_enrollments')->get(), JSON_THROW_ON_ERROR));
    }

    public function test_weak_password_mismatch_and_invalid_token_cannot_activate_an_account(): void
    {
        $this->bootstrap();
        $data = $this->setupData('admin@example.test');
        foreach (['short1!', 'onlylowercase-2026!', str_repeat('Aa1!', 20)] as $password) {
            $this->postJson('/workforce/setup', array_replace($data, ['password' => $password, 'password_confirmation' => $password]))->assertStatus(422)->assertJsonValidationErrors('password', 'error.fields');
            $this->travel(2)->minutes();
        }
        $this->postJson('/workforce/setup', array_replace($data, ['password_confirmation' => 'Something-Else-2026!']))->assertStatus(422);
        $this->postJson('/workforce/setup', array_replace($data, ['enrollment_token' => str_repeat('a', 64)]))->assertStatus(422);
        $this->assertNull(User::query()->where('email', 'admin@example.test')->sole()->activated_at);
    }

    public function test_expired_and_rotated_tokens_fail_and_new_email_link_succeeds(): void
    {
        $this->bootstrap();
        $old = $this->setupData('admin@example.test');
        $this->travel(25)->hours();
        $this->postJson('/workforce/setup', $old)->assertStatus(422);
        $this->bootstrap(resend: true);
        $fresh = $this->setupData('admin@example.test');
        $this->assertNotSame($old['enrollment_token'], $fresh['enrollment_token']);
        $this->postJson('/workforce/setup', $old)->assertStatus(422);
        $this->postJson('/workforce/setup', $fresh)->assertOk();
        $this->assertDatabaseCount('role_assignments', 2);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_revoked_grant_or_suspended_tenant_blocks_completion_without_changing_password(): void
    {
        $result = $this->bootstrap();
        $user = User::query()->where('email', 'admin@example.test')->sole();
        $before = $user->password;
        DB::table('role_assignments')->where('role_id', $result['admin_role_id'])->delete();
        $this->postJson('/workforce/setup', $this->setupData('admin@example.test'))->assertStatus(422);
        $this->assertSame($before, $user->fresh()->password);
        $this->assertNull($user->fresh()->activated_at);
        $this->tenant->update(['status' => TenantStatus::Suspended]);
        $this->postJson('/workforce/setup', $this->setupData('super.admin@example.test'))->assertStatus(422);
    }

    public function test_mfa_requirement_is_never_cleared_and_cross_tenant_role_does_not_unlock_an_account(): void
    {
        $result = $this->bootstrap();
        $user = User::query()->where('email', 'super.admin@example.test')->sole();
        $user->forceFill(['mfa_required' => true])->save();
        $this->postJson('/workforce/setup', $this->setupData('super.admin@example.test'))->assertOk();
        $this->assertTrue($user->fresh()->mfa_required);
        $this->assertFalse(app(PanelAccessService::class)->canAccess($user->fresh(), 'admin'));
        $user = User::query()->where('email', 'admin@example.test')->sole();
        $other = $this->createTenant('other-bootstrap');
        $this->withinTenant($other, $user, function () use ($other, $user): void {
            $member = $this->createMembership($other, $user);
            $this->assignDirectly($other, $member, $this->createRole($other, 'other-admin', [PermissionKey::PlatformPanelAccess]));
        });
        DB::table('role_assignments')->where('role_id', $result['admin_role_id'])->delete();
        $this->postJson('/workforce/setup', $this->setupData('admin@example.test'))->assertStatus(422);
    }

    public function test_existing_identity_rolls_back_the_entire_bootstrap_and_queues_no_email(): void
    {
        $user = $this->createUser();
        $user->update(['email' => 'ADMIN@example.test']);
        $context = new TenantContext((string) $this->tenant->getKey(), ActorType::Platform, null, (string) Str::ulid());
        try {
            app(CurrentTenant::class)->run($context, fn () => app(AdministratorBootstrap::class)->create('super.admin@example.test', 'admin@example.test', 'Power Solutions Platform', 'approved-test'));
            $this->fail('Existing identities must not be overwritten.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseCount('roles', 0);
            $this->assertDatabaseCount('organizations', 0);
            $this->assertDatabaseCount('role_assignments', 0);
            $this->assertDatabaseCount('identity_workforce_enrollments', 0);
            $this->assertDatabaseCount('organization_administrator_bootstraps', 0);
            Notification::assertNothingSent();
        }
    }

    public function test_human_context_cannot_use_bootstrap_and_a_different_plan_is_rejected(): void
    {
        $user = $this->createUser();
        $this->withinTenant($this->tenant, $user, function (): void {
            try {
                app(AdministratorBootstrap::class)->create('super.admin@example.test', 'admin@example.test', 'Power Solutions Platform', 'approval');
                $this->fail('Human requests must not bootstrap privileges.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('organization_administrator_bootstraps', 0);
            }
        });
        $this->bootstrap();
        $context = new TenantContext((string) $this->tenant->getKey(), ActorType::Platform, null, (string) Str::ulid());
        $this->expectException(ValidationException::class);
        app(CurrentTenant::class)->run($context, fn () => app(AdministratorBootstrap::class)->create('different@example.test', 'admin@example.test', 'Power Solutions Platform', 'approval'));
    }

    public function test_delivery_is_recorded_only_for_current_mail_and_expired_hashes_are_cleared(): void
    {
        $this->bootstrap();
        $mail = $this->emails['admin@example.test'];
        $this->assertTrue($mail->shouldSend((object) [], 'mail'));
        Event::dispatch(new NotificationSent((object) [], $mail, 'mail', null));
        $this->assertSame(1, DB::table('identity_workforce_enrollments')->whereNotNull('email_sent_at')->count());
        $this->travel(25)->hours();
        $this->assertSame(0, Artisan::call('security:expire-workforce-enrollments'));
        $this->assertFalse($mail->shouldSend((object) [], 'mail'));
        $this->assertSame(0, DB::table('identity_workforce_enrollments')->whereNotNull('token_hash')->count());
        $this->assertDatabaseCount('role_assignments', 2);
    }

    public function test_existing_platform_administrator_prevents_bootstrap(): void
    {
        $user = $this->createUser();
        $this->withinTenant($this->tenant, $user, function () use ($user): void {
            $member = $this->createMembership($this->tenant, $user);
            $this->assignDirectly($this->tenant, $member, $this->createRole($this->tenant, 'existing-admin', [PermissionKey::PlatformPanelAccess]));
        });
        $context = new TenantContext((string) $this->tenant->getKey(), ActorType::Platform, null, (string) Str::ulid());
        try {
            app(CurrentTenant::class)->run($context, fn () => app(AdministratorBootstrap::class)->create('super.admin@example.test', 'admin@example.test', 'Power Solutions Platform', 'approval'));
            $this->fail('Existing administration requires normal authorized management.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseCount('organization_administrator_bootstraps', 0);
            Notification::assertNothingSent();
        }
    }

    public function test_stale_mail_is_not_delivered_and_completed_accounts_cannot_be_reenrolled(): void
    {
        $this->bootstrap();
        $old = $this->emails['admin@example.test'];
        $this->bootstrap(resend: true);
        $this->assertFalse($old->shouldSend((object) [], 'mail'));
        Event::dispatch(new NotificationSent((object) [], $old, 'mail', null));
        $this->assertSame(0, DB::table('identity_workforce_enrollments')->whereNotNull('email_sent_at')->count());
        $this->postJson('/workforce/setup', $this->setupData('admin@example.test'))->assertOk();
        Notification::fake();
        $this->bootstrap(resend: true);
        Notification::assertSentOnDemandTimes(WorkforceEnrollmentNotification::class, 1);
        $this->assertSame(1, DB::table('identity_workforce_enrollments')->whereNotNull('completed_at')->count());
    }

    public function test_suspended_identity_cannot_enroll_and_validation_never_flashes_secrets(): void
    {
        $this->bootstrap();
        $user = User::query()->where('email', 'admin@example.test')->sole();
        $user->forceFill(['disabled_at' => now('UTC')])->save();
        $data = $this->setupData('admin@example.test');
        $this->post('/workforce/setup', $data)->assertStatus(422)->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.enrollment_token');
        $this->assertNull($user->fresh()->activated_at);
        $this->assertSame(0, DB::table('identity_workforce_enrollments')->whereNotNull('completed_at')->count());
    }

    public function test_enrollment_endpoint_is_rate_limited(): void
    {
        $data = ['enrollment_token' => str_repeat('b', 64), 'name' => 'Owner', 'password' => 'Private-Password-2026!', 'password_confirmation' => 'Private-Password-2026!'];
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson('/workforce/setup', $data)->assertStatus(422);
        }
        $this->postJson('/workforce/setup', $data)->assertStatus(429);
    }

    public function test_command_requires_explicit_confirmation_and_emits_no_credentials(): void
    {
        $options = ['--tenant' => $this->tenant->getKey(), '--super-admin' => 'super.admin@example.test', '--admin' => 'admin@example.test', '--organization' => 'Power Solutions Platform', '--approval-reference' => 'owner-approved-test'];
        $this->assertSame(1, Artisan::call('security:bootstrap-administrators', $options));
        $this->assertSame(0, Artisan::call('security:bootstrap-administrators', $options + ['--confirm-bootstrap' => true]));
        $this->assertStringNotContainsString('password', Artisan::output());
        $this->assertStringNotContainsString('token', Artisan::output());
        $this->assertStringNotContainsString('/workforce/setup', Artisan::output());
        $this->assertDatabaseCount('role_assignments', 2);
    }
}
