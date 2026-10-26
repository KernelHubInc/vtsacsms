<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Modules\Notifications\Domain\Models\NotificationDevice;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Tests\Support\TenantSecurityTestCase;

final class NotificationDeviceTest extends TenantSecurityTestCase
{
    public function test_registration_is_authenticated_validated_and_idempotent(): void
    {
        $tenant = $this->createTenant('push-registry');
        $user = $this->createUser();
        $this->withinTenant($tenant, $user, fn () => $this->createMembership($tenant, $user));
        $token = $this->login($user, $tenant);
        $url = '/api/v1/mobile/notification-device';
        $body = ['platform' => 'android', 'installation_id' => str_repeat('a', 40)];

        $this->putJson($url, $body)->assertUnauthorized();
        $this->withToken($token)->putJson($url, ['platform' => 'ios', 'installation_id' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors(['platform', 'installation_id']);
        $this->putJson($url, $body)->assertNoContent();
        $this->putJson($url, $body)->assertNoContent();

        $this->assertSame(1, NotificationDevice::query()->count());
        $device = NotificationDevice::query()->firstOrFail();
        $this->assertSame((string) $tenant->getKey(), $device->tenant_id);
        $this->assertSame($user->getKey(), $device->user_id);
        $this->assertSame($body['installation_id'], $device->installation_ciphertext);
        $this->assertDatabaseMissing('notification_devices', ['installation_ciphertext' => $body['installation_id']]);
        $this->deleteJson($url)->assertNoContent();
        $this->assertDatabaseCount('notification_devices', 0);
    }

    public function test_token_is_transferred_when_the_same_installation_changes_tenant(): void
    {
        $first = $this->createTenant('push-first');
        $second = $this->createTenant('push-second');
        $user = $this->createUser();
        $this->withinTenant($first, $user, fn () => $this->createMembership($first, $user));
        $this->withinTenant($second, $user, fn () => $this->createMembership($second, $user));
        $body = ['platform' => 'android', 'installation_id' => str_repeat('b', 40)];

        $this->withToken($this->login($user, $first))
            ->putJson('/api/v1/mobile/notification-device', $body)->assertNoContent();
        $this->withToken($this->login($user, $second))
            ->putJson('/api/v1/mobile/notification-device', $body)->assertNoContent();

        $this->assertDatabaseCount('notification_devices', 1);
        $this->assertSame((string) $second->getKey(), NotificationDevice::query()->firstOrFail()->tenant_id);
    }

    public function test_sign_out_removes_the_device_registration(): void
    {
        $tenant = $this->createTenant('push-signout');
        $user = $this->createUser();
        $this->withinTenant($tenant, $user, fn () => $this->createMembership($tenant, $user));
        $token = $this->login($user, $tenant);

        $this->withToken($token)->putJson('/api/v1/mobile/notification-device', [
            'platform' => 'android',
            'installation_id' => str_repeat('c', 40),
        ])->assertNoContent();
        $this->assertDatabaseCount('notification_devices', 1);

        $this->postJson('/api/v1/auth/logout')->assertSuccessful();
        $this->assertDatabaseCount('notification_devices', 0);
    }

    private function login(User $user, Tenant $tenant): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'tenant_id' => $tenant->getKey(),
            'device_name' => 'Push test',
        ])->assertOk()->json('data.token');
    }
}
