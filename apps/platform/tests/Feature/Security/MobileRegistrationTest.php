<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Foundation\Demo\DemoEnvironment;
use App\Models\User;
use App\Modules\Identity\Notifications\VerifyEmailNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

final class MobileRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mail_failure_preserves_registration_and_requires_email_verification(): void
    {
        Log::spy();
        Log::shouldReceive('warning')->once()->with(
            'identity.registration.verification_email_failed',
            Mockery::on(static fn (array $context): bool => $context['exception_class'] === TransportException::class
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'Private SMTP diagnostic')),
        );
        Notification::shouldReceive('send')->once()->andThrow(new TransportException('Private SMTP diagnostic'));
        config()->set('features.demo_mode', true);
        $this->seedRegistrationScope();

        $this->postJson('/api/v2/auth/register', [
            'birth_date' => '1990-01-01', 'plate_pending' => true, 'first_name' => 'Test', 'last_name' => 'Driver',
            'name' => 'Mail Failure Driver',
            'email' => 'mail.failure@example.test',
            'password' => 'SafePassword!2026',
            'password_confirmation' => 'SafePassword!2026',
            'tenant_id' => DemoEnvironment::TENANT_ID,
        ])->assertCreated()
            ->assertJsonPath('data.verification_email_sent', false)
            ->assertDontSee('Private SMTP diagnostic');

        $user = User::query()->where('email', 'mail.failure@example.test')->sole();
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseHas('memberships', [
            'tenant_id' => DemoEnvironment::TENANT_ID,
            'user_id' => $user->getKey(),
            'status' => 'active',
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'SafePassword!2026',
            'tenant_id' => DemoEnvironment::TENANT_ID, 'device_name' => 'Mail recovery test',
        ])->assertOk()->assertJsonPath('data.user.email_verified', false);
        $this->withToken((string) $login->json('data.token'))->getJson('/api/v1/me')
            ->assertForbidden()->assertJsonPath('error.code', 'email_unverified');
    }

    public function test_staging_mobile_registration_creates_a_scoped_consumer_account(): void
    {
        $this->withoutVite();
        Notification::fake();
        config()->set('features.demo_mode', true);
        $this->seedRegistrationScope();

        $originalEnvironment = app()->environment();
        app()->detectEnvironment(static fn (): string => 'staging');

        try {
            $this->postJson('/api/v2/auth/register', [
                'birth_date' => '1990-01-01', 'plate_pending' => true, 'first_name' => 'Test', 'last_name' => 'Driver',
                'name' => 'New Mobile Driver',
                'email' => 'new.driver@example.test',
                'password' => 'SafePassword!2026',
                'password_confirmation' => 'SafePassword!2026',
                'tenant_id' => DemoEnvironment::TENANT_ID,
            ])->assertCreated();
        } finally {
            app()->detectEnvironment(static fn (): string => $originalEnvironment);
        }

        $user = User::query()->where('email', 'new.driver@example.test')->sole();
        $membership = DB::table('memberships')
            ->where('tenant_id', DemoEnvironment::TENANT_ID)
            ->where('user_id', $user->getKey())
            ->sole();

        $this->assertSame('active', $membership->status);
        $this->assertDatabaseHas('role_assignments', [
            'tenant_id' => DemoEnvironment::TENANT_ID,
            'membership_id' => $membership->id,
            'role_id' => $this->consumerRoleId,
            'scope_type' => 'organization',
            'scope_id' => $this->fleetOrganizationId,
        ]);
        Notification::assertSentTo($user, VerifyEmailNotification::class);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'SafePassword!2026',
            'tenant_id' => DemoEnvironment::TENANT_ID, 'device_name' => 'Staging flow test',
        ])->assertOk()->assertJsonPath('data.user.email_verified', false);
        $token = (string) $login->json('data.token');
        $this->withToken($token)->getJson('/api/v1/me')->assertForbidden()
            ->assertJsonPath('error.code', 'email_unverified');
        $this->postJson('/api/v1/auth/email/verification-notification')->assertAccepted();
        $this->flushHeaders();

        $verificationUrl = URL::temporarySignedRoute(
            'api.v1.auth.email.verify',
            now('UTC')->addMinutes(10),
            ['user' => $user->public_id, 'hash' => sha1($user->email)],
        );
        $this->get($verificationUrl, ['Accept' => 'text/html'])->assertOk()->assertSee('Email verified');
        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $this->assertNotNull($user->fresh()?->email_verified_at);
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => DemoEnvironment::TENANT_ID,
            'action' => 'identity.email.verified',
            'target_id' => $user->public_id,
        ]);
    }

    public function test_production_registration_remains_unavailable(): void
    {
        Notification::fake();
        config()->set('features.demo_mode', true);
        $this->seedRegistrationScope();

        $originalEnvironment = app()->environment();
        app()->detectEnvironment(static fn (): string => 'production');

        try {
            $this->postJson('/api/v2/auth/register', [
                'birth_date' => '1990-01-01', 'plate_pending' => true, 'first_name' => 'Test', 'last_name' => 'Driver',
                'name' => 'Blocked Mobile Driver',
                'email' => 'blocked.driver@example.test',
                'password' => 'SafePassword!2026',
                'password_confirmation' => 'SafePassword!2026',
                'tenant_id' => DemoEnvironment::TENANT_ID,
            ])->assertNotFound();
        } finally {
            app()->detectEnvironment(static fn (): string => $originalEnvironment);
        }

        $this->assertDatabaseMissing('users', ['email' => 'blocked.driver@example.test']);
        Notification::assertNothingSent();
    }

    #[DataProvider('blockedStagingRegistrations')]
    public function test_staging_registration_requires_demo_mode_and_the_demo_tenant(bool $demoMode, string $tenantId): void
    {
        Notification::fake();
        config()->set('features.demo_mode', $demoMode);
        $this->seedRegistrationScope();
        $originalEnvironment = app()->environment();
        app()->detectEnvironment(static fn (): string => 'staging');

        try {
            $this->postJson('/api/v2/auth/register', [
                'birth_date' => '1990-01-01', 'plate_pending' => true, 'first_name' => 'Test', 'last_name' => 'Driver',
                'name' => 'Blocked Staging Driver',
                'email' => 'blocked.staging@example.test',
                'password' => 'SafePassword!2026',
                'password_confirmation' => 'SafePassword!2026',
                'tenant_id' => $tenantId,
            ])->assertNotFound();
        } finally {
            app()->detectEnvironment(static fn (): string => $originalEnvironment);
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('memberships', 0);
        $this->assertDatabaseCount('role_assignments', 0);
        Notification::assertNothingSent();
    }

    /** @return array<string, array{bool, string}> */
    public static function blockedStagingRegistrations(): array
    {
        return [
            'demo mode disabled' => [false, DemoEnvironment::TENANT_ID],
            'another tenant' => [true, '01J00000000000000000000001'],
        ];
    }

    public function test_registration_enforces_eighteenth_birthday_and_records_pending_vehicle(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-08T12:00:00Z'));
        Notification::fake();
        config()->set('features.demo_mode', true);
        $this->seedRegistrationScope();
        $payload = ['first_name' => 'Test', 'middle_name' => 'Middle', 'last_name' => 'Driver',
            'email' => 'age-test@example.test', 'password' => 'SafePassword!2026', 'password_confirmation' => 'SafePassword!2026',
            'tenant_id' => DemoEnvironment::TENANT_ID, 'plate_pending' => true];
        foreach (['2008-10-09', '2027-01-01', '2000-02-30', 'not-a-date'] as $birth) {
            $this->postJson('/api/v2/auth/register', $payload + ['birth_date' => $birth])->assertUnprocessable()
                ->assertJsonPath('error.message', 'Account creation was not successful.');
        }
        $this->postJson('/api/v2/auth/register', $payload + ['birth_date' => '2008-10-08'])->assertCreated();
        $user = User::query()->where('email', 'age-test@example.test')->sole();
        $this->assertSame('Test Middle Driver', $user->name);
        $this->assertSame('2008-10-08', $user->birth_date);
        $this->assertNotSame('2008-10-08', $user->getRawOriginal('birth_date'));
        $this->assertDatabaseHas('driver_vehicles', ['is_default' => true, 'plate_pending' => true, 'plate_number' => null]);
        $this->postJson('/api/v2/auth/register', $payload + ['birth_date' => '2008-10-08'])->assertUnprocessable()
            ->assertJsonPath('error.message', 'Account creation was not successful.')->assertDontSee('already been taken');
    }

    public function test_legacy_registration_requires_update_instead_of_bypassing_age_rule(): void
    {
        $this->postJson('/api/v1/auth/register', ['name' => 'Old client'])->assertStatus(426)
            ->assertJsonPath('error.code', 'app_update_required');
        $this->assertDatabaseCount('users', 0);
    }

    private string $fleetOrganizationId;

    private string $consumerRoleId;

    private function seedRegistrationScope(): void
    {
        $now = now('UTC');
        $this->fleetOrganizationId = (string) Str::ulid();
        $this->consumerRoleId = (string) Str::ulid();

        DB::table('tenants')->insert([
            'id' => DemoEnvironment::TENANT_ID,
            'name' => 'Mobile Registration Tenant',
            'slug' => 'mobile-registration',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('organizations')->insert([
            'id' => $this->fleetOrganizationId,
            'tenant_id' => DemoEnvironment::TENANT_ID,
            'name' => 'Consumer Drivers',
            'code' => 'CONSUMER-DRIVERS',
            'type' => 'fleet',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('roles')->insert([
            'id' => $this->consumerRoleId,
            'tenant_id' => DemoEnvironment::TENANT_ID,
            'key' => 'consumer-driver',
            'name' => 'Consumer Driver',
            'is_system' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
