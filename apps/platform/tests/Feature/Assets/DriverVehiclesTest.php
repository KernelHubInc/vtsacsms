<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Modules\Assets\Application\DriverVehicles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TenantSecurityTestCase;

final class DriverVehiclesTest extends TenantSecurityTestCase
{
    public function test_vehicle_api_scopes_owner_and_tenant_encrypts_plate_and_preserves_one_default(): void
    {
        $tenant = $this->createTenant('driver-garage');
        $otherTenant = $this->createTenant('other-garage');
        $user = $this->createUser();
        $other = $this->createUser();
        $this->withinTenant($tenant, $user, fn () => $this->createMembership($tenant, $user));
        $this->withinTenant($tenant, $other, fn () => $this->createMembership($tenant, $other));
        $this->withinTenant($otherTenant, $user, fn () => $this->createMembership($otherTenant, $user));
        $login = fn ($actor, $scope): string => (string) $this->postJson('/api/v1/auth/login', [
            'email' => $actor->email, 'password' => 'password', 'tenant_id' => $scope->getKey(), 'device_name' => 'Garage test',
        ])->assertOk()->json('data.token');
        $token = $login($user, $tenant);
        $id = (string) Str::ulid();
        $second = (string) Str::ulid();
        $payload = ['nickname' => 'Test car', 'plate_pending' => false, 'plate_number' => 'abc 1234', 'connector_standards' => ['Type 2'], 'is_default' => false];
        $this->withToken($token)->putJson('/api/v1/vehicles/'.$id, $payload)->assertOk()
            ->assertJsonPath('data.plate_number', 'ABC 1234')->assertJsonPath('data.is_default', true);
        $this->putJson('/api/v1/vehicles/'.$id, $payload)->assertOk();
        $this->assertDatabaseCount('driver_vehicles', 1);
        $this->assertNotSame('ABC 1234', DB::table('driver_vehicles')->value('plate_number'));
        $this->putJson('/api/v1/vehicles/'.$second, ['nickname' => 'Pending car', 'plate_pending' => true, 'connector_standards' => [], 'is_default' => true])->assertOk();
        $this->getJson('/api/v1/vehicles')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $second);
        $this->deleteJson('/api/v1/vehicles/'.$second)->assertOk();
        $this->getJson('/api/v1/vehicles')->assertJsonPath('data.0.is_default', true);
        $this->flushHeaders();
        $otherToken = $login($other, $tenant);
        $this->withToken($otherToken)->getJson('/api/v1/vehicles')->assertOk()->assertJsonCount(0, 'data');
        $this->putJson('/api/v1/vehicles/'.$id, $payload)->assertNotFound();
        $this->deleteJson('/api/v1/vehicles/'.$id)->assertNotFound();
        $this->flushHeaders();
        $tenantToken = $login($user, $otherTenant);
        $this->withToken($tenantToken)->getJson('/api/v1/vehicles')->assertOk()->assertJsonCount(0, 'data');
        $this->putJson('/api/v1/vehicles/'.$id, $payload)->assertConflict();
        $this->assertDatabaseCount('driver_vehicles', 1);
        $this->withinTenant($tenant, $user, fn () => $this->assertSame('ABC 1234', app(DriverVehicles::class)->list($user->public_id)[0]['plate_number']));
    }

    public function test_garage_erasure_is_scoped_and_repeatable(): void
    {
        $tenant = $this->createTenant('erase-garage');
        $user = $this->createUser();
        $other = $this->createUser();
        $this->withinTenant($tenant, $user, function () use ($user, $other): void {
            $service = app(DriverVehicles::class);
            $data = ['nickname' => 'Synthetic', 'plate_pending' => true];
            $service->save($user->public_id, (string) Str::ulid(), $data);
            $service->save($other->public_id, (string) Str::ulid(), $data);
            $service->eraseForSubject($user->public_id);
            $service->eraseForSubject($user->public_id);
            $this->assertSame([], $service->list($user->public_id));
            $this->assertCount(1, $service->list($other->public_id));
        });
    }

    public function test_vehicle_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/vehicles')->assertUnauthorized();
        $this->putJson('/api/v1/vehicles/'.Str::ulid(), [])->assertUnauthorized();
    }
}
