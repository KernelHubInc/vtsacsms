<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\CMS\Domain\Models\DriverNotice;
use App\Modules\Identity\Domain\Models\MobileAccessToken;
use App\Modules\Notifications\Application\DriverNoticePush;
use App\Modules\Notifications\Domain\Models\NoticePushDelivery;
use App\Modules\Notifications\Domain\Models\NotificationDevice;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Support\TenantSecurityTestCase;

final class DriverNoticePushTest extends TenantSecurityTestCase
{
    private string $credentials;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->credentials = tempnam(sys_get_temp_dir(), 'push-test-');
        $private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($private === false) {
            throw new \RuntimeException('Test RSA key generation failed; configure OPENSSL_CONF.');
        }
        openssl_pkey_export($private, $pem);
        file_put_contents($this->credentials, json_encode(['type' => 'service_account', 'project_id' => 'test-project',
            'client_email' => 'test@example.test', 'private_key' => $pem], JSON_THROW_ON_ERROR));
        config(['notice_push.enabled' => true, 'notice_push.project_id' => 'test-project', 'notice_push.credentials' => $this->credentials]);
        Http::preventStrayRequests();
        $this->responses();
    }

    protected function tearDown(): void
    {
        unlink($this->credentials);
        parent::tearDown();
    }

    public function test_only_enabled_active_notices_are_sent_once_to_their_own_tenant(): void
    {
        $tenant = $this->createTenant('push-one');
        $other = $this->createTenant('push-other');
        $device = $this->device($tenant);
        $this->device($other);
        $notice = $this->notice($tenant);
        $this->notice($other);
        $this->notice($tenant, ['status' => 'draft']);
        $this->notice($tenant, ['send_push' => false]);
        $this->notice($tenant, ['starts_at' => now()->addHour()]);
        $this->notice($tenant, ['ends_at' => now()->subMinute()]);

        $push = app(DriverNoticePush::class);
        $this->assertSame(1, $push->deliver((string) $tenant->getKey()));
        $this->assertSame(0, $push->deliver((string) $tenant->getKey()));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'messages:send')
            && $request['message']['fid'] === $device->installation_ciphertext
            && $request['message']['data']['notice_id'] === $notice->id);
        $this->assertDatabaseCount('notice_push_deliveries', 1);
        $this->assertDatabaseHas('notice_push_deliveries', ['status' => 'accepted', 'attempts' => 1]);
        $notice->update(['title' => 'Edited title']);
        $this->assertSame(0, $push->deliver((string) $tenant->getKey()));
        $this->assertSame([], $push->report((string) $other->getKey(), $notice->id)['recent']);
    }

    public function test_disabled_sending_does_not_contact_firebase(): void
    {
        config(['notice_push.enabled' => false]);
        $tenant = $this->createTenant('push-disabled');
        $this->device($tenant);
        $this->notice($tenant);
        $this->assertSame(0, app(DriverNoticePush::class)->deliver((string) $tenant->getKey()));
        Http::assertNothingSent();
    }

    public function test_browser_recipient_receives_a_web_payload_and_tenant_safe_link(): void
    {
        config(['app.url' => 'https://staging.evcspowersolutions.com']);
        $tenant = $this->createTenant('push-browser');
        $device = $this->device($tenant);
        $device->update(['platform' => 'web']);
        $notice = $this->notice($tenant);
        $push = app(DriverNoticePush::class);
        $this->assertSame(1, $push->deliver((string) $tenant->getKey()));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'messages:send')
            && $request['message']['fid'] === $device->installation_ciphertext
            && $request['message']['webpush']['fcm_options']['link'] === 'https://staging.evcspowersolutions.com/mobile/#/updates'
            && ! isset($request['message']['android']));
        $this->assertDatabaseHas('notice_push_deliveries', ['notice_id' => $notice->id, 'status' => 'accepted']);
        $this->assertSame(0, $push->deliver((string) $tenant->getKey()));
    }

    public function test_configuration_check_uses_validation_only_and_does_not_create_deliveries(): void
    {
        $this->assertSame(0, Artisan::call('notifications:send-driver-notices', ['--check' => true]));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'messages:send')
            && $request['validate_only'] === true);
        $this->assertDatabaseCount('notice_push_deliveries', 0);
    }

    public function test_delivery_history_expires_only_after_the_notice_retention_window(): void
    {
        $tenant = $this->createTenant('push-retention');
        $this->device($tenant);
        $notice = $this->notice($tenant);
        $push = app(DriverNoticePush::class);
        $push->deliver((string) $tenant->getKey());
        $notice->update(['starts_at' => now()->subDays(100), 'ends_at' => now()->subDays(89)]);
        $push->prune((string) $tenant->getKey());
        $this->assertDatabaseCount('notice_push_deliveries', 1);
        $notice->update(['ends_at' => now()->subDays(91)]);
        config(['notice_push.enabled' => false]);
        $this->assertSame(0, Artisan::call('notifications:send-driver-notices'));
        $this->assertDatabaseCount('notice_push_deliveries', 0);
        $this->assertDatabaseCount('notification_devices', 1);
    }

    public function test_unregistered_installation_is_removed_and_failure_is_redacted(): void
    {
        $this->responses(404, ['error' => ['message' => 'private provider detail', 'details' => [['errorCode' => 'UNREGISTERED']]]]);
        $tenant = $this->createTenant('push-invalid');
        $this->device($tenant);
        $this->notice($tenant);
        app(DriverNoticePush::class)->deliver((string) $tenant->getKey());
        $this->assertDatabaseCount('notification_devices', 0);
        $this->assertDatabaseHas('notice_push_deliveries', ['status' => 'invalid', 'error_code' => 'unregistered']);
        $this->assertStringNotContainsString('private provider', (string) NoticePushDelivery::query()->firstOrFail()->toJson());
    }

    public function test_throttling_retries_later_but_unpublished_notices_are_cancelled(): void
    {
        $this->responses(429);
        $tenant = $this->createTenant('push-retry');
        $this->device($tenant);
        $notice = $this->notice($tenant);
        $push = app(DriverNoticePush::class);
        $push->deliver((string) $tenant->getKey());
        $this->assertDatabaseHas('notice_push_deliveries', ['status' => 'pending', 'attempts' => 1]);
        $this->assertSame(0, $push->deliver((string) $tenant->getKey()));
        $notice->update(['status' => 'draft']);
        $this->travel(2)->minutes();
        $push->deliver((string) $tenant->getKey());
        $this->assertDatabaseHas('notice_push_deliveries', ['status' => 'cancelled']);
    }

    public function test_lost_responses_are_not_retried_and_interrupted_sends_are_marked_unknown(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-access']),
            'fcm.googleapis.com/*' => Http::failedConnection()]);
        $tenant = $this->createTenant('push-timeout');
        $this->device($tenant);
        $this->notice($tenant);
        $push = app(DriverNoticePush::class);
        $push->deliver((string) $tenant->getKey());
        $this->assertDatabaseHas('notice_push_deliveries', ['status' => 'unknown']);
        $this->assertSame(0, $push->deliver((string) $tenant->getKey()));
        NoticePushDelivery::query()->update(['status' => 'sending', 'attempted_at' => now()->subMinutes(11)]);
        $push->deliver((string) $tenant->getKey());
        $this->assertDatabaseHas('notice_push_deliveries', ['status' => 'unknown', 'error_code' => 'worker_interrupted']);
    }

    public function test_revoked_sessions_and_expired_memberships_are_not_recipients(): void
    {
        $tenant = $this->createTenant('push-revoked');
        $device = $this->device($tenant);
        $this->notice($tenant);
        MobileAccessToken::query()->whereKey($device->auth_token_id)->update(['expires_at' => now()->subMinute()]);
        app(DriverNoticePush::class)->deliver((string) $tenant->getKey());
        $this->assertDatabaseCount('notice_push_deliveries', 0);
    }

    public function test_a_future_notice_becomes_eligible_and_retries_are_bounded(): void
    {
        $this->responses(503);
        $tenant = $this->createTenant('push-scheduled');
        $this->device($tenant);
        $this->notice($tenant, ['starts_at' => now()->addMinutes(2)]);
        $push = app(DriverNoticePush::class);
        $this->assertSame(0, $push->deliver((string) $tenant->getKey()));
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->travel(2)->minutes();
            $push->deliver((string) $tenant->getKey());
        }
        $this->assertDatabaseHas('notice_push_deliveries', ['status' => 'failed', 'attempts' => 5]);
    }

    /** @param array<string, mixed> $body */
    private function responses(int $status = 200, array $body = ['name' => 'projects/test-project/messages/test']): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-access']),
            'fcm.googleapis.com/*' => Http::response($body, $status)]);
    }

    private function device(Tenant $tenant): NotificationDevice
    {
        $user = $this->createUser();
        $this->withinTenant($tenant, $user, fn () => $this->createMembership($tenant, $user));
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password',
            'tenant_id' => $tenant->getKey(), 'device_name' => 'Push test'])->assertOk()->json('data.token');
        $this->withToken($token)->putJson('/api/v1/mobile/notification-device', ['platform' => 'android',
            'installation_id' => str_repeat('f', 15).$user->id])->assertNoContent();

        return NotificationDevice::query()->where('user_id', $user->id)->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    private function notice(Tenant $tenant, array $attributes = []): DriverNotice
    {
        return DriverNotice::query()->create([...['tenant_id' => $tenant->getKey(), 'category' => 'maintenance',
            'title' => 'Scheduled maintenance', 'message' => 'Please check Updates.', 'status' => 'published',
            'send_push' => true, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()], ...$attributes]);
    }
}
