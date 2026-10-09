<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Filament\Operator\Resources\ChargingStations\Pages\CreateChargingStation;
use App\Filament\Operator\Resources\ChargingStations\Pages\EditChargingStation;
use App\Models\User;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Assets\Domain\Models\OcppVersion;
use App\Modules\Identity\Application\ChargerCredentials;
use App\Modules\Identity\Application\ImportChargerCredentials;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\TenantSecurityTestCase;

final class ChargerEnrollmentTest extends TenantSecurityTestCase
{
    private const PASSWORD = 'synthetic-ocpp-password-123';

    public function test_station_api_provisions_and_rotates_credentials_without_returning_them(): void
    {
        [$tenant, $user, $station] = $this->fixture();
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'password', 'tenant_id' => $tenant->getKey(), 'device_name' => 'Enrollment tests',
        ])->assertOk()->json('data.token');
        $response = $this->withToken($token)->postJson('/api/v1/stations', [
            'site_id' => $station->site_id, 'name' => 'API enrolled station', 'charge_point_identity' => 'API-CP',
            'serial_number' => 'API-SN', 'qr_identifier' => 'API-QR', 'lifecycle_status' => 'active',
            'ocpp_version_id' => $station->ocpp_version_id, 'ocpp_password' => self::PASSWORD,
        ])->assertCreated()->assertJsonMissingPath('data.ocpp_password');
        $id = $response->json('data.id');
        $this->authenticate('API-CP')->assertOk()->assertJsonPath('data.charger_id', $id);
        $this->withToken($token)->patchJson('/api/v1/stations/'.$id, ['ocpp_password' => 'synthetic-rotated-password'])->assertOk();
        $this->authenticate('API-CP')->assertForbidden();
        $this->authenticate('API-CP', 'synthetic-rotated-password')->assertOk();
    }

    public function test_admin_creation_enrolls_without_gateway_registry_and_edit_rotates_without_leaking_secrets(): void
    {
        [$tenant, $user, $station] = $this->fixture();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $created = $this->withinTenant($tenant, $user, function () use ($station) {
            Livewire::test(CreateChargingStation::class)->fillForm([
                'site_id' => $station->site_id, 'name' => 'Dynamic station',
                'charge_point_identity' => 'DYNAMIC-NEW', 'serial_number' => 'DYNAMIC-SN',
                'qr_identifier' => 'DYNAMIC-QR', 'lifecycle_status' => 'active', 'is_public' => false,
                'ocpp_version_id' => $station->ocpp_version_id, 'ocpp_password' => self::PASSWORD,
            ])->call('create')->assertHasNoFormErrors();

            return ChargingStation::query()->where('charge_point_identity', 'DYNAMIC-NEW')->firstOrFail();
        });
        $this->authenticate('DYNAMIC-NEW')->assertOk()->assertJsonPath('data.charger_id', $created->getKey());
        $oldHash = DB::table('identity_charger_credentials')->where('charging_station_id', $created->getKey())->value('password_hash');
        $this->assertTrue(Hash::check(self::PASSWORD, $oldHash));
        $this->withinTenant($tenant, $user, function () use ($created): void {
            Livewire::test(EditChargingStation::class, ['record' => $created->getKey()])
                ->assertFormSet(['ocpp_password' => null])->fillForm(['name' => 'Renamed'])
                ->call('save')->assertHasNoFormErrors();
        });
        $this->assertSame($oldHash, DB::table('identity_charger_credentials')->where('charging_station_id', $created->getKey())->value('password_hash'));
        $this->withinTenant($tenant, $user, function () use ($created): void {
            Livewire::test(EditChargingStation::class, ['record' => $created->getKey()])
                ->fillForm(['ocpp_password' => 'synthetic-rotated-password'])
                ->call('save')->assertHasNoFormErrors()->assertFormSet(['ocpp_password' => null]);
        });
        $this->authenticate('DYNAMIC-NEW')->assertForbidden();
        $this->authenticate('DYNAMIC-NEW', 'synthetic-rotated-password')->assertOk();
        $audit = json_encode(DB::table('audit_events')->get(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::PASSWORD, $audit);
        $this->assertStringNotContainsString($oldHash, $audit);
    }

    public function test_authentication_enforces_password_protocol_asset_and_tenant_lifecycle(): void
    {
        [$tenant, $user, $station] = $this->fixture();
        $this->withinTenant($tenant, $user, fn () => app(ChargerCredentials::class)->setPassword($user, $station->getKey(), self::PASSWORD));
        $this->authenticate($station->charge_point_identity)->assertOk()->assertJsonMissingPath('data.password_hash');
        $this->authenticate($station->charge_point_identity, 'wrong')->assertForbidden();
        $this->authenticate('UNKNOWN')->assertForbidden();
        $this->withToken('synthetic-gateway-token')->postJson('/api/internal/v1/ocpp/authenticate', [
            'identity' => $station->charge_point_identity, 'password' => self::PASSWORD, 'protocol' => 'ocpp2.0.1',
        ])->assertForbidden();
        $this->withinTenant($tenant, $user, fn () => $station->update(['lifecycle_status' => 'retired']));
        $this->authenticate($station->charge_point_identity)->assertForbidden();
        $this->withinTenant($tenant, $user, fn () => $station->update(['lifecycle_status' => 'active']));
        $tenant->update(['status' => 'suspended']);
        $this->authenticate($station->charge_point_identity)->assertForbidden();
    }

    public function test_service_token_is_required_and_tenant_cannot_provision_another_tenants_station(): void
    {
        [$tenant, $user, $station] = $this->fixture();
        $this->postJson('/api/internal/v1/ocpp/authenticate', [])->assertForbidden();
        $this->withToken('wrong')->postJson('/api/internal/v1/ocpp/authenticate', [])->assertForbidden();
        $other = $this->createTenant('other-enrollment');
        $this->expectException(ModelNotFoundException::class);
        $this->withinTenant($other, $user, fn () => app(ChargerCredentials::class)->setPassword($user, $station->getKey(), self::PASSWORD));
    }

    public function test_import_is_idempotent_and_cannot_overwrite_a_rotated_credential(): void
    {
        [$tenant, $user, $station] = $this->fixture();
        $registry = [$station->charge_point_identity => [
            'tenant_id' => $tenant->getKey(), 'charger_id' => $station->getKey(), 'enabled' => true,
            'basic_password_hash' => Hash::make(self::PASSWORD),
        ]];
        $import = app(ImportChargerCredentials::class);
        $this->assertSame(1, $import->import($registry));
        $this->assertSame(0, $import->import($registry));
        $this->withinTenant($tenant, $user, fn () => app(ChargerCredentials::class)->setPassword($user, $station->getKey(), 'synthetic-rotated-password'));
        $this->assertSame(0, $import->import($registry));
        $this->authenticate($station->charge_point_identity)->assertForbidden();
        $this->authenticate($station->charge_point_identity, 'synthetic-rotated-password')->assertOk();
    }

    /** @return TestResponse<JsonResponse> */
    private function authenticate(string $identity, string $password = self::PASSWORD): TestResponse
    {
        return $this->withToken('synthetic-gateway-token')->postJson('/api/internal/v1/ocpp/authenticate', [
            'identity' => $identity, 'password' => $password, 'protocol' => 'ocpp1.6',
        ]);
    }

    /** @return array{Tenant, User, ChargingStation} */
    private function fixture(): array
    {
        config(['services.ocpp_gateway.token' => 'synthetic-gateway-token']);
        $tenant = $this->createTenant('enrollment');
        $user = $this->createUser();
        $station = $this->withinTenant($tenant, $user, function () use ($tenant, $user) {
            $role = $this->createRole($tenant, 'charger-manager', [PermissionKey::AssetManage, PermissionKey::AssetView, PermissionKey::LocationView, PermissionKey::PlatformPanelAccess]);
            $this->assignDirectly($tenant, $this->createMembership($tenant, $user), $role);
            $version = OcppVersion::query()->firstOrCreate(['code' => '1.6J'], ['name' => 'OCPP 1.6J']);

            return ChargingStation::factory()->activePublic()->create(['ocpp_version_id' => $version->getKey()]);
        });

        return [$tenant, $user, $station];
    }
}
