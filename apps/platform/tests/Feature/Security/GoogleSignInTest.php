<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Identity\Notifications\VerifyEmailNotification;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class GoogleSignInTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $organizationId;

    private string $privateKey;

    /** @var array<string, mixed> */
    private array $jwks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 11, 9)->startOfDay());
        JWT::$timestamp = now('UTC')->getTimestamp();
        Notification::fake();
        Http::preventStrayRequests();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $private);
        $this->privateKey = $private;
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $this->jwks = ['keys' => [[
            'kty' => 'RSA', 'kid' => 'google-test-key', 'alg' => 'RS256', 'use' => 'sig',
            'n' => JWT::urlsafeB64Encode($details['rsa']['n']),
            'e' => JWT::urlsafeB64Encode($details['rsa']['e']),
        ]]];
        Http::fake(['https://www.googleapis.com/oauth2/v3/certs' => Http::response($this->jwks, 200, ['Cache-Control' => 'public, max-age=7200'])]);
        $this->tenantId = (string) Str::ulid();
        $this->organizationId = (string) Str::ulid();
        DB::table('tenants')->insert(['id' => $this->tenantId, 'name' => 'Test tenant', 'slug' => 'google-test', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organizations')->insert(['id' => $this->organizationId, 'tenant_id' => $this->tenantId, 'name' => 'Consumers', 'code' => 'CONSUMERS', 'type' => 'fleet', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('roles')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'key' => 'consumer-driver', 'name' => 'Consumer Driver', 'is_system' => true, 'created_at' => now(), 'updated_at' => now()]);
        config()->set([
            'google_sign_in.enabled' => true, 'google_sign_in.tenant_id' => $this->tenantId,
            'google_sign_in.server_client_id' => 'server.apps.googleusercontent.com',
            'google_sign_in.android_client_id' => 'android.apps.googleusercontent.com',
            'registration.enabled' => true, 'registration.tenant_id' => $this->tenantId,
            'registration.organization_id' => $this->organizationId,
        ]);
    }

    protected function tearDown(): void
    {
        JWT::$timestamp = null;
        $this->travelBack();
        parent::tearDown();
    }

    /** @param array<string, mixed> $overrides */
    private function token(array $overrides = [], string $kid = 'google-test-key'): string
    {
        return JWT::encode(array_replace([
            'iss' => 'https://accounts.google.com', 'aud' => 'server.apps.googleusercontent.com',
            'azp' => 'android.apps.googleusercontent.com', 'sub' => 'google-subject-one',
            'email' => 'driver@gmail.com', 'email_verified' => true,
            'given_name' => 'Google', 'family_name' => 'Driver',
            'iat' => now('UTC')->timestamp, 'exp' => now('UTC')->addHour()->timestamp,
        ], $overrides), $this->privateKey, 'RS256', $kid);
    }

    /** @return array<string, mixed> */
    private function loginPayload(?string $token = null): array
    {
        return ['id_token' => $token ?? $this->token(), 'tenant_id' => $this->tenantId, 'device_name' => 'Test Android'];
    }

    private function existingUser(bool $linked = false): User
    {
        $user = User::factory()->create(['email' => 'driver@gmail.com', 'password' => 'ExistingPass1!', 'activated_at' => now(), 'email_verified_at' => now()]);
        if ($linked) {
            $user->forceFill(['google_subject' => 'google-subject-one'])->save();
        }
        DB::table('memberships')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId,
            'user_id' => $user->getKey(), 'organization_id' => $this->organizationId,
            'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    public function test_new_google_account_requires_registration_without_issuing_a_token(): void
    {
        $this->postJson('/api/v1/auth/google', $this->loginPayload())->assertOk()
            ->assertJsonPath('data.status', 'registration_required')->assertJsonPath('data.email', 'driver@gmail.com')
            ->assertJsonMissingPath('data.token')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_matching_email_requires_existing_password_then_links_and_signs_in(): void
    {
        $user = $this->existingUser();
        $this->postJson('/api/v1/auth/google', $this->loginPayload())->assertConflict()->assertJsonPath('error.code', 'google_link_required');
        self::assertNull($user->fresh()->google_subject);
        $this->postJson('/api/v1/auth/google', $this->loginPayload() + ['password' => 'wrong-password'])
            ->assertUnauthorized()->assertJsonPath('error.code', 'invalid_credentials');
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $response = $this->postJson('/api/v1/auth/google', $this->loginPayload() + ['password' => 'ExistingPass1!'])
            ->assertOk()->assertJsonPath('data.user.id', $user->public_id)->assertJsonPath('data.user.email_verified', true);
        self::assertSame('google-subject-one', $user->fresh()->google_subject);
        $this->assertDatabaseCount('memberships', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $this->tenantId, 'action' => 'identity.google.linked']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $this->tenantId, 'action' => 'identity.login.failed']);
        $this->withToken($response->json('data.token'))->getJson('/api/v1/me')->assertOk();
        self::assertArrayNotHasKey('google_subject', $user->toArray());
    }

    public function test_linked_subject_is_stable_when_google_email_changes(): void
    {
        $user = $this->existingUser(true);
        $this->postJson('/api/v1/auth/google', $this->loginPayload($this->token(['email' => 'changed@gmail.com'])))
            ->assertOk()->assertJsonPath('data.user.id', $user->public_id);
        self::assertSame('driver@gmail.com', $user->fresh()->email);
    }

    public function test_google_identity_cannot_replace_an_existing_link_or_join_a_different_tenant(): void
    {
        $user = $this->existingUser(true);
        $this->postJson('/api/v1/auth/google', $this->loginPayload($this->token(['sub' => 'different-google-subject'])) + ['password' => 'ExistingPass1!'])
            ->assertUnauthorized();
        self::assertSame('google-subject-one', $user->fresh()->google_subject);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $otherTenant = (string) Str::ulid();
        DB::table('tenants')->insert(['id' => $otherTenant, 'name' => 'Other tenant', 'slug' => 'other-google-test', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        // The same global provider subject does not grant membership in another tenant.
        config()->set('google_sign_in.tenant_id', $otherTenant);
        $this->postJson('/api/v1/auth/google', array_replace($this->loginPayload(), ['tenant_id' => $otherTenant]))->assertUnauthorized();
        $this->assertDatabaseCount('memberships', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[DataProvider('blockedAccountStates')]
    public function test_google_login_enforces_account_membership_tenant_and_mfa_state(string $state): void
    {
        $user = $this->existingUser(true);
        match ($state) {
            'disabled' => $user->forceFill(['disabled_at' => now()])->save(),
            'inactive' => $user->forceFill(['activated_at' => null])->save(),
            'mfa' => $user->forceFill(['mfa_required' => true])->save(),
            'membership' => DB::table('memberships')->update(['status' => 'suspended']),
            'expired' => DB::table('memberships')->update(['expires_at' => now()->subSecond()]),
            'tenant' => DB::table('tenants')->update(['status' => 'suspended']),
            default => self::fail('Unknown account test scenario.'),
        };
        $this->postJson('/api/v1/auth/google', $this->loginPayload())->assertStatus($state === 'mfa' ? 409 : 401);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /** @return list<array{string}> */
    public static function blockedAccountStates(): array
    {
        return array_map(static fn (string $state): array => [$state], ['disabled', 'inactive', 'mfa', 'membership', 'expired', 'tenant']);
    }

    /** @param array<string, mixed> $claims */
    #[DataProvider('invalidClaims')]
    public function test_invalid_google_claims_are_rejected(array $claims): void
    {
        $this->existingUser(true);
        $this->postJson('/api/v1/auth/google', $this->loginPayload($this->token($claims)))
            ->assertUnauthorized()->assertJsonPath('error.code', 'google_token_invalid');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /** @return list<array{array<string, mixed>}> */
    public static function invalidClaims(): array
    {
        return [
            [['aud' => 'attacker.apps.googleusercontent.com']], [['azp' => 'attacker.apps.googleusercontent.com']],
            [['iss' => 'https://attacker.example']], [['email_verified' => false]], [['email_verified' => 'true']],
            [['sub' => '']], [['email' => 'invalid']], [['exp' => 1]], [['iat' => PHP_INT_MAX]],
        ];
    }

    public function test_token_signature_and_algorithm_are_verified(): void
    {
        $this->existingUser(true);
        $forged = JWT::encode(['sub' => 'google-subject-one'], str_repeat('x', 32), 'HS256', 'google-test-key');
        $this->postJson('/api/v1/auth/google', $this->loginPayload($forged))->assertUnauthorized();
        $parts = explode('.', $this->token());
        $parts[1] = JWT::urlsafeB64Encode(json_encode(['sub' => 'forged'], JSON_THROW_ON_ERROR));
        $this->postJson('/api/v1/auth/google', $this->loginPayload(implode('.', $parts)))->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_disabled_google_configuration_and_wrong_tenant_fail_before_external_requests(): void
    {
        $this->postJson('/api/v1/auth/google', array_replace($this->loginPayload(), ['tenant_id' => (string) Str::ulid()]))->assertNotFound();
        config()->set('google_sign_in.enabled', false);
        $this->postJson('/api/v1/auth/google', $this->loginPayload())->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_rotated_public_key_is_refreshed_and_provider_outage_fails_closed(): void
    {
        $this->existingUser(true);
        $stale = $this->jwks;
        $stale['keys'][0]['kid'] = 'old-key';
        Cache::put('identity.google.jwks', $stale, 3600);
        $this->postJson('/api/v1/auth/google', $this->loginPayload())->assertOk();
        Http::assertSentCount(1);
        Cache::forget('identity.google.jwks');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://www.googleapis.com/oauth2/v3/certs' => Http::response([], 503)]);
        $this->postJson('/api/v1/auth/google', $this->loginPayload())->assertStatus(503)->assertJsonPath('error.code', 'google_sign_in_unavailable');
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    /** @return array<string, mixed> */
    private function registrationPayload(): array
    {
        return $this->loginPayload() + ['first_name' => 'Google', 'last_name' => 'Driver',
            'birth_date' => '1990-01-01', 'plate_pending' => true, 'terms_accepted' => true,
            'email' => 'forged@example.test', 'role' => 'super-admin'];
    }

    public function test_google_signup_uses_verified_identity_and_creates_only_consumer_scope(): void
    {
        $response = $this->postJson('/api/v2/auth/google/register', $this->registrationPayload())->assertCreated()
            ->assertJsonPath('data.user.email', 'driver@gmail.com')->assertJsonPath('data.user.email_verified', true);
        $user = User::query()->sole();
        self::assertSame('google-subject-one', $user->google_subject);
        $this->assertDatabaseCount('memberships', 1);
        $this->assertDatabaseCount('role_assignments', 1);
        $this->assertDatabaseHas('memberships', ['tenant_id' => $this->tenantId, 'organization_id' => $this->organizationId, 'user_id' => $user->id]);
        $this->assertDatabaseCount('driver_vehicles', 1);
        $this->withToken($response->json('data.token'))->getJson('/api/v1/me')->assertOk();
        Notification::assertNotSentTo($user, VerifyEmailNotification::class);
    }

    public function test_google_signup_requires_profile_and_terms_and_cannot_replace_existing_account(): void
    {
        $this->postJson('/api/v2/auth/google/register', array_replace($this->registrationPayload(), ['terms_accepted' => false]))
            ->assertUnprocessable()->assertJsonPath('error.errors.terms_accepted.0', 'The terms accepted field must be accepted.');
        $this->postJson('/api/v2/auth/google/register', array_replace($this->registrationPayload(), ['birth_date' => '2020-01-01']))->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
        $this->existingUser();
        $this->postJson('/api/v2/auth/google/register', $this->registrationPayload())->assertUnprocessable();
        self::assertNull(User::query()->sole()->google_subject);
        $this->assertDatabaseCount('driver_vehicles', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_third_party_google_email_still_needs_local_email_verification(): void
    {
        $payload = array_replace($this->registrationPayload(), ['id_token' => $this->token(['email' => 'driver@example.test'])]);
        $response = $this->postJson('/api/v2/auth/google/register', $payload)->assertCreated()->assertJsonPath('data.user.email_verified', false);
        Notification::assertSentTo(User::query()->sole(), VerifyEmailNotification::class);
        $this->withToken($response->json('data.token'))->getJson('/api/v1/me')->assertForbidden();
    }
}
