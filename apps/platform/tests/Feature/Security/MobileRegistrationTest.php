<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Foundation\Demo\DemoEnvironment;
use App\Models\User;
use App\Modules\Identity\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MobileRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_staging_mobile_registration_creates_a_scoped_consumer_account(): void
    {
        Notification::fake();
        config()->set('features.demo_mode', true);
        $this->seedRegistrationScope();

        $originalEnvironment = app()->environment();
        app()->detectEnvironment(static fn (): string => 'staging');

        try {
            $this->postJson('/api/v1/auth/register', [
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
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

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
            $this->postJson('/api/v1/auth/register', [
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
